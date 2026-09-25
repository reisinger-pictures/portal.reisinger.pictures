<?php

namespace App\Services;

use App\Mail\InvoiceMail;
use App\Models\InvoiceSnapshot;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Claims and queues the invoice message for one durable order/snapshot pair.
 *
 * The old implementation wrote a short-lived cache marker after queuing the
 * mailable. That left an unavoidable duplicate window after a crash, cache
 * eviction, or marker-write failure. The existing invoice_snapshots JSON
 * column now carries a reserved, non-expiring operational claim. The claim and
 * the database-queue insert happen in the same transaction, so a committed
 * claim has a durable enqueue and a failed claim/queue attempt leaves no
 * marker behind for a retry to trip over.
 *
 * This is deliberately an **at-most-once durable enqueue** guarantee, not an
 * exactly-once SMTP-delivery guarantee. A queue worker may retry SMTP after a
 * transport failure; after the retry budget is exhausted the job remains in
 * failed_jobs while this marker prevents an automatic second enqueue. Delivery
 * recovery therefore requires an operator decision and must account for the
 * possibility that SMTP accepted a message before the worker lost its response.
 */
class InvoiceMailDispatcher
{
    /**
     * Durably enqueue the invoice at most once for the order.
     *
     * The return value describes the enqueue claim, not SMTP delivery: a later
     * worker retry or an operator recovery can still produce another SMTP
     * attempt, and a permanently failed worker job is recorded in failed_jobs.
     *
     * @return bool true when this call claimed and durably enqueued a new job
     */
    public function queueOnce(Order $order, ?User $user = null, ?string $recipient = null): bool
    {
        $queueConnection = config('queue.connections.database.connection');
        $databaseConnection = config('database.default');
        if (app()->environment('production')
            && (config('queue.default') !== 'database'
                || ($queueConnection !== null && $queueConnection !== $databaseConnection))) {
            throw new RuntimeException('Invoice mail requires the transactional database queue in production.');
        }

        $snapshot = InvoiceSnapshot::query()
            ->where('order_id', $order->getKey())
            ->first();
        if (! $snapshot instanceof InvoiceSnapshot) {
            throw new RuntimeException('Cannot queue invoice mail without an invoice snapshot.');
        }

        if ($snapshot->invoiceMailDispatchClaimed()) {
            return false;
        }

        $details = $snapshot->customer_details;
        $snapshotRecipient = is_array($details) && is_string($details['email'] ?? null)
            ? trim($details['email'])
            : '';
        $explicitRecipient = trim((string) ($recipient ?? ''));
        $fallbackRecipient = $snapshotRecipient !== ''
            ? $snapshotRecipient
            : (string) ($user?->email ?? $order->user?->email);
        $resolvedRecipient = $explicitRecipient !== '' ? $explicitRecipient : trim($fallbackRecipient);
        if ($resolvedRecipient === '') {
            throw new RuntimeException('Cannot queue invoice mail without a recipient.');
        }

        return (bool) DB::transaction(function () use ($order, $resolvedRecipient, $snapshot): bool {
            $lockedSnapshot = InvoiceSnapshot::query()
                ->whereKey($snapshot->getKey())
                ->lockForUpdate()
                ->first();
            if (! $lockedSnapshot instanceof InvoiceSnapshot) {
                throw new RuntimeException('The invoice snapshot disappeared before mail dispatch.');
            }

            if ($lockedSnapshot->invoiceMailDispatchClaimed()) {
                return false;
            }

            // Claim first, then enqueue. If the queue call or the transaction
            // commit fails, the claim is rolled back with the queue insert and
            // a later checkout/webhook retry can safely try again. Once the
            // commit succeeds, SMTP delivery is a separate retryable worker
            // concern and is not exactly-once.
            $this->claimMarker($lockedSnapshot);
            Mail::to($resolvedRecipient)->queue(new InvoiceMail($order, $lockedSnapshot));

            return true;
        });
    }

    /**
     * Seam for deterministic failure-boundary tests and queue adapters.
     */
    protected function claimMarker(InvoiceSnapshot $snapshot): void
    {
        $snapshot->claimInvoiceMailDispatch();
    }
}
