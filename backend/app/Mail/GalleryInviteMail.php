<?php

namespace App\Mail;

use Illuminate\Support\Facades\Log;

class GalleryInviteMail extends AbstractBrandAwareMailable
{
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
