@extends('emails.layouts.app')

@section('title', 'Model-Profil aktualisiert')

@section('preheader', 'Ein Model-Profil wurde über den Profil-Link aktualisiert.')

@section('content')
    <h2 style="color:#2A9D8F;margin-top:0;font-size:20px;line-height:1.3;">Hallo {{ $recipientName }},</h2>
    <p style="color:#333333;line-height:1.6;margin-bottom:16px;">
        <b>{{ $modelName }}</b> hat das eigene Model-Profil über den Profil-Link aktualisiert.
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:20px;font-size:14px;color:#333333;">
        <tr>
            <td style="padding:4px 8px 4px 0;color:#777777;">Act-Typ</td>
            <td style="padding:4px 0;"><b>{{ $actTypeLabel }}</b></td>
        </tr>
        <tr>
            <td style="padding:4px 8px 4px 0;color:#777777;">Personen</td>
            <td style="padding:4px 0;"><b>{{ $personCount }}</b></td>
        </tr>
        <tr>
            <td style="padding:4px 8px 4px 0;color:#777777;">Aktualisiert am</td>
            <td style="padding:4px 0;">{{ $updatedAt }}</td>
        </tr>
    </table>

    <p style="color:#555555;line-height:1.5;margin-bottom:20px;font-size:14px;">
        Die Angaben wurden auf den aktuellen Katalogstand gebracht. Bitte prüfe bei Bedarf
        das Profil im Admin-Bereich.
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:20px;">
        <tr>
            <td align="center">
                @include('emails.partials.button', ['url' => $modelUrl, 'label' => 'Profil öffnen'])
            </td>
        </tr>
    </table>
@endsection
