<?php

namespace App\Services;

use App\Mail\CustomMail;
use App\Models\InvoiceSnapshot;
use App\Models\Order;
use App\Support\BrandRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Claims and queues the time-critical chargeback/dispute notification for one
 * order.
 *
 * The order status transition is a lifecycle guard, not a mail idempotency
 * key: once an order is `disputed` a Stripe retry can no longer use the
 * transition to decide whether the alert was sent. This dispatcher mirrors
 * {@see InvoiceMailDispatcher::queueOnce()}: a persisted claim marker plus a
 * queue insert inside one transaction, so a failed enqueue is retryable and a
 * successful one is at-most-once. This is deliberately not an exactly-once
 * SMTP guarantee.
 */
class DisputeMailDispatcher
{
    /**
     * Queues the dispute notification at most once for the order.
     *
     * @return bool true when this call claimed and enqueued a new job
     */
    public function queueOnce(Order $order): bool
    {
        $this->assertTransactionalQueueIsUsable();

        $snapshot = InvoiceSnapshot::query()
            ->where('order_id', $order->getKey())
            ->first();

        if (! $snapshot instanceof InvoiceSnapshot) {
            // Legacy/quote orders may not have an invoice snapshot, so there is
            // no durable claim surface for the marker below. The alert is
            // time-critical, so it is sent directly rather than dropped, and
            // the missing durability is recorded explicitly instead of being
            // invisible. The webhook event claim still deduplicates an
            // identical replay at the ingress layer.
            Log::warning('dispute_mail.snapshot_missing_dispatch_not_durable', [
                'order_id' => $order->getKey(),
                'stripe_payment_intent_id' => $order->stripe_payment_intent_id,
            ]);

            Mail::to($this->recipient())->queue($this->mailable($order));

            return true;
        }

        return (bool) DB::transaction(function () use ($order, $snapshot): bool {
            $lockedSnapshot = InvoiceSnapshot::query()
                ->whereKey($snapshot->getKey())
                ->lockForUpdate()
                ->first();
            if (! $lockedSnapshot instanceof InvoiceSnapshot) {
                throw new RuntimeException('The invoice snapshot disappeared before dispute mail dispatch.');
            }

            if ($lockedSnapshot->disputeMailDispatchClaimed()) {
                return false;
            }

            // Claim first, then enqueue. A queue failure rolls the claim back
            // with the queue insert, so the retry can attempt delivery again.
            $this->claimMarker($lockedSnapshot);
            Mail::to($this->recipient())->queue($this->mailable($order));

            return true;
        });
    }

    /**
     * The claim marker and the jobs INSERT only share a commit boundary when
     * the queue writes to the application database inside the same
     * transaction. An inline `sync` driver or a separate queue connection would
     * let a rolled-back attempt still deliver the mail and a retry deliver a
     * second one. This mirrors InvoiceMailDispatcher: enforced in every
     * non-local environment, not only in production. Local/test keep the
     * inline driver for developer ergonomics.
     */
    private function assertTransactionalQueueIsUsable(): void
    {
        if (app()->environment(['local', 'testing'])) {
            return;
        }

        $queueConnection = config('queue.connections.database.connection');
        $databaseConnection = config('database.default');

        $usable = config('queue.default') === 'database'
            && config('queue.connections.database.driver') === 'database'
            && is_string($queueConnection)
            && $queueConnection !== ''
            && $queueConnection === $databaseConnection
            // The dispatcher relies on transaction membership, which
            // after_commit would move the INSERT out of the transaction and
            // leave a committed claim with no job.
            && ! config('queue.connections.database.after_commit');

        if (! $usable) {
            throw new RuntimeException(
                app()->environment('production')
                    ? 'Dispute mail requires the transactional database queue in production.'
                    : 'Dispute mail requires the transactional database queue outside local test environments.',
            );
        }
    }

    /**
     * Seam for deterministic failure-boundary tests.
     */
    protected function claimMarker(InvoiceSnapshot $snapshot): void
    {
        $snapshot->claimDisputeMailDispatch();
    }

    protected function recipient(): string
    {
        return BrandRegistry::configOrDefault()->accountingEmail ?? 'accounting@reisinger.pictures';
    }

    protected function mailable(Order $order): CustomMail
    {
        return new CustomMail(
            'Stripe Dispute eröffnet',
            "Für die Bestellung {$order->id} wurde ein Dispute (Rückbuchung) eröffnet. Der Download-Zugriff für den Kunden wurde automatisch gesperrt.",
        );
    }
}
