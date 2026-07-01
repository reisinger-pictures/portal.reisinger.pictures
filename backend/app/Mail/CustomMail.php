<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CustomMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, BrandAwareMail;

    public $subject;
    public $customBody;

    public function __construct($subject, $customBody)
    {
        $this->subject = $subject;
        $this->customBody = $customBody;
        $this->initializeBrand();
    }

    public function build()
    {
        $this->ensureBrandContext();
        $this->applyBrandFrom();

        return $this->subject($this->subject)
                    ->bcc($this->brandBcc())
                    ->view('emails.custom')
                    ->with([
                        'logoUrl' => $this->brandLogoUrl(),
                    ]);
    }
}
