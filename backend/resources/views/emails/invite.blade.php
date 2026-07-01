<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Einladung zur Galerie</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f4f4;">
    @php $logoUrl ??= rtrim(config('app.frontend_url'), '/') . '/android-chrome-192x192.png'; @endphp
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f4f4f4; padding: 20px; font-family: Arial, sans-serif;">
        <tr><td align="center">
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width: 600px; background-color: #ffffff; border: 1px solid #e0e0e0;">
                <tr><td align="center" style="padding: 30px 20px 10px 20px;">
                    <img src="{{ $logoUrl }}" alt="Logo" width="64" height="64" style="display: block; border-radius: 8px;" />
                </td></tr>
                <tr><td style="padding: 20px 30px 30px 30px;">
                    <h2 style="color: #2A9D8F; margin-top: 0;">Hallo!</h2>
                    <p style="color: #333333; line-height: 1.6; margin-bottom: 20px;">Deine Bilder sind fertig! Du wurdest eingeladen, dir die Galerie <strong>{{ $galleryName }}</strong> anzusehen.</p>
                    <p style="color: #333333; line-height: 1.6; margin-bottom: 20px;">Über den folgenden Link kannst du die Galerie öffnen, deine Auswahl treffen und Favoriten markieren:</p>
                    
                    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom: 20px;">
                        <tr><td align="center">
                            <a href="{{ $inviteLink }}" style="background-color: #2A9D8F; color: #ffffff; padding: 12px 24px; text-decoration: none; font-weight: bold; display: inline-block;">Hier klicken, um zur Galerie zu gelangen</a>
                        </td></tr>
                    </table>
                    
                    <p style="font-size: 0.9em; color: #666666; line-height: 1.4;">
                        Falls der Button nicht funktioniert, kopiere diesen Link in deinen Browser:<br>
                        <a href="{{ $inviteLink }}" style="color: #2A9D8F; word-break: break-all;">{{ $inviteLink }}</a>
                    </p>
                    
                    <hr style="border: 0; border-top: 1px solid #eeeeee; margin: 30px 0 20px 0;">
                    <p style="color: #666666; line-height: 1.6; margin: 0;">
                        Liebe Grüße,<br>
                        Dein Fotograf
                    </p>
                </td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>