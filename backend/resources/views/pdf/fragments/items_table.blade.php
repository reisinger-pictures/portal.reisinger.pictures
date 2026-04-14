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
        @foreach($items as $item)
        <tr>
            <td>
                <strong>{{ $item['filename'] }}</strong>
                @if(!empty($item['notes']))<br><small style="color: #666;">{{ $item['notes'] }}</small>@endif
            </td>
            <td class="text-right">{{ fmod($item['qty'], 1) !== 0.0 ? number_format($item['qty'], 2, ',', '.') : number_format($item['qty'], 0, ',', '.') }}</td>
            <td class="text-right">{{ number_format($item['price'], 2, ',', '.') }} €</td>
            <td class="text-right">{{ number_format($item['row_total'], 2, ',', '.') }} €</td>
        </tr>
        @endforeach
        <tr class="total-row">
            <td colspan="3" class="text-right">{{ ($title === 'ANGEBOT') ? 'Voraussichtliche Gesamtsumme' : 'Rechnungsbetrag' }}</td>
            <td class="text-right">{{ number_format($snapshot->total_gross, 2, ',', '.') }} €</td>
        </tr>
    </tbody>
</table>
