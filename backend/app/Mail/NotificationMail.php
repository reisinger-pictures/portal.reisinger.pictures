<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class NotificationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, BrandAwareMail;

    public $tries = 3;

    public $backoff = [30, 60, 120];

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
        $this->applyBrandFrom();

        return $this->subject($this->mailSubject)
                    ->bcc($this->brandBcc())
                    ->view('emails.notification')
                    ->with([
                        'logoUrl' => $this->brandLogoUrl(),
                    ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Queue job failed', [
            'job' => static::class,
            'userName' => $this->userName,
            'subject' => $this->mailSubject,
            'exception' => $exception->getMessage(),
        ]);
    }
}
