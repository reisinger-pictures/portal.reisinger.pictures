<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TenantInviteMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $tenantName;
    public $inviteLink;

    public function __construct($tenantName, $inviteLink)
    {
        $this->tenantName = $tenantName;
        $this->inviteLink = $inviteLink;
    }

    public function build()
    {
        return $this->subject("Einladung zum Portal: {$this->tenantName}")
                    ->view('emails.tenant_invite');
    }
}
