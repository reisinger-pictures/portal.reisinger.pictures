@php
    // Rechenlogik (Single Source of Truth): Zwischensumme + Rabatt-Erkennung.
    // $showSubtotal kann extern überschrieben werden (Default: nur bei Rabatten).
    $invoiceMode = $invoiceMode ?? false;
    $separateTotals = $separateTotals ?? false;
    $subtotal = 0;
    $hasDiscounts = false;
    foreach ($items as $item) {
        if (!isset($item['type']) || $item['type'] === 'item') {
            $subtotal += $item['row_total'] ?? ($item['price'] * ($item['qty'] ?? 1));
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
                    <td class="text-right" style="white-space: nowrap;">{{ number_format($item['price'] / 100, 2, ',', '.') }} €</td>
                    <td class="text-right" style="white-space: nowrap;">{{ number_format(($item['row_total'] ?? ($item['price'] * ($item['qty'] ?? 1))) / 100, 2, ',', '.') }} €</td>
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
                <td class="text-right" style="padding-top: 15px; padding-bottom: 15px;"><strong>{{ number_format($subtotal / 100, 2, ',', '.') }} €</strong></td>
            </tr>
            @include('pdf.fragments.discount_rows', ['items' => $items])
        @endif
        <tr class="total-row">
            <td colspan="3" class="text-right">{{ $totalLabel }}</td>
            <td class="text-right">{{ number_format($totalGross / 100, 2, ',', '.') }} €</td>
        </tr>
    </tbody>
@if($separateTotals)
    </table>
</div>
@else
</table>
@endif
