<?php

namespace App\Mail;

use App\Enums\Brand;
use App\Support\BrandRegistry;
use Illuminate\Support\Facades\Log;

/**
 * Erfolgsbenachrichtigung an den einladenden User nach abgeschlossener
 * Model-Registrierung — mit harten Fakten (Act-Typ, Personen, pro Person Name/
 * Alter/Ort/Kategorien inkl. Bereitschaft, Ausweis-Status, Portal-Konto) und
 * Deeplink auf das jeweilige Model-Profil im Admin.
 */
class ModelRegistrationSuccessMail extends AbstractBrandAwareMailable
{
    public string $recipientName;

    public string $managerName;

    public int $personCount;

    public string $actType;

    /** @var array<int, array<string, mixed>> */
    public array $persons;

    /**
     * @param  array<int, array<string, mixed>>  $persons  Per-person facts from the
     *                                                     submit context (`id`, `name`, `age`, `city`, `categories`, `age_proof_uploaded`, `portal_account`).
     */
    public function __construct(
        string $recipientName,
        string $managerName,
        int $personCount,
        string $actType = 'single',
        array $persons = [],
        ?Brand $brand = null,
    ) {
        $this->recipientName = $recipientName;
        $this->managerName = $managerName;
        $this->personCount = $personCount;
        $this->actType = $actType;
        $this->persons = $persons;
        $this->initializeBrand($brand);
    }

    public function build()
    {
        $this->applyBrandFrom();

        $frontendUrl = BrandRegistry::frontendUrl($this->brand);
        $this->persons = array_map(function (array $person) use ($frontendUrl): array {
            $person['model_url'] = $frontendUrl.'/admin-models?model='.($person['id'] ?? '');

            return $person;
        }, $this->persons);

        return $this->subject('Neue Model-Registrierung: '.$this->managerName)
            ->view('emails.model_registration_success')
            ->with([
                'logoUrl' => $this->brandLogoUrl(),
                'actTypeLabel' => $this->actTypeLabel(),
            ]);
    }

    public function actTypeLabel(): string
    {
        return match ($this->actType) {
            'couple' => 'Paar',
            'group' => 'Gruppe',
            default => 'Einzelperson',
        };
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Queue job failed', [
            'job' => static::class,
            'managerName' => $this->managerName,
            'exception' => $exception->getMessage(),
        ]);
    }
}
