<?php

namespace App\Mail;

use App\Models\Contract;
use App\Services\ContractPdfService;
use App\Support\BrandRegistry;
use Illuminate\Support\Facades\Log;

class ContractClosedMail extends AbstractBrandAwareMailable
{
    public $contract;

    public function __construct(Contract $contract)
    {
        $this->contract = $contract;
        $this->initializeBrand();
    }

    public function build()
    {
        $this->brand = BrandRegistry::resolveFromContract($this->contract);

        $previousBrand = BrandRegistry::current();
        BrandRegistry::set($this->brand);

        try {
            $this->applyBrandFrom();

            $pdf = app(ContractPdfService::class)->generate($this->contract);

            $filename = 'Vertrag_' . now()->format('Y-m-d') . '.pdf';

            return $this->subject('Ihr unterschriebener Vertrag')
                ->bcc($this->brandBcc())
                ->view('emails.custom')
                ->with([
                    'subject' => 'Ihr unterschriebener Vertrag',
                    'customBody' => '<p>Guten Tag,</p><p>im Anhang erhalten Sie den unterschriebenen Vertrag als PDF-Dokument.</p><p>Bitte bewahren Sie dieses Dokument sorgfältig auf.</p>',
                    'logoUrl' => $this->brandLogoUrl(),
                ])
                ->attachData($pdf, $filename, [
                    'mime' => 'application/pdf',
                ]);
        } finally {
            BrandRegistry::set($previousBrand);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ContractClosedMail failed', [
            'contract_id' => $this->contract?->id,
            'exception' => $exception->getMessage(),
        ]);
    }
}
