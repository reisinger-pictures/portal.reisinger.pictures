<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Angebot {{ $snapshot->invoice_number }}</title>
    @php
        $primaryColor = $primaryColor ?? '#1E5631';
        $secondaryColor = $secondaryColor ?? '#A4B494';
    @endphp
    <style>
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #333; font-size: 13px; line-height: 1.5; }
        .invoice-details { margin-bottom: 30px; width: 100%; }
        .invoice-details td { vertical-align: top; padding: 0; border: none; }
        h1, h2, h3, h4, h5, h6 { page-break-after: avoid; color: {{ $secondaryColor }}; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 20px; }
        table.items th, table.items td { padding: 10px; border-bottom: 1px solid #ddd; text-align: left; }
        table.items th { background-color: #f8f9fa; font-weight: bold; }
        .text-right { text-align: right !important; }
        .total-row td { font-size: 16px; font-weight: bold; border-top: 2px solid {{ $secondaryColor }}; padding-top: 15px; }
        .editor-content table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        .editor-content table td, .editor-content table th { border: 1px solid #ccc; padding: 8px; }
        .editor-content table th { background-color: #f2f2f2; font-weight: bold; }
        .footer { position: absolute; bottom: 30px; width: 100%; font-size: 10px; color: #888; border-top: 1px solid #ddd; padding-top: 10px; text-align: center; }
    </style>
</head>
<body>
    @include('pdf.header', ['title' => 'ANGEBOT', 'bankHolder' => $bankHolder, 'pfx' => $pfx ?? ''])
    @include('pdf.fragments.details', ['title' => 'ANGEBOT'])

    @if(!empty($snapshot->customer_details['custom_html_terms']))
        <div class="editor-content" style="margin-top: 20px; padding: 15px; background: #fcfcfc; border: 1px solid #eee; border-radius: 5px;">
            {!! $snapshot->customer_details['custom_html_terms'] !!}
        </div>
    @endif

    <table class="items">
        <thead>
            <tr>
                <th>Position</th>
                <th class="text-right">Menge</th>
                <th class="text-right">Preis</th>
                <th class="text-right">Gesamt</th>
            </tr>
        </thead>
        <tbody>
            @php $subtotal = 0; $hasDiscounts = false; @endphp
            @foreach($items as $item)
                @if(!isset($item['type']) || $item['type'] === 'item')
                    @php $subtotal += $item['row_total']; @endphp
                    <tr>
                        <td>
                            <strong>{{ $item['filename'] }}</strong>
                            @if(!empty($item['notes']))<br><small style="color: #666;">{{ $item['notes'] }}</small>@endif
                        </td>
                        <td class="text-right" style="white-space: nowrap;">{{ fmod($item['qty'] ?? 1, 1) !== 0.0 ? number_format($item['qty'] ?? 1, 2, ',', '.') : number_format($item['qty'] ?? 1, 0, ',', '.') }}</td>
                        <td class="text-right" style="white-space: nowrap;">
                            @if(isset($item['type']) && $item['type'] === 'discount_percent')
                                {{ rtrim(rtrim(number_format($item['price'] / 100, 4, ',', '.'), '0'), ',') }} %
                            @else
                                {{ number_format($item['price'] / 100, 2, ',', '.') }} €
                            @endif
                        </td>
                        <td class="text-right" style="white-space: nowrap;">{{ number_format($item['row_total'] / 100, 2, ',', '.') }} €</td>
                    </tr>
                @else
                    @php $hasDiscounts = true; @endphp
                @endif
            @endforeach

            @if($hasDiscounts)
                <tr>
                    <td colspan="3" class="text-right" style="padding-top: 15px; padding-bottom: 15px;"><strong>Zwischensumme</strong></td>
                    <td class="text-right" style="padding-top: 15px; padding-bottom: 15px;"><strong>{{ number_format($subtotal / 100, 2, ',', '.') }} €</strong></td>
                </tr>
                @include('pdf.fragments.discount_rows', ['items' => $items])
            @endif

            <tr class="total-row">
                <td colspan="3" class="text-right">Voraussichtlicher Gesamtbetrag</td>
                <td class="text-right">{{ number_format($snapshot->total_gross / 100, 2, ',', '.') }} €</td>
            </tr>
        </tbody>
    </table>

    @if(!empty($snapshot->customer_details['custom_conditions']))
        <div class="editor-content" style="margin-top: 20px; padding: 15px; background: #fcfcfc; border: 1px solid #eee; border-radius: 5px;">
            <strong>Lizenzbedingungen / Custom Conditions</strong>
            {!! $snapshot->customer_details['custom_conditions'] !!}
        </div>
    @endif

    @include('pdf.footer', ['bankHolder' => $bankHolder, 'bankIban' => $bankIban, 'bankBic' => $bankBic])
</body>
</html>