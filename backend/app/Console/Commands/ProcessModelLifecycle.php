<?php

namespace App\Console\Commands;

use App\Enums\Brand;
use App\Mail\ModelProfileReminderMail;
use App\Models\ModelAccessToken;
use App\Models\ModelProfile;
use App\Services\ModelFileStore;
use App\Services\ModelProfileEraser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Täglicher Lifecycle-Job: verschickt die Reminder-Mails (T+12/13/14 Monate)
 * idempotent und brand-aware. Der Versand hängt nicht am Katalogstand; auch ein
 * nicht migriertes v1-Profil erhält Reminder (Aktion dann `confirm`).
 *
 * Idempotenz: pro Stufe höchstens eine Mail. `last_reminder_stage` speichert die
 * höchste bereits versendete Stufe. Hat ein Profil keinen Kontaktweg, wird
 * nicht gesendet und die Stufe bewusst NICHT fortgeschrieben — sobald eine
 * E-Mail hinterlegt ist, greift der Reminder beim nächsten Lauf.
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

        $token = ModelAccessToken::issueFor($customer);
        $brand = Brand::tryFrom((string) $profile->brandValue());

        Mail::to($customer->email)->queue(
            new ModelProfileReminderMail($token->url(), $stage, $brand)
        );

        $profile->forceFill([
            'last_reminder_stage' => $stage,
            'last_reminder_at' => now(),
        ])->save();

        Log::info('model.lifecycle.reminder', [
            'model_profile_id' => $profile->id,
            'customer_id' => $customer->id,
            'stage' => $stage,
        ]);

        $this->line("Reminder {$stage} an {$customer->email} ({$profile->id}).");

        return true;
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
