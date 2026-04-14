<table class="invoice-details">
    <tr>
        <td style="width: 50%;">
            @if(!empty($snapshot->customer_details['name']) || !empty($snapshot->customer_details['company']))
                <strong>{{ ($title === 'ANGEBOT') ? 'Adressat:' : 'Rechnungsempfänger:' }}</strong><br>
                @if(!empty($snapshot->customer_details['company'])) {{ $snapshot->customer_details['company'] }}<br> @endif
                @if(!empty($snapshot->customer_details['name'])) {{ $snapshot->customer_details['name'] }}<br> @endif
                @if(!empty($snapshot->customer_details['street'])) {{ $snapshot->customer_details['street'] }}<br> @endif
                @if(!empty($snapshot->customer_details['zip']) || !empty($snapshot->customer_details['city'])) {{ $snapshot->customer_details['zip'] }} {{ $snapshot->customer_details['city'] }}<br> @endif
                @if(!empty($snapshot->customer_details['country'])) {{ $snapshot->customer_details['country'] }}<br> @endif
                @if(!empty($snapshot->customer_details['uid'])) UID: {{ $snapshot->customer_details['uid'] }}<br> @endif
            @endif
        </td>
        <td style="width: 50%; text-align: right;">
            <strong>Belegnummer:</strong> {{ $snapshot->invoice_number }}<br>
            <strong>Datum:</strong> {{ \Carbon\Carbon::parse($snapshot->created_at)->format('d.m.Y') }}<br>
            @if($title === 'ANGEBOT' && !empty($snapshot->customer_details['validity']))
                <strong>Gültigkeit:</strong> {{ $snapshot->customer_details['validity'] }}<br>
            @elseif($title !== 'ANGEBOT' && !empty($snapshot->customer_details['service_date']))
                <strong>Leistungszeitraum:</strong> {{ $snapshot->customer_details['service_date'] }}<br>
            @endif
            <strong>Status:</strong> {{ $snapshot->customer_details['due_date'] ?? '' }}
        </td>
    </tr>
</table>
