<?php

namespace App\Mail;

use Illuminate\Support\Facades\Log;

class ActivateAccountMail extends AbstractBrandAwareMailable
{
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
