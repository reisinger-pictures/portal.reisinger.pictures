<?php

namespace App\Services;

use App\Exceptions\ContractCloseConflictException;
use App\Mail\ContractClosedMail;
use App\Models\Contract;
use App\Models\InvoiceSequence;
use App\Models\InvoiceSnapshot;
use App\Models\Order;
use App\Models\User;
use App\Support\BrandRegistry;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\DeadlockException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

class ContractCloseService
{
    public const RESULT_CLOSED = 'closed';

    public const RESULT_ALREADY_CLOSED = 'already_closed';

    public const RESULT_NOT_ACTIVE = 'not_active';

    public const RESULT_CONFLICT = 'conflict';

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

    /**
     * Close a contract and perform all accounting/mail side effects in one
     * transaction. The contract row is the durable close claim: only the
     * active -> closed conditional transition may create the order, invoice,
     * or queued closure mail. A retry observes the committed closed state and
     * returns without repeating any side effect.
     *
     * @return array{status: 'closed'|'already_closed'|'not_active'|'conflict', contract: Contract}
     */
    public function close(Contract $contract): array
    {
        try {
            return DB::transaction(function () use ($contract): array {
                $lockedContract = Contract::query()
                    ->whereKey($contract->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedContract->status === 'closed') {
                    $lockedContract->load('signers');

                    return [
                        'status' => self::RESULT_ALREADY_CLOSED,
                        'contract' => $lockedContract,
                    ];
                }

                if ($lockedContract->status !== 'active') {
                    $lockedContract->load('signers');

                    return [
                        'status' => self::RESULT_NOT_ACTIVE,
                        'contract' => $lockedContract,
                    ];
                }

                // Preflight the locked snapshot before changing lifecycle state.
                // Any pricing failure therefore leaves the contract active and
                // creates no accounting or mail side effect.
                $snapshot = $this->normalizedSnapshot($lockedContract);
                $processed = $this->contractPricingService->preflightSnapshot($snapshot);
                $totalGross = $processed['total'];
                $billingDetails = is_array($lockedContract->billing_details)
                    ? $lockedContract->billing_details
                    : [];

                $closedAt = now();
                $affected = Contract::query()
                    ->whereKey($lockedContract->getKey())
                    ->where('status', 'active')
                    ->update([
                        'status' => 'closed',
                        'updated_at' => $closedAt,
                    ]);

                if ($affected !== 1) {
                    $current = Contract::query()
                        ->whereKey($lockedContract->getKey())
                        ->first();

                    if ($current?->status === 'closed') {
                        $current->load('signers');

                        return [
                            'status' => self::RESULT_ALREADY_CLOSED,
                            'contract' => $current,
                        ];
                    }

                    $current?->load('signers');

                    return [
                        'status' => self::RESULT_CONFLICT,
                        'contract' => $current ?? $lockedContract,
                    ];
                }

                // The conditional update is the lifecycle claim. Keep the
                // in-memory model aligned for the queued mailable and response.
                $lockedContract->status = 'closed';
                $lockedContract->updated_at = $closedAt;

                // The documented auto-invoicing trigger is `total_gross > 0`
                // alone. A priced contract must never close without a
                // receivable, so the billing-recipient preconditions are not
                // allowed to silently skip the accounting rows: a missing
                // recipient yields an order/invoice without a customer e-mail
                // rather than no order at all.
                if ($totalGross > 0) {
                    $this->createInvoice($lockedContract, $billingDetails, $processed, $totalGross);
                }

                $this->queueClosedMail($lockedContract, $billingDetails);
                $lockedContract->load('signers');

                return [
                    'status' => self::RESULT_CLOSED,
                    'contract' => $lockedContract,
                ];
            }, 3);
        } catch (HttpResponseException $exception) {
            // InvoiceSequence uses a 503 HttpResponseException for a lock
            // conflict. Expose the same generic, retryable contract response
            // instead of leaking an accounting implementation detail.
            if ($exception->getResponse()->getStatusCode() >= 500) {
                throw new ContractCloseConflictException;
            }

            throw $exception;
        } catch (Throwable $exception) {
            if ($this->isRetryableDatabaseConflict($exception)) {
                throw new ContractCloseConflictException;
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $billingDetails
     * @param  array<string, mixed>  $processed
     */
    private function createInvoice(
        Contract $contract,
        array $billingDetails,
        array $processed,
        int $totalGross,
    ): void {
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
                    // The existing JSON snapshot carries the contract identity;
                    // no new order/invoice column or migration is required.
                    InvoiceSnapshot::CONTRACT_ID_KEY => (string) $contract->getKey(),
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
    }

    /**
     * @param  array<string, mixed>  $billingDetails
     */
    private function queueClosedMail(Contract $contract, array $billingDetails): void
    {
        $recipients = $contract->signers()
            ->pluck('email')
            ->unique()
            ->all();

        if (! empty($billingDetails['email'])) {
            $recipients[] = $billingDetails['email'];
        }

        $recipients = array_values(array_unique(array_filter($recipients)));

        // Dispatch only after the close transaction commits. The database queue
        // connection also sets after_commit, but the sync/local driver would
        // otherwise send inline and survive a rolled-back attempt (then send a
        // second time on the retry).
        DB::afterCommit(function () use ($recipients, $contract): void {
            foreach ($recipients as $recipient) {
                Mail::to($recipient)->queue(new ContractClosedMail($contract));
            }
        });
    }

    private function isRetryableDatabaseConflict(Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return $exception instanceof LockTimeoutException
            || $exception instanceof DeadlockException
            || str_contains($message, 'deadlock')
            || str_contains($message, 'lock wait timeout')
            || str_contains($message, 'database is locked')
            || str_contains($message, 'database table is locked')
            || str_contains($message, 'try restarting transaction');
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
