<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>@if(str_starts_with($snapshot->invoice_number, 'L-')) Lieferschein @else Rechnung @endif {{ $snapshot->invoice_number }}</title>
    @include('pdf.fragments.styles', ['primaryColor' => $primaryColor ?? '#1E5631', 'secondaryColor' => $secondaryColor ?? '#A4B494'])
</head>
<body>
    @include('pdf.header', ['title' => str_starts_with($snapshot->invoice_number, 'L-') ? 'LIEFERSCHEIN' : 'RECHNUNG', 'bankHolder' => $bankHolder, 'pfx' => $pfx])

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

    {{-- Items + Totals (Single Source of Truth: pdf.fragments.items_table) --}}
    @include('pdf.fragments.items_table', [
        'items' => $items,
        'totalLabel' => 'Rechnungsbetrag',
        'totalGross' => $snapshot->total_gross,
        'invoiceMode' => true,
        'separateTotals' => true,
    ])

    <div style="margin-top: 20px;">
        @if(!empty($snapshot->customer_details['custom_conditions']))
            <div class="editor-content" style="margin-top: 10px;">
                <strong>Lizenzbedingungen / Custom Conditions</strong>
                {!! $snapshot->customer_details['custom_conditions'] !!}
            </div>
        @endif
        @if(!empty($snapshot->customer_details['custom_html_terms']))
            <div class="editor-content" style="margin-top: 10px;">
                {!! $snapshot->customer_details['custom_html_terms'] !!}
            </div>
        @endif
    </div>

    @include('pdf.footer', ['bankHolder' => $bankHolder, 'bankIban' => $bankIban, 'bankBic' => $bankBic])
</body>
</html>
