@extends('emails.layouts.app')

@section('title', 'Einladung zur Model-Registrierung')

@section('preheader', 'Du wurdest zur Model-Registrierung eingeladen.')

@section('content')
    <h2 style="color:#2A9D8F;margin-top:0;font-size:20px;line-height:1.3;">Einladung zur Model-Registrierung</h2>
    <p style="color:#333333;line-height:1.6;margin-bottom:16px;">
        Du wurdest eingeladen, dich als Model zu registrieren. Über den folgenden Link kannst du
        einen Act mit einer oder mehreren Personen anlegen und die nötigen Angaben übermitteln.
    </p>
    <p style="color:#555555;line-height:1.5;margin-bottom:20px;font-size:14px;">
        Der Link ist 7 Tage gültig und kann nur einmal verwendet werden.
    </p>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:20px;">
        <tr>
            <td align="center">
                @include('emails.partials.button', ['url' => $inviteLink, 'label' => 'Registrierung starten'])
            </td>
        </tr>
    </table>
@endsection
