<?php

namespace App\Services;

use App\Models\Contract;
use App\Support\BrandRegistry;
use Barryvdh\DomPDF\Facade\Pdf;

class ContractPdfService
{
    public function __construct(
        private ManualInvoiceService $manualInvoiceService,
        private OfferTokenService $offerTokenService,
        private SettingResolver $settingResolver,
    ) {}

    public function generate(Contract $contract): string
    {
        $contract->load('signers.auditLogs');

        $allLineItems = array_merge($contract->items ?? [], $contract->discounts ?? []);
        $processed = $this->manualInvoiceService->processItems($allLineItems);

        $bankDetails = $this->manualInvoiceService->getBankDetails();

        $offerData = [
            'customer_name' => $contract->billing_details['name'] ?? '',
            'customer_company' => $contract->billing_details['company'] ?? '',
            'customer_street' => $contract->billing_details['street'] ?? '',
            'customer_zip' => $contract->billing_details['zip'] ?? '',
            'customer_city' => $contract->billing_details['city'] ?? '',
            'customer_country' => $contract->billing_details['country'] ?? '',
            'customer_email' => $contract->billing_details['email'] ?? '',
            'customer_uid' => $contract->billing_details['uid'] ?? '',
            'items' => $contract->items ?? [],
            'terms_html' => $contract->terms_html ?? '',
            'due_date' => '',
            'validity' => '',
        ];
        $offerPayload = $this->offerTokenService->issue($offerData);
        $offerMarker = "%OFFER_JWT:{$offerPayload}%";

        $signers = $contract->signers->where('status', 'signed');
        $ageLabel = \App\Support\AgeHelper::format($contract->billing_details['birthdate'] ?? null, $contract->created_at?->format('Y-m-d'));
        $brand = BrandRegistry::resolveFromContract($contract);

        $config = BrandRegistry::configForBrand($brand->value);

        $pdf = Pdf::loadView('pdf.contract_signatures', [
            'contract' => $contract,
            'items' => $processed['items'],
            'total' => $processed['total'],
            'bankHolder' => $bankDetails['holder'],
            'bankIban' => $bankDetails['iban'],
            'bankBic' => $bankDetails['bic'],
            'signers' => $signers,
            'offerMarker' => $offerMarker,
            'pfx' => $brand->prefix(),
            'ageLabel' => $ageLabel,
            'primaryColor' => $config?->primaryColor ?? '#1E5631',
            'secondaryColor' => $config?->secondaryColor ?? '#A4B494',
        ]);

        return $pdf->output();
    }
}
