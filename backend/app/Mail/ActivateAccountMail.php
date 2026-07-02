<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ActivateAccountMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, BrandAwareMail;

    public $tries = 3;

    public $backoff = [30, 60, 120];

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
        $this->applyBrandFrom();

        return $this->subject($this->mailSubject)
                    ->view('emails.activate')
                    ->with([
                        'logoUrl' => $this->brandLogoUrl(),
                    ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Queue job failed', [
            'job' => static::class,
            'userName' => $this->userName,
            'exception' => $exception->getMessage(),
        ]);
    }
}
