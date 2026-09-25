@php
    // Rechenlogik (Single Source of Truth): Zwischensumme + Rabatt-Erkennung.
    // $showSubtotal kann extern überschrieben werden (Default: nur bei Rabatten).
    $invoiceMode = $invoiceMode ?? false;
    $separateTotals = $separateTotals ?? false;
    $subtotal = 0;
    $hasDiscounts = false;
    $normalizedItems = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            throw new \InvalidArgumentException('The invoice contains an invalid row.');
        }
        if ((!isset($item['type']) || $item['type'] === 'item')
            && !array_key_exists('row_total', $item)
        ) {
            // Ordinary checkout snapshots historically omit row_total. Fill
            // that compatibility case with checked integer arithmetic; manual
            // fractional rows always arrive from ManualInvoiceService with an
            // authoritative row_total and never take this fallback.
            $price = (int) ($item['price'] ?? 0);
            $quantity = (int) ($item['qty'] ?? 1);
            if ($price < 0 || $quantity < 0
                || ($price !== 0 && $quantity > intdiv(\App\Services\ContractPricingService::MAX_SAFE_INTEGER, $price))
            ) {
                throw new \InvalidArgumentException('The invoice row exceeds the safe integer range.');
            }
            $item['row_total'] = $price * $quantity;
        }
        $normalizedItems[] = $item;
    }
    $items = $normalizedItems;

    foreach ($items as $item) {
        if (!isset($item['type']) || $item['type'] === 'item') {
            // Service-produced rows always carry the checked, authoritative
            // integer row total. Never re-multiply a potentially fractional
            // legacy quantity in the PDF layer.
            $rowTotal = (int) ($item['row_total'] ?? 0);
            if ($rowTotal < 0 || $rowTotal > \App\Services\ContractPricingService::MAX_SAFE_INTEGER - $subtotal) {
                throw new \InvalidArgumentException('The invoice subtotal exceeds the safe integer range.');
            }
            $subtotal += $rowTotal;
        } else {
            $hasDiscounts = true;
        }
    }
    $showSubtotal = $showSubtotal ?? $hasDiscounts;
@endphp
<table class="items">
    <thead>
        <tr>
            <th>Position</th>
            <th class="text-right">Menge</th>
            <th class="text-right">{{ $invoiceMode ? 'Preis / Stück' : 'Preis' }}</th>
            <th class="text-right">Gesamt</th>
        </tr>
    </thead>
    <tbody>
        @foreach($items as $item)
            @if(!isset($item['type']) || $item['type'] === 'item')
                <tr>
                    <td>
                        @if($invoiceMode && isset($item['tier']) && $item['tier'] !== 'custom')
                            <strong>Datei:</strong> {{ $item['filename'] ?? 'Unbekannt' }}<br>
                            <small style="color: #666;">Auflösung: {{ strtoupper($item['tier']) }}</small>
                        @elseif($invoiceMode)
                            <strong>{{ $item['filename'] ?? 'Unbekannt' }}</strong><br>
                            @if(!empty($item['notes']))
                                <small style="color: #666;">{{ $item['notes'] }}</small>
                            @endif
                        @else
                            <strong>{{ $item['filename'] }}</strong>
                            @if(!empty($item['notes']))<br><small style="color: #666;">{{ $item['notes'] }}</small>@endif
                        @endif
                    </td>
                    <td class="text-right" style="white-space: nowrap;">{{ fmod($item['qty'] ?? 1, 1) !== 0.0 ? number_format($item['qty'] ?? 1, 2, ',', '.') : number_format($item['qty'] ?? 1, 0, ',', '.') }}</td>
                    <td class="text-right" style="white-space: nowrap;">{{ \App\Support\PersistedMoney::formatCents((int) ($item['price'] ?? 0)) }} €</td>
                    <td class="text-right" style="white-space: nowrap;">{{ \App\Support\PersistedMoney::formatCents((int) ($item['row_total'] ?? 0)) }} €</td>
                </tr>
            @endif
        @endforeach
    </tbody>
@if($separateTotals)
</table>
<div style="page-break-inside: avoid;">
    <table class="items" style="border-top: none;">
@endif
    <tbody>
        @if($showSubtotal)
            <tr>
                <td colspan="3" class="text-right" style="padding-top: 15px; padding-bottom: 15px;"><strong>Zwischensumme</strong></td>
                <td class="text-right" style="padding-top: 15px; padding-bottom: 15px;"><strong>{{ \App\Support\PersistedMoney::formatCents($subtotal) }} €</strong></td>
            </tr>
            @include('pdf.fragments.discount_rows', ['items' => $items])
        @endif
        <tr class="total-row">
            <td colspan="3" class="text-right">{{ $totalLabel }}</td>
            <td class="text-right">{{ \App\Support\PersistedMoney::formatCents((int) $totalGross) }} €</td>
        </tr>
    </tbody>
@if($separateTotals)
    </table>
</div>
@else
</table>
@endif
