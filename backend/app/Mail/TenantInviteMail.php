<?php

namespace App\Mail;

use Illuminate\Support\Facades\Log;

class TenantInviteMail extends AbstractBrandAwareMailable
{
    public $tenantName;
    public $inviteLink;

    public function __construct($tenantName, $inviteLink)
    {
        $this->tenantName = $tenantName;
        $this->inviteLink = $inviteLink;
        $this->initializeBrand();
    }

    public function build()
    {
        $this->applyBrandFrom();

        return $this->subject("Einladung zur Organisation: {$this->tenantName}")
                    ->view('emails.tenant_invite')
                    ->with([
                        'logoUrl' => $this->brandLogoUrl(),
                        'tenantName' => $this->tenantName,
                    ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Queue job failed', [
            'job' => static::class,
            'tenantName' => $this->tenantName,
            'exception' => $exception->getMessage(),
        ]);
    }
}
