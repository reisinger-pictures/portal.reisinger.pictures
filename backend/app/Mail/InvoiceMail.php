<?php

namespace App\Mail;

use App\Services\SettingResolver;
use App\Support\BrandRegistry;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;

class InvoiceMail extends AbstractBrandAwareMailable
{
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
        $this->brand = BrandRegistry::resolveFromOrder($this->order);

        // Temporarily set brand so SettingResolver reads the correct brand scope,
        // then restore to prevent leakage to the rest of the request/process.
        $previousBrand = BrandRegistry::current();
        BrandRegistry::set($this->brand);

        try {
            $this->applyBrandFrom();
            $resolver = app(SettingResolver::class);

            $brandConfig = $this->brand ? BrandRegistry::configForBrand($this->brand->value) : null;

            $pdf = Pdf::loadView('pdf.invoice', [
                'order' => $this->order,
                'snapshot' => $this->snapshot,
                'items' => $this->snapshot->customer_details['items'] ?? [],
                'bankHolder' => $resolver->get('bank_holder'),
                'bankIban' => $resolver->get('bank_iban'),
                'bankBic' => $resolver->get('bank_bic'),
                'pfx' => $this->brand?->prefix() ?? '',
                'primaryColor' => $brandConfig?->primaryColor ?? '#1E5631',
                'secondaryColor' => $brandConfig?->secondaryColor ?? '#A4B494',
            ]);

            $customBody = '<p>Guten Tag '.$this->snapshot->customer_details['name'].',</p><p>vielen Dank für Ihre Bestellung im Bild-Portal. Anbei erhalten Sie Ihre Rechnung als PDF-Dokument.</p><p>Ihre Lizenzen und Downloads sind ab sofort in Ihrem Account verfügbar.</p>';

            if ($this->order->withdrawal_waived) {
                $consentAt = $this->order->withdrawal_consent_at
                    ? $this->order->withdrawal_consent_at->format('d.m.Y, H:i \U\h\r')
                    : null;
                $consentSuffix = $consentAt !== null ? ' (erteilt am '.$consentAt.')' : '';
                $customBody .= '<p>Sie haben beim Kauf dem sofortigen Download der digitalen Bilddaten ausdrücklich zugestimmt'.$consentSuffix.'. Gemäß § 18 Abs. 1 Z 11 Fern- und Auswärtsgeschäfte-Gesetz (FAGG) erlischt Ihr Rücktritts- bzw. Widerrufsrecht für digitale Inhalte, sobald mit ausdrücklicher Zustimmung vor Ablauf der Widerrufsfrist mit der Ausführung des Vertrags begonnen wurde. Für diese Bestellung besteht daher kein Widerrufs- bzw. Rücktrittsrecht mehr.</p>';
            }

            $mail = $this->subject('Ihre Rechnung '.$this->snapshot->invoice_number)
                ->bcc($this->brandBcc())
                ->view('emails.custom')
                ->with([
                    'subject' => 'Ihre Rechnung '.$this->snapshot->invoice_number,
                    'customBody' => $customBody,
                    'logoUrl' => $this->brandLogoUrl(),
                ])
                ->attachData($pdf->output(), $this->snapshot->invoice_number.'.pdf', [
                    'mime' => 'application/pdf',
                ]);

            foreach ($this->additionalDocuments as $filename => $pdfData) {
                $mail->attachData($pdfData, $filename, ['mime' => 'application/pdf']);
            }

            return $mail;
        } finally {
            BrandRegistry::set($previousBrand);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Queue job failed', [
            'job' => static::class,
            'order_id' => $this->order?->id,
            'invoice_number' => $this->snapshot?->invoice_number,
            'exception' => $exception->getMessage(),
        ]);
    }
}
