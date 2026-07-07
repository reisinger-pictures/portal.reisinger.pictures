<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>Einladung zum Portal</title></head>
<body style="margin: 0; padding: 0; background-color: #f4f4f4;">
    @php $logoUrl ??= rtrim(config('app.frontend_url'), '/') . '/android-chrome-192x192.png'; @endphp
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f4f4f4; padding: 20px; font-family: Arial, sans-serif;">
        <tr><td align="center">
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width: 600px; background-color: #ffffff; border: 1px solid #e0e0e0;">
                <tr><td align="center" style="padding: 30px 20px 10px 20px;">
                    <img src="{{ $logoUrl }}" alt="Logo" width="64" height="64" style="display: block; border-radius: 8px;" />
                </td></tr>
                <tr><td style="padding: 20px 30px 30px 30px;">
                    <h2 style="color: #2A9D8F; margin-top: 0;">Einladung zu Organisation</h2>
                    <p style="color: #333333; line-height: 1.6; margin-bottom: 16px;">Du wurdest eingeladen, der Organisation <b>{{ $orgName }}</b> auf dem Reisinger Foto Portal beizutreten.</p>
                    <p style="color: #555555; line-height: 1.5; margin-bottom: 20px; font-size: 14px;">
                        Als Mitglied dieser Organisation erhältst du Zugriff auf freigeschaltete Galerien. Org-Administratoren können deine Bestellungen einsehen.
                    </p>
                    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom: 20px;">
                        <tr><td align="center">
                            <a href="{{ $inviteLink }}" style="background-color: #2A9D8F; color: #ffffff; padding: 12px 24px; text-decoration: none; font-weight: bold; display: inline-block; border-radius: 6px;">Einladung annehmen & Account aktivieren</a>
                        </td></tr>
                    </table>
                </td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>
