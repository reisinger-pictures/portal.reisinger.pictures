@extends('emails.layouts.app')

@section('title', 'Neue Model-Registrierung')

@section('preheader', 'Eine Model-Registrierung wurde abgeschlossen.')

@section('content')
    <h2 style="color:#2A9D8F;margin-top:0;font-size:20px;line-height:1.3;">Hallo {{ $recipientName }},</h2>
    <p style="color:#333333;line-height:1.6;margin-bottom:16px;">
        die von dir eingeladene Person <b>{{ $managerName }}</b> hat die Model-Registrierung abgeschlossen.
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:20px;font-size:14px;color:#333333;">
        <tr>
            <td style="padding:4px 8px 4px 0;color:#777777;">Act-Typ</td>
            <td style="padding:4px 0;"><b>{{ $actTypeLabel }}</b></td>
        </tr>
        <tr>
            <td style="padding:4px 8px 4px 0;color:#777777;">Personen</td>
            <td style="padding:4px 0;"><b>{{ count($persons) }}</b></td>
        </tr>
    </table>

    @foreach($persons as $person)
        <h3 style="color:#2A9D8F;margin:0 0 8px;font-size:16px;">{{ $person['name'] ?: 'Unbenannt' }}</h3>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:12px;font-size:14px;color:#333333;border:1px solid #e0e0e0;border-radius:4px;">
            <tr>
                <td style="padding:6px 8px;color:#777777;width:120px;border-bottom:1px solid #eeeeee;">Alter</td>
                <td style="padding:6px 8px;border-bottom:1px solid #eeeeee;">{{ $person['age'] !== null ? $person['age'].' Jahre' : '–' }}</td>
            </tr>
            <tr>
                <td style="padding:6px 8px;color:#777777;border-bottom:1px solid #eeeeee;">Ort</td>
                <td style="padding:6px 8px;border-bottom:1px solid #eeeeee;">{{ $person['city'] ?: '–' }}</td>
            </tr>
            <tr>
                <td style="padding:6px 8px;color:#777777;border-bottom:1px solid #eeeeee;">Kategorien</td>
                <td style="padding:6px 8px;border-bottom:1px solid #eeeeee;">
                    @forelse($person['categories'] as $category)
                        {{ $category['label'] }}@if(!empty($category['willingness'])) ({{ $category['willingness'] }})@endif{{ !$loop->last ? ', ' : '' }}
                    @empty
                        –
                    @endforelse
                </td>
            </tr>
            <tr>
                <td style="padding:6px 8px;color:#777777;border-bottom:1px solid #eeeeee;">Ausweis</td>
                <td style="padding:6px 8px;border-bottom:1px solid #eeeeee;">{{ !empty($person['age_proof_uploaded']) ? 'hochgeladen' : 'ausstehend' }}</td>
            </tr>
            <tr>
                <td style="padding:6px 8px;color:#777777;">Portal-Konto</td>
                <td style="padding:6px 8px;">{{ !empty($person['portal_account']) ? 'ja' : 'nein' }}</td>
            </tr>
        </table>
        @if(!empty($person['model_url']))
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:20px;">
                <tr>
                    <td>
                        @include('emails.partials.button', ['url' => $person['model_url'], 'label' => 'Profil öffnen'])
                    </td>
                </tr>
            </table>
        @endif
    @endforeach
@endsection
