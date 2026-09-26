<?php

namespace App\Services;

use App\Mail\CustomMail;
use App\Models\InvoiceSnapshot;
use App\Models\Order;
use App\Support\BrandRegistry;
use Illuminate\Support\Facades\DB;
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
        $snapshot = InvoiceSnapshot::query()
            ->where('order_id', $order->getKey())
            ->first();

        if (! $snapshot instanceof InvoiceSnapshot) {
            // Legacy/quote orders may not have an invoice snapshot. There is no
            // durable claim surface, but the alert is time-critical, so it is
            // sent directly. The webhook event claim still deduplicates an
            // identical replay at the ingress layer.
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
