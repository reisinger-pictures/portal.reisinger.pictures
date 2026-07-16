<?php

namespace App\Mail;

use Illuminate\Support\Facades\Log;

class TestMail extends AbstractBrandAwareMailable
{
    public string $recipient;

    public function __construct(string $recipient)
    {
        $this->recipient = $recipient;
        $this->initializeBrand();
    }

    public function build()
    {
        $this->applyBrandFrom();

        return $this->subject('Reisinger Portal — SMTP Test')
                    ->view('emails.test')
                    ->with([
                        'logoUrl' => $this->brandLogoUrl(),
                        'recipient' => $this->recipient,
                        'mailer' => config('mail.default'),
                    ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('TestMail queue job failed', [
            'job' => static::class,
            'recipient' => $this->recipient,
            'exception' => $exception->getMessage(),
        ]);
    }
}
