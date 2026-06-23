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
    public $additionalDocuments;

    public function __construct($order, $snapshot, $additionalDocuments = [])
    {
        $this->order = $order;
        $this->snapshot = $snapshot;
        $this->additionalDocuments = $additionalDocuments;
    }

    public function build()
    {
        $pfx = config('app.brand') === 'all-the.rest' ? 'atr_' : '';
        $get = fn($k) => \App\Models\Setting::where('key', $pfx . $k)->value('value') ?? \App\Models\Setting::where('key', $k)->value('value');
        
        // PDF on-the-fly generieren
        $pdf = Pdf::loadView('pdf.invoice', [
            'order' => $this->order,
            'snapshot' => $this->snapshot,
            'items' => $this->snapshot->customer_details['items'] ?? [],
            'bankHolder' => $get('bank_holder'),
            'bankIban' => $get('bank_iban'),
            'bankBic' => $get('bank_bic')
        ]);

        $mail = $this->subject('Ihre Rechnung ' . $this->snapshot->invoice_number)
                    ->bcc(env('ACCOUNTING_EMAIL', 'accounting@reisinger.pictures'))
                    ->view('emails.custom')
                    ->with([
                        'subject' => 'Ihre Rechnung ' . $this->snapshot->invoice_number,
                        'customBody' => '<p>Guten Tag ' . $this->snapshot->customer_details['name'] . ',</p><p>vielen Dank für Ihre Bestellung im Bild-Portal. Anbei erhalten Sie Ihre Rechnung als PDF-Dokument.</p><p>Ihre Lizenzen und Downloads sind ab sofort in Ihrem Account verfügbar.</p>'
                    ])
                    ->attachData($pdf->output(), $this->snapshot->invoice_number . '.pdf', [
                        'mime' => 'application/pdf',
                    ]);

        foreach ($this->additionalDocuments as $filename => $pdfData) {
            $mail->attachData($pdfData, $filename, ['mime' => 'application/pdf']);
        }
        return $mail;
    }
}
