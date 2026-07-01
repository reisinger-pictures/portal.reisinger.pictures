<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>@if(str_starts_with($snapshot->invoice_number, 'L-')) Lieferschein @else Rechnung @endif {{ $snapshot->invoice_number }}</title>
    @php
        $isSrp = \App\Support\BrandRegistry::isSrp();
        $primaryColor = $isSrp ? '#2A9D8F' : '#1E5631';
        $secondaryColor = $isSrp ? '#2A9D8F' : '#A4B494';
    @endphp
    <style>
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #333; font-size: 13px; line-height: 1.5; }
        .invoice-details { margin-bottom: 40px; width: 100%; }
        .invoice-details td { vertical-align: top; padding: 0; border: none; }
        h1, h2, h3, h4, h5, h6 { page-break-after: avoid; }
        .editor-content table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        .editor-content table td, .editor-content table th { border: 1px solid #ccc; padding: 8px; text-align: left; }
        .editor-content table th { background-color: #f2f2f2; font-weight: bold; }
        table.items { width: 100%; border-collapse: collapse; margin-bottom: 0px; }
        table.items thead { display: table-header-group; }
        table.items th, table.items td { padding: 12px; border-bottom: 1px solid #ddd; text-align: left; }
        table.items th { background-color: #f8f9fa; font-weight: bold; color: {{ $primaryColor }}; }
        .text-right { text-align: right !important; }
        .total-row td { font-size: 16px; font-weight: bold; border-top: 2px solid {{ $secondaryColor }}; padding-top: 15px; }
        .footer { position: absolute; bottom: 30px; width: 100%; font-size: 10px; color: #888; border-top: 1px solid #ddd; padding-top: 10px; text-align: center; }
    </style>
</head>
<body>
    @include('pdf.header', ['title' => str_starts_with($snapshot->invoice_number, 'L-') ? 'LIEFERSCHEIN' : 'RECHNUNG', 'bankHolder' => $bankHolder])

    <table class="invoice-details">
        <tr>
            <td style="width: 50%;">
                @if(!empty($snapshot->customer_details['name']) || !empty($snapshot->customer_details['company']))
                    <strong>Rechnungsempfänger:</strong><br>
                    @if(!empty($snapshot->customer_details['company']))
                        {{ $snapshot->customer_details['company'] }}<br>
                    @endif
                    @if(!empty($snapshot->customer_details['name']))
                        {{ $snapshot->customer_details['name'] }}<br>
                    @endif
                    @if(!empty($snapshot->customer_details['street']))
                        {{ $snapshot->customer_details['street'] }}<br>
                    @endif
                    @if(!empty($snapshot->customer_details['zip']) || !empty($snapshot->customer_details['city']))
                        {{ $snapshot->customer_details['zip'] }} {{ $snapshot->customer_details['city'] }}<br>
                    @endif
                    @if(!empty($snapshot->customer_details['country']))
                        {{ $snapshot->customer_details['country'] }}<br>
                    @endif
                    @if(!empty($snapshot->customer_details['uid']))
                        UID: {{ $snapshot->customer_details['uid'] }}<br>
                    @endif
                    @if(!empty($snapshot->customer_details['email']))
                        <small style="color: #666;">{{ $snapshot->customer_details['email'] }}</small>
                    @endif
                @endif
            </td>
            <td style="width: 50%; text-align: right;">
                <strong>Belegnummer:</strong> {{ $snapshot->invoice_number }}<br>
                <strong>Rechnungsdatum:</strong> {{ \Carbon\Carbon::parse($snapshot->created_at)->format('d.m.Y') }}<br>
                <strong>Leistungsdatum:</strong> {{ $snapshot->customer_details['service_date'] ?? 'nicht angegeben' }}<br>
                <strong>Fälligkeit:</strong> {{ $snapshot->customer_details['due_date'] ?? 'Zahlbar sofort.' }}
            </td>
        </tr>
    </table>

    {{-- Main Items Table --}}
    <table class="items">
        <thead>
            <tr>
                <th>Position</th>
                <th class="text-right">Menge</th>
                <th class="text-right">Preis / Stück</th>
                <th class="text-right">Gesamt</th>
            </tr>
        </thead>
        <tbody>
            @php $subtotal = 0; $hasDiscounts = false; @endphp
            
            @foreach($items as $item)
                @if(!isset($item['type']) || $item['type'] === 'item')
                    @php $subtotal += $item['row_total'] ?? ($item['price'] * ($item['qty'] ?? 1)); @endphp
                    <tr>
                        <td>
                            @if(isset($item['tier']) && $item['tier'] === 'custom')
                                <strong>{{ $item['filename'] ?? 'Unbekannt' }}</strong><br>
                                @if(!empty($item['notes']))
                                    <small style="color: #666;">{{ $item['notes'] }}</small>
                                @endif
                            @else
                                <strong>Datei:</strong> {{ $item['filename'] ?? 'Unbekannt' }}<br>
                                <small style="color: #666;">Auflösung: {{ strtoupper($item['tier']) }}</small>
                            @endif
                        </td>
                        <td class="text-right" style="white-space: nowrap;">{{ fmod($item['qty'] ?? 1, 1) !== 0.0 ? number_format($item['qty'] ?? 1, 2, ',', '.') : number_format($item['qty'] ?? 1, 0, ',', '.') }}</td>
                        <td class="text-right" style="white-space: nowrap;">{{ number_format($item['price'] / 100, 2, ',', '.') }} €</td>
                        <td class="text-right" style="white-space: nowrap;">{{ number_format(($item['row_total'] ?? ($item['price'] * ($item['qty'] ?? 1))) / 100, 2, ',', '.') }} €</td>
                    </tr>
                @else
                    @php $hasDiscounts = true; @endphp
                @endif
            @endforeach
        </tbody>
    </table>

    {{-- Totals Table wrapped to prevent page breaks --}}
    <div style="page-break-inside: avoid;">
        <table class="items" style="border-top: none;">
            <tbody>
                @if($hasDiscounts)
                    <tr>
                        <td colspan="3" class="text-right" style="padding-top: 15px; padding-bottom: 15px;"><strong>Zwischensumme</strong></td>
                        <td class="text-right" style="padding-top: 15px; padding-bottom: 15px;"><strong>{{ number_format($subtotal / 100, 2, ',', '.') }} €</strong></td>
                    </tr>
                    @include('pdf.fragments.discount_rows', ['items' => $items])
                @endif
                
                <tr class="total-row">
                    <td colspan="3" class="text-right">Rechnungsbetrag</td>
                    <td class="text-right">{{ number_format($snapshot->total_gross / 100, 2, ',', '.') }} €</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div style="margin-top: 20px;">
        @if(!empty($snapshot->customer_details['custom_html_terms']))
            <div class="editor-content" style="font-size: 11px; color: #555; margin-top: 10px;">
                {!! $snapshot->customer_details['custom_html_terms'] !!}
            </div>
        @endif
    </div>

    @include('pdf.footer', ['bankHolder' => $bankHolder, 'bankIban' => $bankIban, 'bankBic' => $bankBic])
</body>
</html>