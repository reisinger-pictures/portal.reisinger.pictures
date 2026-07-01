<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class NotificationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, BrandAwareMail;

    public $userName;
    public $messageBody;
    public $mailSubject;

    public function __construct($userName, $messageBody, $mailSubject)
    {
        $this->userName = $userName;
        $this->messageBody = $messageBody;
        $this->mailSubject = $mailSubject;
        $this->initializeBrand();
    }

    public function build()
    {
        $this->ensureBrandContext();
        $this->applyBrandFrom();

        return $this->subject($this->mailSubject)
                    ->bcc($this->brandBcc())
                    ->view('emails.notification')
                    ->with([
                        'logoUrl' => $this->brandLogoUrl(),
                    ]);
    }
}
