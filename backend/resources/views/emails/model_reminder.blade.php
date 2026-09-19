@extends('emails.layouts.app')

@section('title', $headline)

@section('preheader', $headline)

@section('content')
    <h2 style="color:#2A9D8F;margin-top:0;font-size:20px;line-height:1.3;">{{ $headline }}</h2>
    <p style="color:#333333;line-height:1.6;margin-bottom:16px;">
        {{ $body }}
    </p>
    <p style="color:#555555;line-height:1.5;margin-bottom:20px;font-size:14px;">
        Über den folgenden Link kannst du dein Profil einsehen und bestätigen.
        Der Link ist 24 Stunden gültig.
    </p>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:20px;">
        <tr>
            <td align="center">
                @include('emails.partials.button', ['url' => $link, 'label' => 'Profil öffnen'])
            </td>
        </tr>
    </table>
@endsection
