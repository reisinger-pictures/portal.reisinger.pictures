<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Barryvdh\DomPDF\Facade\Pdf;

class InvoiceMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $order;
    public $snapshot;

    public function __construct($order, $snapshot)
    {
        $this->order = $order;
        $this->snapshot = $snapshot;
    }

    public function build()
    {
        // PDF on-the-fly generieren
        $pdf = Pdf::loadView('pdf.invoice', [
            'order' => $this->order,
            'snapshot' => $this->snapshot,
            'items' => $this->snapshot->customer_details['items'] ?? []
        ]);

        return $this->subject('Ihre Rechnung ' . $this->snapshot->invoice_number)
                    ->bcc(env('ACCOUNTING_EMAIL', 'accounting@reisinger.pictures'))
                    ->view('emails.custom') // Wir nutzen das bestehende Custom-Template
                    ->with([
                        'subject' => 'Ihre Rechnung ' . $this->snapshot->invoice_number,
                        'customBody' => '<p>Guten Tag ' . $this->snapshot->customer_details['name'] . ',</p><p>vielen Dank für Ihre Bestellung im Bild-Portal. Anbei erhalten Sie Ihre Rechnung als PDF-Dokument.</p><p>Ihre Lizenzen und Downloads sind ab sofort in Ihrem Account verfügbar.</p>'
                    ])
                    ->attachData($pdf->output(), $this->snapshot->invoice_number . '.pdf', [
                        'mime' => 'application/pdf',
                    ]);
    }
}
