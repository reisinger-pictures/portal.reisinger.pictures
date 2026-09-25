<?php

namespace App\Services;

use Carbon\Carbon;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;

class ManualInvoiceService
{
    /**
     * Process and map manual-invoice items for PDF generation.
     *
     * Manual quantities use the explicit hundredths representation while
     * contract PDFs continue to use the contract path's whole quantities.
     * Both paths share the same checked ordered-discount engine.
     */
    public function processItems(array $items): array
    {
        return (new ContractPricingService)->processManualInvoiceLines($items);
    }

    /**
     * Prepare customer details array for invoice
     */
    public function prepareCustomerDetails(array $validated, HtmlSanitizer $sanitizer): array
    {
        return [
            'name' => $validated['customer_name'] ?? '',
            'company' => $validated['customer_company'] ?? '',
            'street' => $validated['customer_street'] ?? '',
            'zip' => $validated['customer_zip'] ?? '',
            'city' => $validated['customer_city'] ?? '',
            'country' => $validated['customer_country'] ?? '',
            'email' => $validated['customer_email'] ?? '',
            'uid' => $validated['customer_uid'] ?? '',
            'due_date' => $validated['due_date'],
            'is_collective' => false,
            'custom_html_terms' => isset($validated['terms_html']) ? $sanitizer->sanitize($validated['terms_html']) : null,
        ];
    }

    /**
     * Get bank details for invoice
     */
    public function getBankDetails(): array
    {
        $resolver = app(SettingResolver::class);

        return [
            'holder' => $resolver->get('bank_holder'),
            'iban' => $resolver->get('bank_iban'),
            'bic' => $resolver->get('bank_bic'),
        ];
    }

    /**
     * Extract offer data from PDF content.
     *
     * Reads the `%OFFER_JWT:{token}%` marker (clean break: the old
     * `%SMART_DOC:payload.signature%` HMAC marker is no longer supported) and
     * verifies the embedded JWT via OfferTokenService. Returns the offer
     * payload array on success, or null when no marker is present or the token
     * is invalid/expired.
     */
    public function extractOfferFromPdf(string $content): ?array
    {
        if (! preg_match('/OFFER_JWT:([A-Za-z0-9_\.\-]+)/', $content, $matches)) {
            return null;
        }

        return app(OfferTokenService::class)->verify($matches[1]);
    }

    /**
     * Generate a signed JWT offer payload and the PDF-EOF marker for embedding.
     *
     * The offer validity (`due_date`/`validity`) drives the JWT `exp`; on parse
     * failure the OfferTokenService default (14 days) applies.
     */
    public function generateOfferPayload(array $data): array
    {
        $expiresAt = $this->resolveExpiry($data);
        $token = app(OfferTokenService::class)->issue($data, $expiresAt);

        return [
            'token' => $token,
            'marker' => "%OFFER_JWT:{$token}%",
        ];
    }

    /**
     * Derive the offer expiry from the form data (`due_date` / `validity`).
     * Returns null when no usable date is present so the service default applies.
     */
    private function resolveExpiry(array $data): ?Carbon
    {
        foreach (['due_date', 'validity'] as $field) {
            $value = $data[$field] ?? null;
            if (is_string($value) && trim($value) !== '') {
                try {
                    return Carbon::parse($value);
                } catch (\Throwable) {
                    // Not a parseable date (e.g. free-text "14 Tage") → try next field.
                }
            }
        }

        return null;
    }

    /**
     * Prepare offer data for embedding. Includes the offer validity fields so
     * they can drive the JWT `exp` downstream.
     */
    public function prepareOfferData(array $validated): array
    {
        return [
            'customer_name' => $validated['customer_name'] ?? '',
            'customer_company' => $validated['customer_company'] ?? '',
            'customer_street' => $validated['customer_street'] ?? '',
            'customer_zip' => $validated['customer_zip'] ?? '',
            'customer_city' => $validated['customer_city'] ?? '',
            'customer_country' => $validated['customer_country'] ?? '',
            'customer_email' => $validated['customer_email'] ?? '',
            'customer_uid' => $validated['customer_uid'] ?? '',
            'items' => $validated['items'] ?? [],
            'terms_html' => $validated['terms_html'] ?? '',
            'due_date' => $validated['due_date'] ?? '',
            'validity' => $validated['validity'] ?? '',
        ];
    }
}
