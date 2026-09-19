<?php

namespace App\Mail;

use App\Enums\Brand;
use Illuminate\Support\Facades\Log;

/**
 * Einladung zur öffentlichen Model-Registrierung (Einmal-Link).
 */
class ModelInviteMail extends AbstractBrandAwareMailable
{
    public $inviteLink;

    public function __construct(string $inviteLink, ?Brand $brand = null)
    {
        $this->inviteLink = $inviteLink;
        $this->initializeBrand($brand);
    }

    public function build()
    {
        $this->applyBrandFrom();

        return $this->subject('Einladung zur Model-Registrierung')
            ->view('emails.model_invite')
            ->with([
                'logoUrl' => $this->brandLogoUrl(),
            ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Queue job failed', [
            'job' => static::class,
            'exception' => $exception->getMessage(),
        ]);
    }
}
