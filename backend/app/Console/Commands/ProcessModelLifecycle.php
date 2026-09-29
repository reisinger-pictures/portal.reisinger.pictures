<?php

namespace App\Console\Commands;

use App\Enums\Brand;
use App\Mail\ModelProfileReminderMail;
use App\Models\ModelAccessToken;
use App\Models\ModelProfile;
use App\Services\DisputeMailDispatcher;
use App\Services\InvoiceMailDispatcher;
use App\Services\ModelFileStore;
use App\Services\ModelProfileEraser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Täglicher Lifecycle-Job: verschickt die Reminder-Mails (T+12/13/14 Monate)
 * idempotent und brand-aware. Der Versand hängt nicht am Katalogstand; auch ein
 * nicht migriertes v1-Profil erhält Reminder (Aktion dann `confirm`).
 *
 * Idempotenz: pro Stufe höchstens eine Mail. `last_reminder_stage` speichert die
 * höchste bereits versendete Stufe. Hat ein Profil keinen Kontaktweg, wird
 * nicht gesendet und die Stufe bewusst NICHT fortgeschrieben — sobald eine
 * E-Mail hinterlegt ist, greift der Reminder beim nächsten Lauf.
 *
 * ## At-most-once Versand, transaktional (D-17)
 *
 * Die Stufe ist der Claim, und Claim + Enqueue stehen in **einer**
 * Transaktion. Ohne diese Kopplung liefen beide Schritte auseinander: Mail
 * einreihen, dann `last_reminder_stage` speichern — ein Absturz dazwischen ließ
 * den Job dauerhaft in der Queue und die Stufe offen, und am nächsten Tag ging
 * dieselbe Erinnerung ein zweites Mal raus.
 *
 * Die Reihenfolge ist umgekehrt und bewusst so: erst der Claim, dann der
 * Versand. Ein Fehler danach rollt beides zurück (kein Reminder verloren, der
 * nächste Lauf versucht es erneut), ein Absturz nach dem Commit hinterlässt den
 * Claim und der nächste Tag schweigt.
 *
 * Ausdrücklich **keine** Exactly-once-Zusage für SMTP: ein Worker darf nach
 * einem Transportfehler erneut zustellen. Garantiert ist hier nur, dass
 * *eingereiht* wird — und zwar höchstens einmal. Das Muster ist dasselbe wie in
 * `InvoiceMailDispatcher::queueOnce()` und `DisputeMailDispatcher::queueOnce()`,
 * samt derselben Queue-Voraussetzung (siehe
 * {@see ProcessModelLifecycle::assertTransactionalQueueIsUsable()}).
 */
class ProcessModelLifecycle extends Command
{
    protected $signature = 'app:process-model-lifecycle';

    protected $description = 'Verschickt Model-Profil-Reminder (T+12/13/14 Monate) idempotent und brand-aware.';

    public function handle(): int
    {
        // Crash-safety sweep: remove stale `.plain-*` temp files that a hard
        // failure may have left between plaintext write and encryption.
        $cleaned = app(ModelFileStore::class)->cleanupOrphanedTempFiles();
        if ($cleaned > 0) {
            Log::warning('model.file.temp_cleanup', ['deleted' => $cleaned]);
        }

        $deleted = $this->deleteExpiredProfiles();
        $sent = $this->sendReminders();

        $this->info("Model-Lifecycle abgeschlossen. {$deleted} abgelaufene Profile gelöscht, {$sent} Reminder verschickt.");

        return self::SUCCESS;
    }

    /**
     * Hard-delete profiles whose last confirmation is older than 15 months
     * (13 active + 2 inactive). Uses the shared DSGVO erasure routine.
     */
    private function deleteExpiredProfiles(): int
    {
        $threshold = now()->subMonths(ModelProfile::LIFECYCLE_EXPIRED_MONTHS);
        $deleted = 0;

        ModelProfile::query()
            ->whereRaw('COALESCE(last_confirmed_at, submitted_at) < ?', [$threshold])
            ->with('customer')
            ->chunkById(200, function ($profiles) use (&$deleted): void {
                foreach ($profiles as $profile) {
                    $customer = $profile->customer;
                    if (! $customer) {
                        continue;
                    }

                    app(ModelProfileEraser::class)->erase($customer, 'expired');
                    $deleted++;
                }
            });

        return $deleted;
    }

    private function sendReminders(): int
    {
        $sent = 0;

        ModelProfile::query()
            ->whereRaw('COALESCE(last_confirmed_at, submitted_at) IS NOT NULL')
            ->with('customer')
            ->chunkById(200, function ($profiles) use (&$sent): void {
                foreach ($profiles as $profile) {
                    if ($this->processProfile($profile)) {
                        $sent++;
                    }
                }
            });

        return $sent;
    }

    private function processProfile(ModelProfile $profile): bool
    {
        $stage = $profile->dueReminderStage();
        if ($stage === null) {
            return false;
        }

        if (self::rank($stage) <= self::rank($profile->last_reminder_stage)) {
            return false;
        }

        $customer = $profile->customer;
        if (! $customer || ! $customer->email) {
            // Kein Kontaktweg: keine Mail. Der Admin sieht den Zustand; die
            // Stufe bleibt offen, damit ein später hinterlegtes Postfach greift.
            return false;
        }

        $this->assertTransactionalQueueIsUsable();

        $sent = DB::transaction(function () use ($profile, $customer): ?array {
            // Re-read under the row lock: a concurrent run (or an operator
            // change) may have moved the profile while this chunk was paging.
            // Deciding on the in-memory copy would queue a second reminder for
            // a stage that is already claimed.
            $locked = ModelProfile::query()
                ->whereKey($profile->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof ModelProfile) {
                return null;
            }

            $due = $locked->dueReminderStage();
            if ($due === null || self::rank($due) <= self::rank($locked->last_reminder_stage)) {
                return null;
            }

            $token = ModelAccessToken::issueFor($customer);
            $brand = Brand::tryFrom((string) $locked->brandValue());

            // Claim first, then enqueue — the order that makes the pair atomic.
            // A failure after this point rolls the claim back with the queue
            // insert, so the next run can try again instead of silently losing
            // the reminder.
            $locked->forceFill([
                'last_reminder_stage' => $due,
                'last_reminder_at' => now(),
            ])->save();

            Mail::to($customer->email)->queue(
                new ModelProfileReminderMail($token->url(), $due, $brand)
            );

            return [$due, $locked->getKey()];
        });

        if ($sent === null) {
            return false;
        }

        [$sentStage, $profileId] = $sent;

        Log::info('model.lifecycle.reminder', [
            'model_profile_id' => $profileId,
            'customer_id' => $customer->id,
            'stage' => $sentStage,
        ]);

        $this->line("Reminder {$sentStage} an {$customer->email} ({$profileId}).");

        return true;
    }

    /**
     * The claim and the jobs INSERT only share a commit boundary when the queue
     * writes to the application database inside the same transaction. An inline
     * `sync` driver or a separate queue connection would let a rolled-back
     * attempt still deliver the mail and a retry deliver a second one — exactly
     * the duplicate this class now prevents. This mirrors
     * {@see InvoiceMailDispatcher::queueOnce()} and
     * {@see DisputeMailDispatcher::queueOnce()}: enforced in every
     * non-local environment, not only in production. Local/test keep the inline
     * driver for developer ergonomics.
     *
     * The check sits directly in front of the transaction it protects, so a
     * run that has nothing due never fails on a queue configuration it does not
     * use.
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
                    ? 'Model lifecycle reminders require the transactional database queue in production.'
                    : 'Model lifecycle reminders require the transactional database queue outside local test environments.',
            );
        }
    }

    private static function rank(?string $stage): int
    {
        return match ($stage) {
            't12' => 12,
            't13' => 13,
            't14' => 14,
            default => 0,
        };
    }
}
