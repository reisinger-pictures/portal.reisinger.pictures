<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ActivateAccountMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, BrandAwareMail;

    public $userName;
    public $introText;
    public $actionUrl;
    public $actionText;
    public $mailSubject;

    public function __construct($userName, $introText, $actionUrl, $actionText, $mailSubject)
    {
        $this->userName = $userName;
        $this->introText = $introText;
        $this->actionUrl = $actionUrl;
        $this->actionText = $actionText;
        $this->mailSubject = $mailSubject;
        $this->initializeBrand();
    }

    public function build()
    {
        $this->ensureBrandContext();
        $this->applyBrandFrom();

        return $this->subject($this->mailSubject)
                    ->view('emails.activate')
                    ->with([
                        'logoUrl' => $this->brandLogoUrl(),
                    ]);
    }
}
