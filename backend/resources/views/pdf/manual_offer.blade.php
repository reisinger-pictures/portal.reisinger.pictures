<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Angebot {{ $snapshot->invoice_number }}</title>
    @include('pdf.fragments.styles', ['primaryColor' => $primaryColor ?? '#1E5631', 'secondaryColor' => $secondaryColor ?? '#A4B494'])
</head>
<body>
    @include('pdf.header', ['title' => 'ANGEBOT', 'bankHolder' => $bankHolder, 'pfx' => $pfx ?? ''])
    @include('pdf.fragments.details', ['title' => 'ANGEBOT'])

    @if(!empty($snapshot->customer_details['custom_html_terms']))
        <div class="editor-content" style="margin-top: 20px;">
            {!! $snapshot->customer_details['custom_html_terms'] !!}
        </div>
    @endif

    <div style="margin-top: 20px;">
        @include('pdf.fragments.items_table', [
            'items' => $items,
            'totalLabel' => 'Voraussichtlicher Gesamtbetrag',
            'totalGross' => $snapshot->total_gross,
            'invoiceMode' => false,
        ])
    </div>

    @if(!empty($snapshot->customer_details['custom_conditions']))
        <div class="editor-content" style="margin-top: 20px;">
            <strong>Lizenzbedingungen / Custom Conditions</strong>
            {!! $snapshot->customer_details['custom_conditions'] !!}
        </div>
    @endif

    @include('pdf.footer', ['bankHolder' => $bankHolder, 'bankIban' => $bankIban, 'bankBic' => $bankBic])
</body>
</html>
