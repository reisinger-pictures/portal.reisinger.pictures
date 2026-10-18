<?php

namespace App\Services;

use App\Mail\ContractClosedMail;
use App\Models\Contract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Enqueue one closure message per recipient inside the contract-close
 * transaction. In production, the close transition and database-queue inserts
 * therefore commit or roll back together; local/testing sync queues are deferred
 * until commit so a rolled-back close cannot send mail inline.
 */
class ContractClosedMailDispatcher
{
    /**
     * @param  list<string>  $recipients
     */
    public function queueRecipients(Contract $contract, array $recipients): void
    {
        if ($recipients === []) {
            return;
        }

        if (! $this->usesTransactionalQueue()) {
            // Local development intentionally uses the sync driver. Preserve its
            // inline ergonomics without sending before a surrounding transaction
            // commits; production/staging fail closed rather than use this path.
            DB::afterCommit(function () use ($contract, $recipients): void {
                foreach ($recipients as $recipient) {
                    $this->queueRecipient($contract, $recipient);
                }
            });

            return;
        }

        foreach ($recipients as $recipient) {
            $this->queueRecipient($contract, $recipient);
        }
    }

    /**
     * Seam for deterministic partial-enqueue failure tests and queue adapters.
     */
    protected function queueRecipient(Contract $contract, string $recipient): void
    {
        Mail::to($recipient)->queue(new ContractClosedMail($contract));
    }

    private function usesTransactionalQueue(): bool
    {
        $queueConnection = config('queue.connections.database.connection');
        $databaseConnection = config('database.default');
        $isTransactionalQueue = config('queue.default') === 'database'
            && config('queue.connections.database.driver') === 'database'
            && is_string($queueConnection)
            && $queueConnection !== ''
            && $queueConnection === $databaseConnection
            && config('queue.connections.database.after_commit') === false;

        if (! $isTransactionalQueue) {
            if (app()->environment(['local', 'testing'])) {
                return false;
            }

            throw new RuntimeException(
                'Contract closure mail requires the transactional database queue outside local test environments.',
            );
        }

        return true;
    }
}
