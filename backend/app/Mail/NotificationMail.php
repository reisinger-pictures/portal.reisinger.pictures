<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class NotificationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $userName;
    public $messageBody;
    public $mailSubject;

    public function __construct($userName, $messageBody, $mailSubject)
    {
        $this->userName = $userName;
        $this->messageBody = $messageBody;
        $this->mailSubject = $mailSubject;
    }

    public function build()
    {
        return $this->subject($this->mailSubject)
                    ->bcc(env('ACCOUNTING_EMAIL', 'accounting@reisinger.pictures'))
                    ->view('emails.notification');
    }
}
