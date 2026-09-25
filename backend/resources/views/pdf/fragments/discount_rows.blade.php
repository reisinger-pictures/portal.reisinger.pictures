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
                <td class="text-right" style="white-space: nowrap;">{{ \App\Support\PersistedMoney::formatCents((int) ($item['price'] ?? 0)) }} €</td>
                <td class="text-right" style="white-space: nowrap;">{{ \App\Support\PersistedMoney::formatCents((int) ($item['row_total'] ?? 0)) }} €</td>
            </tr>
        @else
            <tr>
                <td>
                    <strong>{{ $item['filename'] }}</strong>
                    @if($item['type'] === 'discount_percent')
                        {{-- calculated_percentage is display units (10% = 10), not the basis-point wire rate. --}}
                        @php
                            $basisPoints = max(0, (int) ($item['price'] ?? 0));
                            $wholePercent = intdiv($basisPoints, 100);
                            $fractionPercent = $basisPoints % 100;
                            $derivedPercentage = $fractionPercent === 0
                                ? (string) $wholePercent
                                : sprintf('%d.%02d', $wholePercent, $fractionPercent);
                            $metadataPercentage = array_key_exists('calculated_percentage', $item)
                                ? (string) $item['calculated_percentage']
                                : $derivedPercentage;
                            // Old snapshots sometimes persisted the raw
                            // basis-point rate in the display field. If the
                            // two values are identical, derive the safe
                            // display value from the canonical wire rate.
                            $calculatedPercentage = $metadataPercentage === (string) $basisPoints
                                ? $derivedPercentage
                                : $metadataPercentage;
                        @endphp
                        ({{ str_replace('.', ',', $calculatedPercentage) }}%)
                    @endif
                    @if(!empty($item['notes']))
                        <br><small style="color: #666;">{{ $item['notes'] }}</small>
                    @endif
                </td>
                <td class="text-right" style="white-space: nowrap;">1</td>
                <td class="text-right" style="white-space: nowrap;">
                    @if($item['type'] === 'discount_fixed')
                        {{ \App\Support\PersistedMoney::formatCents((int) ($item['price'] ?? 0)) }} €
                    @else
                        -
                    @endif
                </td>
                <td class="text-right" style="white-space: nowrap;">
                    {{ \App\Support\PersistedMoney::formatCents((int) ($item['row_total'] ?? 0)) }} €
                </td>
            </tr>
        @endif
    @endif
@endforeach
