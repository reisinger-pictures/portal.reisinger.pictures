<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class RatingFinishedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, BrandAwareMail;

    public $notifiedUserName;
    public $clientName;
    public $clientEmail;
    public $galleryName;

    public function __construct($notifiedUserName, $clientName, $clientEmail, $galleryName)
    {
        $this->notifiedUserName = $notifiedUserName;
        $this->clientName = $clientName;
        $this->clientEmail = $clientEmail;
        $this->galleryName = $galleryName;
        $this->initializeBrand();
    }

    public function build()
    {
        $this->ensureBrandContext();
        $this->applyBrandFrom();

        return $this->subject("Auswahl abgeschlossen: {$this->galleryName}")
                    ->view('emails.rating_finished')
                    ->with([
                        'logoUrl' => $this->brandLogoUrl(),
                    ]);
    }
}
