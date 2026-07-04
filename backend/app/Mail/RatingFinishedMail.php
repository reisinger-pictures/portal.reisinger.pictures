<?php

namespace App\Mail;

use Illuminate\Support\Facades\Log;

class RatingFinishedMail extends AbstractBrandAwareMailable
{
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
        $this->applyBrandFrom();

        return $this->subject("Auswahl abgeschlossen: {$this->galleryName}")
                    ->view('emails.rating_finished')
                    ->with([
                        'logoUrl' => $this->brandLogoUrl(),
                    ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Queue job failed', [
            'job' => static::class,
            'galleryName' => $this->galleryName,
            'clientEmail' => $this->clientEmail,
            'exception' => $exception->getMessage(),
        ]);
    }
}
