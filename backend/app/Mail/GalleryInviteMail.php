<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GalleryInviteMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, BrandAwareMail;

    public $tries = 3;

    public $backoff = [30, 60, 120];

    public $galleryName;
    public $inviteLink;

    public function __construct($galleryName, $inviteLink)
    {
        $this->galleryName = $galleryName;
        $this->inviteLink = $inviteLink;
        $this->initializeBrand();
    }

    public function build()
    {
        $this->applyBrandFrom();

        return $this->subject('Deine Foto-Auswahl: ' . $this->galleryName)
                    ->view('emails.invite')
                    ->with([
                        'logoUrl' => $this->brandLogoUrl(),
                    ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Queue job failed', [
            'job' => static::class,
            'galleryName' => $this->galleryName,
            'exception' => $exception->getMessage(),
        ]);
    }
}
