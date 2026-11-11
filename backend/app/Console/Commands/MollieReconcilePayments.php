<?php

namespace App\Console\Commands;

use App\Models\MolliePaymentAttempt;
use App\Services\MollieCheckoutService;
use App\Services\MolliePaymentException;
use App\Services\MolliePaymentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Convergence for Mollie payment attempts that no webhook settled.
 *
 * The webhook is the normal authority. This command never trusts a local
 * status: every attempt with a known provider id is re-read with the
 * authenticated credential and its verified status is applied through the
 * same transition as the webhook. An attempt whose create answer never
 * arrived cannot be looked up by id and is therefore marked ambiguous once
 * the provider's idempotency window has passed — never replaced.
 */
class MollieReconcilePayments extends Command
{
    protected $signature = 'mollie:reconcile-payments
        {--minutes=2 : Only attempts not observed for at least this many minutes}
        {--limit=200 : Maximum number of attempts to inspect per run}';

    protected $description = 'Reconcile open Mollie payment attempts and settle verified paid orders.';

    public function handle(
        MolliePaymentService $mollie,
        MollieCheckoutService $mollieCheckout,
    ): int {
        if (! $mollie->isConfigured()) {
            $this->warn('Mollie is not configured; nothing to reconcile.');
            Log::warning('mollie.reconcile.not_configured');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $minutes = max(0, (int) $this->option('minutes'));
        $cutoff = now()->subMinutes($minutes);

        $this->settlePaidAttempts($mollieCheckout, $limit);

        $attempts = MolliePaymentAttempt::query()
            ->needsReconciliation()
            ->where(function ($query) use ($cutoff): void {
                $query->whereNull('last_reconciled_at')
                    ->orWhere('last_reconciled_at', '<=', $cutoff);
            })
            ->orderBy('last_reconciled_at')
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        $inspected = 0;
        $settled = 0;
        $ambiguous = 0;

        foreach ($attempts as $attempt) {
            $inspected++;

            if ($attempt->mollie_payment_id === null) {
                if ($mollieCheckout->abandonUnresolvedCreate($attempt)) {
                    $ambiguous++;
                    Log::warning('mollie.reconcile.unresolved_create_ambiguous', [
                        'attempt_id' => (string) $attempt->getKey(),
                        'order_id' => (string) $attempt->order_id,
                    ]);
                }

                continue;
            }

            try {
                $payment = $mollie->getPayment($attempt->mollie_payment_id);
            } catch (MolliePaymentException $exception) {
                Log::warning('mollie.reconcile.payment_lookup_failed', [
                    'attempt_id' => (string) $attempt->getKey(),
                    'reason' => $exception->reason,
                ]);

                continue;
            } catch (Throwable $exception) {
                Log::warning('mollie.reconcile.payment_lookup_failed', [
                    'attempt_id' => (string) $attempt->getKey(),
                    'exception_class' => $exception::class,
                ]);

                continue;
            }

            $result = $mollieCheckout->applyVerifiedPayment($attempt, $payment);
            if ($result['status'] === 'paid' || $result['status'] === 'already_paid') {
                $settled++;
            }
            if ($result['status'] === 'ignored') {
                Log::warning('mollie.reconcile.identity_ignored', [
                    'attempt_id' => (string) $attempt->getKey(),
                    'reason' => $result['reason'],
                ]);
            }
        }

        $this->info(sprintf(
            'Mollie reconciliation: %d inspected, %d settled, %d marked ambiguous.',
            $inspected,
            $settled,
            $ambiguous,
        ));

        return self::SUCCESS;
    }

    private function settlePaidAttempts(MollieCheckoutService $mollieCheckout, int $limit): void
    {
        $paidAttempts = MolliePaymentAttempt::query()
            ->where('status', MolliePaymentAttempt::STATUS_PAID)
            ->whereHas('order', fn ($query) => $query->where('status', 'pending_payment'))
            ->limit($limit)
            ->get();

        foreach ($paidAttempts as $attempt) {
            try {
                $mollieCheckout->settleVerifiedPaidAttempt($attempt);
                Log::warning('mollie.reconcile.paid_order_recovered', [
                    'attempt_id' => (string) $attempt->getKey(),
                    'order_id' => (string) $attempt->order_id,
                ]);
            } catch (Throwable $exception) {
                Log::error('mollie.reconcile.paid_order_recovery_failed', [
                    'attempt_id' => (string) $attempt->getKey(),
                    'exception_class' => $exception::class,
                ]);
            }
        }
    }
}
