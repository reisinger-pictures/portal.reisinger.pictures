<?php

namespace App\Mail;

use Illuminate\Support\Facades\Log;

class OrgInviteMail extends AbstractBrandAwareMailable
{
    public $orgName;
    public $inviteLink;

    public function __construct($orgName, $inviteLink)
    {
        $this->orgName = $orgName;
        $this->inviteLink = $inviteLink;
        $this->initializeBrand();
    }

    public function build()
    {
        $this->applyBrandFrom();

        return $this->subject("Einladung zur Organisation: {$this->orgName}")
                    ->view('emails.org_invite')
                    ->with([
                        'logoUrl' => $this->brandLogoUrl(),
                        'orgName' => $this->orgName,
                    ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Queue job failed', [
            'job' => static::class,
            'orgName' => $this->orgName,
            'exception' => $exception->getMessage(),
        ]);
    }
}
