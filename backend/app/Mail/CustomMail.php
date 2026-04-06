<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CustomMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $subject;
    public $customBody;

    public function __construct($subject, $customBody)
    {
        $this->subject = $subject;
        $this->customBody = $customBody;
    }

    public function build()
    {
        return $this->subject($this->subject)
                    ->bcc(env('ACCOUNTING_EMAIL', 'accounting@reisinger.pictures'))
                    ->view('emails.custom');
    }
}
