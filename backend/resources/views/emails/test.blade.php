<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>SMTP Test</title></head>
<body style="margin: 0; padding: 0; background-color: #f4f4f4;">
    @php
        $logoUrl ??= (function () {
            $brand = \App\Support\BrandRegistry::current();
            $config = $brand ? \App\Support\BrandRegistry::configForBrand($brand->value) : null;
            $frontendUrl = rtrim(config('app.frontend_url'), '/');
            $logoPath = $config?->logoEmailPath ?? '/brands/rp/logo-email-64.png';
            return $frontendUrl . $logoPath;
        })();
    @endphp
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f4f4f4; padding: 20px; font-family: Arial, sans-serif;">
        <tr><td align="center">
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width: 600px; background-color: #ffffff; border: 1px solid #e0e0e0;">
                <tr><td align="center" style="padding: 30px 20px 10px 20px;">
                    <img src="{{ $logoUrl }}" alt="Logo" width="64" height="64" style="display: block; border-radius: 8px;" />
                </td></tr>
                <tr><td style="padding: 20px 30px 30px 30px; font-family: Arial, sans-serif; color: #333333; line-height: 1.6;">
                    <h2 style="margin: 0 0 16px 0;">SMTP-Verbindungstest</h2>
                    <p>Diese E-Mail wurde manuell über den Super-Admin-Bereich des Portals versendet.</p>
                    <p><strong>Empfänger:</strong> {{ $recipient }}<br />
                    <strong>Mailer:</strong> {{ $mailer }}<br />
                    <strong>Zeitpunkt:</strong> {{ now()->format('d.m.Y H:i:s') }}</p>
                    <p>Wenn du diese Nachricht erhältst, ist der E-Mail-Versand korrekt konfiguriert.</p>
                </td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>
