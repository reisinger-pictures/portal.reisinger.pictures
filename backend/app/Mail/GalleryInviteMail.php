<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;

class GalleryInviteMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, BrandAwareMail;

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
        $this->ensureBrandContext();
        $this->applyBrandFrom();

        return $this->subject('Deine Foto-Auswahl: ' . $this->galleryName)
                    ->view('emails.invite')
                    ->with([
                        'logoUrl' => $this->brandLogoUrl(),
                    ]);
    }
}
