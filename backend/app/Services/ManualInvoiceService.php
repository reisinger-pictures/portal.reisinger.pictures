<?php

namespace App\Services;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use App\Models\Setting;

class ManualInvoiceService
{
    /**
     * Process and map invoice items for PDF generation
     */
    public function processItems(array $items): array
    {
        $runningTotal = 0;
        $mappedItems = [];

        foreach ($items as $item) {
            if ($item['type'] === 'item') {
                $rowTotal = $item['price'] * $item['qty'];
                $runningTotal += $rowTotal;
                $mappedItems[] = [
                    'type' => 'item',
                    'filename' => $item['description'],
                    'notes' => $item['notes'] ?? '',
                    'tier' => 'custom',
                    'qty' => $item['qty'],
                    'price' => $item['price'],
                    'row_total' => $rowTotal
                ];
            } elseif ($item['type'] === 'discount_fixed') {
                $runningTotal -= $item['price'];
                $mappedItems[] = [
                    'type' => 'discount_fixed',
                    'filename' => $item['description'],
                    'notes' => $item['notes'] ?? '',
                    'tier' => 'custom',
                    'qty' => 1,
                    'price' => $item['price'],
                    'row_total' => -$item['price']
                ];
            } elseif ($item['type'] === 'discount_percent') {
                $discountAmt = (int) round($runningTotal * ($item['price'] / 10000));
                $runningTotal -= $discountAmt;
                $mappedItems[] = [
                    'type' => 'discount_percent',
                    'filename' => $item['description'],
                    'notes' => $item['notes'] ?? '',
                    'tier' => 'custom',
                    'qty' => 1,
                    'price' => $item['price'],
                    'row_total' => -$discountAmt,
                    'calculated_percentage' => $item['price']
                ];
            }
        }

        return [
            'items' => $mappedItems,
            'total' => max(0, $runningTotal)
        ];
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
            'custom_html_terms' => isset($validated['terms_html']) ? $sanitizer->sanitize($validated['terms_html']) : null
        ];
    }

    /**
     * Get bank details for invoice
     */
    public function getBankDetails(): array
    {
        return [
            'holder' => Setting::where('key', 'bank_holder')->value('value'),
            'iban' => Setting::where('key', 'bank_iban')->value('value'),
            'bic' => Setting::where('key', 'bank_bic')->value('value')
        ];
    }

    /**
     * Extract offer data from PDF content
     */
    public function extractOfferFromPdf(string $content): ?array
    {
        if (preg_match('/%SMART_DOC:(.*?)\.(.*?)%/', $content, $matches)) {
            $payload = $matches[1];
            $signature = $matches[2];
            $appKey = config('app.key');

            if (hash_equals(hash_hmac('sha256', $payload, $appKey), $signature)) {
                return json_decode(base64_decode($payload), true);
            }

            // Return special marker for tampered signature
            return ['_signature_error' => true];
        }

        return null;
    }

    /**
     * Generate smart offer payload and signature for embedding in PDF
     */
    public function generateOfferPayload(array $data): array
    {
        $payload = base64_encode(json_encode($data));
        $signature = hash_hmac('sha256', $payload, config('app.key'));

        return [
            'payload' => $payload,
            'signature' => $signature,
            'marker' => "%SMART_DOC:{$payload}.{$signature}%"
        ];
    }

    /**
     * Prepare offer data for embedding
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
            'terms_html' => $validated['terms_html'] ?? ''
        ];
    }
}
