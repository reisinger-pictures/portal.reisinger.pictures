<div style="text-align: center; margin-bottom: 20px;">
    @php
        $logoPath = \Illuminate\Support\Facades\Storage::disk('photos')->path('_watermarks/' . $pfx . 'watermark.svg');
        if (!file_exists($logoPath)) {
            $logoPath = \Illuminate\Support\Facades\Storage::disk('photos')->path('_watermarks/watermark.svg');
        }

        $primaryColor = $primaryColor ?? '#1E5631';
        $secondaryColor = $secondaryColor ?? '#A4B494';
    @endphp
    @if(file_exists($logoPath))
        <img src="data:image/svg+xml;base64,{{ base64_encode(file_get_contents($logoPath)) }}" style="max-height: 96px; max-width: 100%;">
    @endif
</div>
<table width="100%" style="margin-bottom: 30px; border-bottom: 2px solid {{ $primaryColor }}; padding-bottom: 10px;">
    <tr>
        <td style="vertical-align: bottom;">
            <h1 style="font-size: 28px; font-weight: bold; color: {{ $primaryColor }}; margin: 0;">{{ $title }}</h1>
        </td>
        <td style="text-align: right; vertical-align: top; font-size: 12px; color: #666;">
            @php 
                $street = \App\Models\Setting::where('key', $pfx . 'company_street')->value('value') ?? \App\Models\Setting::where('key', 'company_street')->value('value');
                $zip = \App\Models\Setting::where('key', $pfx . 'company_zip')->value('value') ?? \App\Models\Setting::where('key', 'company_zip')->value('value');
                $city = \App\Models\Setting::where('key', $pfx . 'company_city')->value('value') ?? \App\Models\Setting::where('key', 'company_city')->value('value');
                $country = \App\Models\Setting::where('key', $pfx . 'company_country')->value('value') ?? \App\Models\Setting::where('key', 'company_country')->value('value');
                $email = \App\Models\Setting::where('key', $pfx . 'company_email')->value('value') ?? \App\Models\Setting::where('key', 'company_email')->value('value');
            @endphp
            <strong>{{ $bankHolder }}</strong><br>
            @if($street){{ $street }}<br>@endif
            @if($zip || $city){{ trim($zip . ' ' . $city) }}@if($country), {{ $country }}@endif<br>@endif
            @if($email){{ $email }}@endif
        </td>
    </tr>
</table>