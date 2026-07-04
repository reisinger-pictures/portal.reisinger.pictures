<?php

namespace App\Mail;

use Illuminate\Support\Facades\Log;

class CustomMail extends AbstractBrandAwareMailable
{
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
        $this->applyBrandFrom();

        return $this->subject($this->subject)
                    ->bcc($this->brandBcc())
                    ->view('emails.custom')
                    ->with([
                        'logoUrl' => $this->brandLogoUrl(),
                    ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Queue job failed', [
            'job' => static::class,
            'subject' => $this->subject,
            'exception' => $exception->getMessage(),
        ]);
    }
}
