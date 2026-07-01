<?php

namespace App\Mail;

use App\Services\SettingResolver;
use App\Support\BrandRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Barryvdh\DomPDF\Facade\Pdf;

class InvoiceMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, BrandAwareMail;

    public $order;
    public $snapshot;
    public $additionalDocuments;

    public function __construct($order, $snapshot, $additionalDocuments = [])
    {
        $this->order = $order;
        $this->snapshot = $snapshot;
        $this->additionalDocuments = $additionalDocuments;
        $this->initializeBrand();
    }

    public function build()
    {
        BrandRegistry::set(BrandRegistry::resolveFromOrder($this->order));
        $this->applyBrandFrom();
        $resolver = app(SettingResolver::class);

        $pdf = Pdf::loadView('pdf.invoice', [
            'order' => $this->order,
            'snapshot' => $this->snapshot,
            'items' => $this->snapshot->customer_details['items'] ?? [],
            'bankHolder' => $resolver->get('bank_holder'),
            'bankIban' => $resolver->get('bank_iban'),
            'bankBic' => $resolver->get('bank_bic'),
        ]);

        $mail = $this->subject('Ihre Rechnung ' . $this->snapshot->invoice_number)
                    ->bcc($this->brandBcc())
                    ->view('emails.custom')
                    ->with([
                        'subject' => 'Ihre Rechnung ' . $this->snapshot->invoice_number,
                        'customBody' => '<p>Guten Tag ' . $this->snapshot->customer_details['name'] . ',</p><p>vielen Dank für Ihre Bestellung im Bild-Portal. Anbei erhalten Sie Ihre Rechnung als PDF-Dokument.</p><p>Ihre Lizenzen und Downloads sind ab sofort in Ihrem Account verfügbar.</p>',
                        'logoUrl' => $this->brandLogoUrl(),
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
