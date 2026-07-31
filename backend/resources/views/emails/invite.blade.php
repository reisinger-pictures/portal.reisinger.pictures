@extends('emails.layouts.app')

@section('title', 'Einladung zur Galerie')

@section('preheader', 'Deine Bilder sind fertig – schau dir die Galerie ' . $galleryName . ' an.')

@section('content')
    <h2 style="color:#2A9D8F;margin-top:0;font-size:20px;line-height:1.3;">Hallo!</h2>
    <p style="color:#333333;line-height:1.6;margin-bottom:20px;">Deine Bilder sind fertig! Du wurdest eingeladen, dir die Galerie <strong>{{ $galleryName }}</strong> anzusehen.</p>
    <p style="color:#333333;line-height:1.6;margin-bottom:20px;">Über den folgenden Link kannst du die Galerie öffnen, deine Auswahl treffen und Favoriten markieren:</p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:20px;">
        <tr>
            <td align="center">
                @include('emails.partials.button', ['url' => $inviteLink, 'label' => 'Hier klicken, um zur Galerie zu gelangen'])
            </td>
        </tr>
    </table>

    <p style="font-size:0.9em;color:#666666;line-height:1.4;">
        Falls der Button nicht funktioniert, kopiere diesen Link in deinen Browser:<br>
        <a href="{{ $inviteLink }}" style="color:#2A9D8F;word-break:break-all;">{{ $inviteLink }}</a>
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:30px 0 20px 0;">
        <tr>
            <td style="border-bottom:1px solid #eeeeee;font-size:0;line-height:0;">&nbsp;</td>
        </tr>
    </table>
    <p style="color:#666666;line-height:1.6;margin:0;">Liebe Grüße,<br>Dein Fotograf</p>
@endsection
