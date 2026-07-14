<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Vertrag {{ $contract->id }}</title>
    <style>
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #333; font-size: 13px; line-height: 1.5; }
        .invoice-details { margin-bottom: 30px; width: 100%; }
        .invoice-details td { vertical-align: top; padding: 0; border: none; }
        h1, h2, h3, h4, h5, h6 { page-break-after: avoid; color: #4A5568; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 20px; }
        table.items th, table.items td { padding: 10px; border-bottom: 1px solid #ddd; text-align: left; }
        table.items th { background-color: #f8f9fa; font-weight: bold; }
        .text-right { text-align: right !important; }
        .total-row td { font-size: 16px; font-weight: bold; border-top: 2px solid #4A5568; padding-top: 15px; }
        .editor-content table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        .editor-content table td, .editor-content table th { border: 1px solid #ccc; padding: 8px; }
        .editor-content table th { background-color: #f2f2f2; font-weight: bold; }
        .signature-section { margin-top: 40px; }
        .signature-section table { width: 100%; border-collapse: collapse; }
        .signature-section th, .signature-section td { padding: 10px; border: 1px solid #ddd; text-align: left; }
        .signature-section th { background-color: #4A5568; color: #fff; font-weight: bold; }
        .audit-section { margin-top: 30px; page-break-inside: avoid; }
        .audit-section h4 { color: #4A5568; margin-bottom: 5px; }
        .audit-section table { width: 100%; border-collapse: collapse; font-size: 11px; }
        .audit-section th, .audit-section td { padding: 8px; border: 1px solid #ddd; text-align: left; }
        .audit-section th { background-color: #f8f9fa; font-weight: bold; color: #4A5568; }
        .footer { position: relative; bottom: 0; width: 100%; font-size: 10px; color: #888; border-top: 1px solid #ddd; padding-top: 10px; margin-top: 50px; text-align: center; }
        .section-title { font-size: 18px; font-weight: bold; color: #4A5568; border-bottom: 2px solid #4A5568; padding-bottom: 5px; margin-top: 30px; margin-bottom: 15px; }
    </style>
</head>
<body>
    @include('pdf.header', ['title' => 'VERTRAG', 'bankHolder' => $bankHolder, 'pfx' => $pfx ?? ''])

    <table class="invoice-details">
        <tr>
            <td style="width: 50%;">
                @if(!empty($contract->billing_details['name']) || !empty($contract->billing_details['company']))
                    <strong>Vertragspartner:</strong><br>
                    @if(!empty($contract->billing_details['company'])) {{ $contract->billing_details['company'] }}<br> @endif
                    @if(!empty($contract->billing_details['name'])) {{ $contract->billing_details['name'] }}<br> @endif
                    @if(!empty($contract->billing_details['street'])) {{ $contract->billing_details['street'] }}<br> @endif
                    @if(!empty($contract->billing_details['zip']) || !empty($contract->billing_details['city'])) {{ $contract->billing_details['zip'] }} {{ $contract->billing_details['city'] }}<br> @endif
                    @if(!empty($contract->billing_details['country'])) {{ $contract->billing_details['country'] }}<br> @endif
                    @if(!empty($contract->billing_details['uid'])) UID: {{ $contract->billing_details['uid'] }}<br> @endif
                    @if($ageLabel)
                        <p style="margin-top: 4px; font-size: 10px; color: #666;">{{ $ageLabel }}</p>
                    @endif
                @endif
            </td>
            <td style="width: 50%; text-align: right;">
                <strong>Vertragsnummer:</strong> {{ $contract->id }}<br>
                <strong>Datum:</strong> {{ \Carbon\Carbon::parse($contract->created_at)->format('d.m.Y') }}<br>
                <strong>Status:</strong> Geschlossen am {{ now()->format('d.m.Y') }}
            </td>
        </tr>
    </table>

    @if(!empty($contract->terms_html))
        <div class="section-title">Vertragstext</div>
        <div class="editor-content" style="margin-top: 20px; padding: 15px; background: #fcfcfc; border: 1px solid #eee; border-radius: 5px;">
            {!! $contract->terms_html !!}
        </div>
    @endif

    <div class="section-title">Leistungen und Vergütung</div>

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
                <td colspan="3" class="text-right">Gesamtbetrag</td>
                <td class="text-right">{{ number_format($total / 100, 2, ',', '.') }} €</td>
            </tr>
        </tbody>
    </table>

    <div class="signature-section">
        <div class="section-title">Unterschriften</div>

        @if($signers->isNotEmpty())
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>E-Mail</th>
                        <th>Rolle(n)</th>
                        <th>Unterschrieben am</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($signers as $signer)
                        <tr>
                            <td>{{ $signer->name }}</td>
                            <td>{{ $signer->email }}</td>
                            <td>{{ is_array($signer->roles) ? implode(', ', $signer->roles) : $signer->roles }}</td>
                            <td>{{ $signer->signed_at ? $signer->signed_at->format('d.m.Y H:i') : '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p style="color: #888;">Keine Unterschriften vorhanden.</p>
        @endif
    </div>

    @if($signers->isNotEmpty())
        <div class="section-title">Prüfprotokoll</div>

        @foreach($signers as $signer)
            <div class="audit-section">
                <h4>{{ $signer->name }} ({{ $signer->email }}) — Rolle(n): {{ is_array($signer->roles) ? implode(', ', $signer->roles) : $signer->roles }}</h4>
                @php $logs = $signer->auditLogs ?? collect(); @endphp
                @if($logs->isNotEmpty())
                    <table>
                        <thead>
                            <tr>
                                <th>Aktion</th>
                                <th>IP-Adresse</th>
                                <th>Zeitpunkt</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($logs as $log)
                                <tr>
                                    <td>{{ $log->action }}</td>
                                    <td>{{ $log->ip_address ?? '-' }}</td>
                                    <td>{{ $log->created_at ? $log->created_at->format('d.m.Y H:i:s') : '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <p style="color: #888; font-size: 11px;">Keine Protokolleinträge vorhanden.</p>
                @endif
            </div>
        @endforeach
    @endif

    @include('pdf.footer', ['bankHolder' => $bankHolder, 'bankIban' => $bankIban, 'bankBic' => $bankBic])

    <div style="position: absolute; bottom: 5px; right: 5px; font-size: 1px; color: transparent; opacity: 0;">
        {!! $offerMarker !!}
    </div>
</body>
</html>
