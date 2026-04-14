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
        // PDF on-the-fly generieren
        $pdf = Pdf::loadView('pdf.invoice', [
            'order' => $this->order,
            'snapshot' => $this->snapshot,
            'items' => $this->snapshot->customer_details['items'] ?? [],
            'bankHolder' => \App\Models\Setting::where('key', 'bank_holder')->value('value'),
            'bankIban' => \App\Models\Setting::where('key', 'bank_iban')->value('value'),
            'bankBic' => \App\Models\Setting::where('key', 'bank_bic')->value('value')
        ]);

        $tempPath = storage_path('app/private/temp/invoice_' . $this->snapshot->invoice_number . '_' . uniqid() . '.pdf');
        if (!is_dir(dirname($tempPath))) mkdir(dirname($tempPath), 0755, true);
        $pdf->save($tempPath);

        $mail = $this->subject('Ihre Rechnung ' . $this->snapshot->invoice_number)
                    ->bcc(env('ACCOUNTING_EMAIL', 'accounting@reisinger.pictures'))
                    ->view('emails.custom')
                    ->with([
                        'subject' => 'Ihre Rechnung ' . $this->snapshot->invoice_number,
                        'customBody' => '<p>Guten Tag ' . $this->snapshot->customer_details['name'] . ',</p><p>vielen Dank für Ihre Bestellung im Bild-Portal. Anbei erhalten Sie Ihre Rechnung als PDF-Dokument.</p><p>Ihre Lizenzen und Downloads sind ab sofort in Ihrem Account verfügbar.</p>'
                    ])
                    ->attach($tempPath, [
                        'as' => $this->snapshot->invoice_number . '.pdf',
                        'mime' => 'application/pdf',
                    ]);

        foreach ($this->additionalDocuments as $filename => $pdfData) {
            $mail->attachData($pdfData, $filename, ['mime' => 'application/pdf']);
        }
        return $mail;
    }
}
