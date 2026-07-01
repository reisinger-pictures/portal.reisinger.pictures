@foreach($items as $item)
    @if(isset($item['type']) && str_starts_with($item['type'], 'discount'))
        @if($item['type'] === 'discount_coupon')
            <tr>
                <td>
                    <strong>{{ $item['filename'] }}</strong>
                    @if(!empty($item['notes']))
                        <br><small style="color: #666;">{{ $item['notes'] }}</small>
                    @endif
                </td>
                <td class="text-right" style="white-space: nowrap;">{{ $item['qty'] }}</td>
                <td class="text-right" style="white-space: nowrap;">{{ number_format($item['price'] / 100, 2, ',', '.') }} €</td>
                <td class="text-right" style="white-space: nowrap;">{{ number_format($item['row_total'] / 100, 2, ',', '.') }} €</td>
            </tr>
        @else
            <tr>
                <td>
                    <strong>{{ $item['filename'] }}</strong>
                    @if($item['type'] === 'discount_percent')
                        ({{ rtrim(rtrim(number_format($item['calculated_percentage'] ?? 0, 2, ',', '.'), '0'), ',') }}%)
                    @endif
                    @if(!empty($item['notes']))
                        <br><small style="color: #666;">{{ $item['notes'] }}</small>
                    @endif
                </td>
                <td class="text-right" style="white-space: nowrap;">1</td>
                <td class="text-right" style="white-space: nowrap;">
                    @if($item['type'] === 'discount_fixed')
                        {{ number_format($item['price'] / 100, 2, ',', '.') }} €
                    @else
                        -
                    @endif
                </td>
                <td class="text-right" style="white-space: nowrap;">
                    {{ number_format($item['row_total'] / 100, 2, ',', '.') }} €
                </td>
            </tr>
        @endif
    @endif
@endforeach
