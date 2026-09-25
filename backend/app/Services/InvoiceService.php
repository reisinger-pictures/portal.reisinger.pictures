<?php

namespace App\Services;

use App\Enums\Brand;
use App\Models\InvoiceSequence;
use App\Models\InvoiceSnapshot;
use App\Models\Order;
use App\Models\Org;
use App\Support\PersistedMoney;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    public function countOpenDeliveryNotesForOrg(Org $org): int
    {
        $currentOrgUserIds = $this->currentOrgUserIds($org);
        $candidateOrders = $this->openDeliveryNoteCandidates();

        return $this->ordersBelongingToOrg($candidateOrders, $org, $currentOrgUserIds)->count();
    }

    public function generateForOrg(Org $org, $initiator = null)
    {
        try {
            return DB::transaction(function () use ($org, $initiator) {
                // Current membership is a compatibility fallback for legacy
                // snapshots only. New purchase snapshots carry their organization
                // identity in InvoiceSnapshot::PURCHASE_ORG_ID_KEY.
                $currentOrgUserIds = $this->currentOrgUserIds($org);

                try {
                    $candidateOrders = $this->openDeliveryNoteCandidates(true);
                } catch (QueryException $e) {
                    if (str_contains($e->getMessage(), 'Deadlock') || str_contains($e->getMessage(), 'lock wait timeout')) {
                        return ['success' => false, 'error' => 'Server ist derzeit überlastet. Bitte versuche es in einigen Sekunden erneut.'];
                    }
                    throw $e;
                }

                // The SQL candidate set is deliberately broad enough to include a
                // historical order whose purchaser has since moved. The PHP
                // predicate makes an explicit snapshot authoritative and only
                // falls back to current membership when the key is absent.
                $openOrders = $this->ordersBelongingToOrg(
                    $candidateOrders,
                    $org,
                    $currentOrgUserIds
                );

                if ($openOrders->isEmpty()) {
                    return ['success' => false, 'error' => 'Keine offenen Lieferscheine für diese Organisation gefunden.'];
                }

                $totalNet = 0;
                $totalGross = 0;
                $allItems = [];
                $usedTerms = [];

                foreach ($openOrders as $order) {
                    $snap = $order->invoiceSnapshot;
                    if ($snap) {
                        $totalNet += $snap->total_net;
                        $totalGross += $snap->total_gross;

                        $details = is_array($snap->customer_details) ? $snap->customer_details : [];
                        $items = $details['items'] ?? [];
                        if (! is_array($items)) {
                            $items = [];
                        }
                        foreach ($items as $item) {
                            if (! is_array($item)) {
                                continue;
                            }
                            $item['ordered_by'] = $order->user?->name ?? 'Unbekannt';
                            $item['original_order_id'] = $order->id;
                            $allItems[] = $item;
                        }

                        $terms = $details['terms'] ?? [];
                        if (is_array($terms)) {
                            foreach ($terms as $k => $v) {
                                $usedTerms[$k] = $v;
                            }
                        }
                    }
                    // Alten Lieferschein archivieren
                    $order->update(['status' => 'archived_in_collective']);
                }

                PersistedMoney::assertFitsCents($totalNet, 'invoice_snapshots.total_net');
                PersistedMoney::assertFitsCents($totalGross, 'invoice_snapshots.total_gross');

                $firstOrder = $openOrders->first();
                $firstSnapshot = $firstOrder?->invoiceSnapshot;
                $fallbackDetails = $firstSnapshot?->customer_details;
                if (! is_array($fallbackDetails)) {
                    $fallbackDetails = [];
                }
                $fallbackUser = $org->users()->first();

                $collectiveUserId = $initiator?->id
                    ?? ($fallbackUser?->id ?? $firstOrder?->user_id);
                $billingStreet = $initiator?->billing_street
                    ?? ($fallbackUser?->billing_street ?? ($fallbackDetails['street'] ?? 'Firmenadresse'));
                $billingZip = $initiator?->billing_zip
                    ?? ($fallbackUser?->billing_zip ?? ($fallbackDetails['zip'] ?? '0000'));
                $billingCity = $initiator?->billing_city
                    ?? ($fallbackUser?->billing_city ?? ($fallbackDetails['city'] ?? 'Unbekannt'));
                $collectiveEmail = $initiator?->email
                    ?? ($fallbackUser?->email ?? ($fallbackDetails['email'] ?? config('mail.from.address')));

                $brand = $org->brand ?? $firstOrder?->brand ?? Brand::B2B;
                $brand = $brand instanceof Brand ? $brand->value : $brand;

                $collectiveOrder = Order::create([
                    'user_id' => $collectiveUserId,
                    'status' => 'invoice_created',
                    'brand' => $brand,
                    'total_amount' => $totalGross,
                ]);

                $invoiceNumber = InvoiceSequence::getNextInvoiceNumber('P-');

                $snapshot = InvoiceSnapshot::create([
                    'order_id' => $collectiveOrder->id,
                    'invoice_number' => $invoiceNumber,
                    'brand' => $brand,
                    'customer_details' => [
                        InvoiceSnapshot::PURCHASE_ORG_ID_KEY => (string) $org->getKey(),
                        'name' => $org->name,
                        'email' => $collectiveEmail,
                        'company' => $org->name,
                        'street' => $billingStreet,
                        'zip' => $billingZip,
                        'city' => $billingCity,
                        'country' => 'Österreich',
                        'items' => $allItems,
                        'terms' => $usedTerms,
                        'is_collective' => true,
                    ],
                    'total_net' => $totalNet,
                    'total_gross' => $totalGross,
                    'tax_rate' => null,
                ]);

                $mailTo = is_string($collectiveEmail) && trim($collectiveEmail) !== ''
                    ? $collectiveEmail
                    : config('mail.from.address');
                app(InvoiceMailDispatcher::class)->queueOnce($collectiveOrder, null, $mailTo);

                return [
                    'success' => true,
                    'invoice_number' => $invoiceNumber,
                    'processed_orders' => $openOrders->count(),
                ];
            });
        } catch (\InvalidArgumentException $exception) {
            return [
                'success' => false,
                'error' => $exception->getMessage(),
                'status' => 422,
            ];
        }
    }

    private function currentOrgUserIds(Org $org): array
    {
        return $org->users()
            ->pluck('users.id')
            ->map(fn ($id): string => (string) $id)
            ->all();
    }

    private function openDeliveryNoteCandidates(bool $lockForUpdate = false): Collection
    {
        // Do not apply a JSON-path predicate in SQL. SQLite evaluates
        // json_extract() even for rows that would not belong to the target org
        // and aborts the whole candidate query when one legacy/corrupt JSON
        // document is malformed. Fetch all delivery notes that have a
        // snapshot and apply the attribution predicate in PHP instead.
        $query = Order::query()
            ->where('status', 'delivery_note')
            ->whereHas('invoiceSnapshot')
            ->with(['invoiceSnapshot', 'user']);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    private function ordersBelongingToOrg(
        Collection $candidateOrders,
        Org $org,
        array $currentOrgUserIds
    ): Collection {
        return $candidateOrders
            ->filter(fn (Order $order): bool => $this->orderBelongsToOrg(
                $order,
                $org,
                $currentOrgUserIds
            ))
            ->values();
    }

    private function orderBelongsToOrg(Order $order, Org $org, array $currentOrgUserIds): bool
    {
        $snapshot = $order->invoiceSnapshot;
        $targetOrgId = (string) $org->getKey();

        if ($snapshot === null) {
            // A delivery note without its accounting snapshot has no
            // trustworthy purchase-time attribution. Do not reinterpret it as
            // a legacy row merely because the current user happens to belong
            // to this organization.
            return false;
        }

        try {
            $details = $snapshot->customer_details;
        } catch (\Throwable) {
            return false;
        }

        // A snapshot with a malformed JSON root is invalid attribution, not a
        // legacy row that may silently fall back to membership.
        if (! is_array($details)) {
            return false;
        }

        if (! array_key_exists(InvoiceSnapshot::PURCHASE_ORG_ID_KEY, $details)) {
            // Missing the key is the one intentional legacy compatibility
            // case.
            return in_array((string) $order->user_id, $currentOrgUserIds, true);
        }

        // A present value is authoritative. Only the exact, non-empty string
        // written by checkout is accepted; null, empty, whitespace-wrapped,
        // and malformed values fail closed for every organization.
        $purchaseOrgId = $snapshot->purchaseOrgId();

        return $purchaseOrgId !== null && $purchaseOrgId === $targetOrgId;
    }
}
