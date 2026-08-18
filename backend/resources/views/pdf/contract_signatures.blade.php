<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Vertrag {{ $contract->id }}</title>
    @include('pdf.fragments.styles', [
        'primaryColor' => $primaryColor ?? '#1E5631',
        'secondaryColor' => $secondaryColor ?? '#A4B494',
        'footerAbsolute' => false,
    ])
    <style>
        .signature-section { margin-top: 40px; }
        .signature-section table { width: 100%; border-collapse: collapse; }
        .signature-section th, .signature-section td { padding: 10px; border: 1px solid #ddd; text-align: left; }
        .signature-section th { background-color: {{ $primaryColor }}; color: #fff; font-weight: bold; }
        .audit-section { margin-top: 30px; page-break-inside: avoid; }
        .audit-section h4 { color: {{ $secondaryColor }}; margin-bottom: 5px; }
        .audit-section table { width: 100%; border-collapse: collapse; font-size: 11px; }
        .audit-section th, .audit-section td { padding: 8px; border: 1px solid #ddd; text-align: left; }
        .audit-section th { background-color: #f8f9fa; font-weight: bold; color: {{ $secondaryColor }}; }
        .section-title { font-size: 18px; font-weight: bold; color: {{ $secondaryColor }}; border-bottom: 2px solid {{ $secondaryColor }}; padding-bottom: 5px; margin-top: 30px; margin-bottom: 15px; }
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
        <div class="editor-content" style="margin-top: 20px;">
            {!! $contract->terms_html !!}
        </div>
    @endif

    <div class="section-title">Leistungen und Vergütung</div>

    <div style="margin-top: 20px;">
        @include('pdf.fragments.items_table', [
            'items' => $items,
            'totalLabel' => 'Gesamtbetrag',
            'totalGross' => $total,
            'invoiceMode' => false,
        ])
    </div>

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
