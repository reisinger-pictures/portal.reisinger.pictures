<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>@if(str_starts_with($snapshot->invoice_number, 'L-')) Lieferschein @else Rechnung @endif {{ $snapshot->invoice_number }}</title>
    <style>
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #333; font-size: 13px; line-height: 1.5; }
        .header { margin-bottom: 50px; border-bottom: 2px solid #2A9D8F; padding-bottom: 20px; }
        .company-info { text-align: right; font-size: 12px; color: #666; float: right; }
        .title { font-size: 28px; font-weight: bold; color: #2A9D8F; margin: 0; }
        .invoice-details { margin-bottom: 40px; width: 100%; }
        .invoice-details td { vertical-align: top; padding: 0; border: none; }
        table.items { width: 100%; border-collapse: collapse; margin-bottom: 30px; page-break-inside: auto; }
        table.items tr { page-break-inside: avoid; page-break-after: auto; }
        table.items thead { display: table-header-group; }
        table.items tfoot { display: table-footer-group; }
        table.items th, table.items td { padding: 12px; border-bottom: 1px solid #ddd; text-align: left; }
        table.items th { background-color: #f8f9fa; font-weight: bold; color: #264653; }
        .text-right { text-align: right !important; }
        .total-row td { font-size: 16px; font-weight: bold; border-top: 2px solid #264653; padding-top: 15px; }
        .footer { position: absolute; bottom: 30px; width: 100%; font-size: 10px; color: #888; border-top: 1px solid #ddd; padding-top: 10px; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <div class="company-info">
            <strong>{{ env('APP_NAME', 'Reisinger Foto Portal') }}</strong><br>
            Dein Fotograf<br>
            Musterstraße 1<br>
            4020 Linz, Österreich<br>
            hello@reisinger.pictures
        </div>
        <h1 class="title">@if(str_starts_with($snapshot->invoice_number, 'L-')) LIEFERSCHEIN @else RECHNUNG @endif</h1>
    </div>

    <table class="invoice-details">
        <tr>
            <td style="width: 50%;">
                <strong>Rechnungsempfänger:</strong><br>
                @if(!empty($snapshot->customer_details['company']))
                    {{ $snapshot->customer_details['company'] }}<br>
                @endif
                {{ $snapshot->customer_details['name'] }}<br>
                {{ $snapshot->customer_details['street'] }}<br>
                {{ $snapshot->customer_details['zip'] }} {{ $snapshot->customer_details['city'] }}<br>
                {{ $snapshot->customer_details['country'] }}<br>
                <small style="color: #666;">{{ $snapshot->customer_details['email'] }}</small>
            </td>
            <td style="width: 50%; text-align: right;">
                <strong>Belegnummer:</strong> {{ $snapshot->invoice_number }}<br>
                <strong>Leistungsdatum:</strong> {{ \Carbon\Carbon::parse($snapshot->created_at)->format('d.m.Y') }}<br>
                <strong>Fälligkeit:</strong> Zahlbar sofort netto Kassa.
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>Position / Lizenz</th>
                <th class="text-right">Netto Betrag</th>
            </tr>
        </thead>
        <tbody>
            @foreach($items as $item)
            <tr>
                <td>
                    <strong>Datei:</strong> {{ $item['filename'] }}<br>
                    <small style="color: #666;">Auflösung: {{ strtoupper($item['tier']) }} | {{ ($item['usage'] ?? 'editorial') === 'commercial' ? 'Kommerziell' : 'Redaktionell' }} | {{ ($item['duration'] ?? '1_year') === 'unlimited' ? 'Unbegrenzt' : '1 Jahr' }}</small>
                    @if(!empty($snapshot->customer_details['is_collective']))
                        <br><small style="color: #2A9D8F;">Bestellt von: {{ $item['ordered_by'] ?? 'Unbekannt' }}</small>
                    @endif
                </td>
                <td class="text-right">{{ number_format($item['price'], 2, ',', '.') }} €</td>
            </tr>
            @endforeach
            
            <tr>
                <td class="text-right" style="padding-top: 20px;"><strong>Gesamtbetrag (Netto)</strong></td>
                <td class="text-right" style="padding-top: 20px;"><strong>{{ number_format($snapshot->total_net, 2, ',', '.') }} €</strong></td>
            </tr>
            <tr>
                <td class="text-right"><small>Umsatzsteuer ({{ number_format($snapshot->tax_rate, 2, ',', '.') }}%)</small></td>
                <td class="text-right"><small>0,00 €</small></td>
            </tr>
            <tr class="total-row">
                <td class="text-right">Rechnungsbetrag</td>
                <td class="text-right">{{ number_format($snapshot->total_gross, 2, ',', '.') }} €</td>
            </tr>
        </tbody>
    </table>


    <div style="margin-top: 40px; border-top: 1px solid #ddd; padding-top: 20px;">
        <h4 style="font-size: 14px; margin-bottom: 10px;">Geltende Lizenzbedingungen für diese Positionen:</h4>
        <ul style="font-size: 11px; color: #555; padding-left: 15px;">
            @if(isset($snapshot->customer_details['terms']))
                @foreach($snapshot->customer_details['terms'] as $key => $termText)
                    <li style="margin-bottom: 4px;"><strong>{{ strtoupper($key) }}:</strong> {{ $termText }}</li>
                @endforeach
            @endif
        </ul>
    </div>

    <div class="footer">
        Umsatzsteuerfrei aufgrund der Kleinunternehmerregelung gem. § 6 Abs. 1 Z 27 UStG.<br>
        Bitte überweisen Sie den Rechnungsbetrag unter Angabe der Rechnungsnummer auf folgendes Konto:<br>
        IBAN: ATXX XXXX XXXX XXXX XXXX | BIC: XXXXXX
    </div>
</body>
</html>
