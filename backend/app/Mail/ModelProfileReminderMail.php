<?php

namespace App\Mail;

use App\Enums\Brand;
use Illuminate\Support\Facades\Log;

/**
 * Lifecycle-Reminder an ein Model (T+12/13/14 Monate) mit Profil-Magic-Link.
 * Der Empfänger ist die gespeicherte Kontakt-E-Mail der Person; die Mail wird
 * brand-aware gerendert.
 */
class ModelProfileReminderMail extends AbstractBrandAwareMailable
{
    public const STAGE_T12 = 't12';

    public const STAGE_T13 = 't13';

    public const STAGE_T14 = 't14';

    public string $link;

    public string $stage;

    public function __construct(string $link, string $stage, ?Brand $brand = null)
    {
        $this->link = $link;
        $this->stage = $stage;
        $this->initializeBrand($brand);
    }

    public function build()
    {
        $this->applyBrandFrom();

        return $this->subject($this->subjectForStage())
            ->view('emails.model_reminder')
            ->with([
                'logoUrl' => $this->brandLogoUrl(),
                'headline' => $this->headlineForStage(),
                'body' => $this->bodyForStage(),
            ]);
    }

    public function subjectForStage(): string
    {
        return match ($this->stage) {
            self::STAGE_T13 => 'Erinnerung: Dein Model-Profil wird inaktiv',
            self::STAGE_T14 => 'Letzte Erinnerung: Dein Model-Profil läuft bald ab',
            default => 'Dein Model-Profil: Bitte bestätigen',
        };
    }

    public function headlineForStage(): string
    {
        return match ($this->stage) {
            self::STAGE_T13 => 'Dein Profil wird inaktiv',
            self::STAGE_T14 => 'Letzte Erinnerung',
            default => 'Profil bestätigen',
        };
    }

    public function bodyForStage(): string
    {
        return match ($this->stage) {
            self::STAGE_T13 => 'Dein Model-Profil ist seit über 13 Monaten nicht bestätigt. Bitte aktualisiere deine Angaben, damit du weiterhin für Shootings vorgeschlagen wirst.',
            self::STAGE_T14 => 'Dein Model-Profil ist seit über 14 Monaten nicht bestätigt und wird in Kürze als abgelaufen geführt. Bestätige jetzt, um aktiv zu bleiben.',
            default => 'Dein Model-Profil ist seit über 12 Monaten nicht bestätigt. Bitte prüfe deine Angaben und bestätige dein Profil, damit es weiterhin aktiv ist.',
        };
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Queue job failed', [
            'job' => static::class,
            'exception' => $exception->getMessage(),
        ]);
    }
}
