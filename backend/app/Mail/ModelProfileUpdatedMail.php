<?php

namespace App\Mail;

use App\Enums\Brand;
use App\Support\BrandRegistry;
use Illuminate\Support\Facades\Log;

/**
 * Benachrichtigung an den einladenden User, wenn ein Model sein Profil über den
 * öffentlichen Magic-Link aktualisiert hat — mit harten Fakten (Person/Act,
 * Zeitpunkt, Katalog-Hinweis) und Deeplink auf das Model-Profil im Admin.
 */
class ModelProfileUpdatedMail extends AbstractBrandAwareMailable
{
    public string $recipientName;

    public string $modelName;

    public string $actType;

    public int $personCount;

    public string $modelId;

    public string $updatedAt;

    public string $modelUrl = '';

    public function __construct(
        string $recipientName,
        string $modelName,
        string $actType,
        int $personCount,
        string $modelId,
        string $updatedAt,
        ?Brand $brand = null,
    ) {
        $this->recipientName = $recipientName;
        $this->modelName = $modelName;
        $this->actType = $actType;
        $this->personCount = $personCount;
        $this->modelId = $modelId;
        $this->updatedAt = $updatedAt;
        $this->initializeBrand($brand);
    }

    public function build()
    {
        $this->applyBrandFrom();

        $this->modelUrl = BrandRegistry::frontendUrl($this->brand).'/admin-models?model='.$this->modelId;

        return $this->subject('Model-Profil aktualisiert: '.$this->modelName)
            ->view('emails.model_profile_updated')
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
            'modelName' => $this->modelName,
            'exception' => $exception->getMessage(),
        ]);
    }
}
