@extends('emails.layouts.app')

@section('title', 'Einladung zum Portal')

@section('preheader', 'Du wurdest eingeladen, der Organisation ' . $orgName . ' beizutreten.')

@section('content')
    <h2 style="color:#2A9D8F;margin-top:0;font-size:20px;line-height:1.3;">Einladung zu Organisation</h2>
    <p style="color:#333333;line-height:1.6;margin-bottom:16px;">Du wurdest eingeladen, der Organisation <b>{{ $orgName }}</b> auf dem Reisinger Foto Portal beizutreten.</p>
    <p style="color:#555555;line-height:1.5;margin-bottom:20px;font-size:14px;">
        Als Mitglied dieser Organisation erhältst du Zugriff auf freigeschaltete Galerien. Org-Administratoren können deine Bestellungen einsehen.
    </p>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:20px;">
        <tr>
            <td align="center">
                @include('emails.partials.button', ['url' => $inviteLink, 'label' => 'Einladung annehmen & Account aktivieren'])
            </td>
        </tr>
    </table>
@endsection
