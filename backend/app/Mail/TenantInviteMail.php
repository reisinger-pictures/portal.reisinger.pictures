<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class TenantInviteMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, BrandAwareMail;

    public $tries = 3;

    public $backoff = [30, 60, 120];

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

        return $this->subject("Einladung zum Portal: {$this->tenantName}")
                    ->view('emails.tenant_invite')
                    ->with([
                        'logoUrl' => $this->brandLogoUrl(),
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
