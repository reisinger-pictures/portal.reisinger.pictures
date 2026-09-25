<?php

namespace App\Services;

use App\Mail\ContractClosedMail;
use App\Models\Contract;
use App\Models\InvoiceSequence;
use App\Models\InvoiceSnapshot;
use App\Models\Order;
use App\Models\User;
use App\Support\BrandRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class ContractCloseService
{
    public function __construct(
        private ContractPricingService $contractPricingService,
    ) {}

    /**
     * Return the canonical snapshot used by the public response, PDF, and
     * invoice serializer. Legacy mixed placement is normalized for reads.
     *
     * @return array{items: list<array<string, mixed>>, discounts: list<array<string, mixed>>, lines: list<array<string, mixed>>}
     */
    public function normalizedSnapshot(Contract $contract): array
    {
        return $this->contractPricingService->normalizeSnapshot(
            $contract->items,
            $contract->discounts,
        );
    }

    /**
     * Calculate the authoritative contract total in integer cents.
     */
    public function calculateTotal(Contract $contract): int
    {
        $snapshot = $this->normalizedSnapshot($contract);

        return $this->contractPricingService->preflightSnapshot($snapshot)['total'];
    }

    public function close(Contract $contract): void
    {
        $snapshot = $this->normalizedSnapshot($contract);
        $processed = $this->contractPricingService->preflightSnapshot($snapshot);
        $totalGross = $processed['total'];
        $billingDetails = is_array($contract->billing_details) ? $contract->billing_details : [];

        if ($totalGross > 0 && $billingDetails !== []) {
            DB::transaction(function () use ($contract, $billingDetails, $processed, $totalGross) {
                $brand = $contract->brand ?? BrandRegistry::currentOrDefault();

                $userId = $this->resolveBillingUserId(
                    $billingDetails['email'] ?? null,
                    $contract->brand ?? BrandRegistry::currentIdOrNull(),
                );

                $order = Order::create([
                    'user_id' => $userId,
                    'status' => 'invoice_created',
                    'total_amount' => $totalGross,
                    'is_quote_request' => false,
                    'brand' => $brand,
                ]);

                $invoiceNumber = InvoiceSequence::getNextInvoiceNumber('P-');

                InvoiceSnapshot::create([
                    'invoice_number' => $invoiceNumber,
                    'order_id' => $order->id,
                    'brand' => $brand,
                    'customer_details' => array_merge(
                        $billingDetails,
                        [
                            // Invoices consume one ordered line array. Keep the
                            // separate key as well for consumers that need to
                            // inspect the canonical contract partition.
                            'items' => $processed['items'],
                            'discounts' => array_values(array_filter(
                                $processed['items'],
                                static fn (array $line): bool => $line['type'] !== ContractPricingService::ITEM_TYPE,
                            )),
                            'terms' => $contract->terms_html ?? '',
                        ],
                    ),
                    'total_net' => $totalGross,
                    'total_gross' => $totalGross,
                    'tax_rate' => null,
                ]);

            });
        }

        $recipients = $contract->relationLoaded('signers')
            ? $contract->signers->pluck('email')->unique()->toArray()
            : $contract->signers()->pluck('email')->unique()->toArray();

        if (! empty($billingDetails['email'])) {
            $recipients[] = $billingDetails['email'];
        }

        $recipients = array_values(array_unique(array_filter($recipients)));

        foreach ($recipients as $recipient) {
            Mail::to($recipient)->queue(new ContractClosedMail($contract));
        }
    }

    private function resolveBillingUserId(mixed $email, mixed $brand): ?string
    {
        $email = is_string($email) ? trim($email) : null;
        $brandId = BrandRegistry::normalizeId($brand);

        if ($email === null || $email === '' || $brandId === null) {
            return null;
        }

        return User::query()
            ->where('email', $email)
            ->where('brand', $brandId)
            ->value('id');
    }
}
