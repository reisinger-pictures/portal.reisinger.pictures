<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>Benachrichtigung</title></head>
<body style="margin: 0; padding: 0; background-color: #f4f4f4;">
    @php $logoUrl = rtrim(config('app.frontend_url'), '/') . '/android-chrome-192x192.png'; @endphp
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f4f4f4; padding: 20px; font-family: Arial, sans-serif;">
        <tr><td align="center">
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width: 600px; background-color: #ffffff; border: 1px solid #e0e0e0;">
                <tr><td align="center" style="padding: 30px 20px 10px 20px;">
                    <img src="{{ $logoUrl }}" alt="Logo" width="64" height="64" style="display: block; border-radius: 8px;" />
                </td></tr>
                <tr><td style="padding: 20px 30px 30px 30px;">
                    <h2 style="color: #2A9D8F; margin-top: 0;">Hallo {{ $userName }},</h2>
                    <div style="color: #333333; line-height: 1.6; margin-bottom: 20px;">{!! $messageBody !!}</div>
                </td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>