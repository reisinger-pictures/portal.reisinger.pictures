<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="de" xml:lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>@yield('title', 'Reisinger Foto Portal')</title>
    <!--[if mso]>
    <noscript>
        <xml>
            <o:OfficeDocumentSettings>
                <o:PixelsPerInch>96</o:PixelsPerInch>
            </o:OfficeDocumentSettings>
        </xml>
    </noscript>
    <![endif]-->
    <style>
        html, body { margin: 0 !important; padding: 0 !important; height: 100% !important; width: 100% !important; }
        body { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; background-color: #f4f4f4; }
        * { -ms-text-size-adjust: 100%; }
        #outlook a { padding: 0; }
        img { border: 0; outline: none; text-decoration: none; -ms-interpolation-mode: bicubic; }
        table { border-collapse: collapse !important; mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        td, a, span { word-break: break-word; }
        .ExternalClass { width: 100%; }
        .ExternalClass, .ExternalClass p, .ExternalClass span, .ExternalClass font, .ExternalClass td, .ExternalClass div { line-height: 100%; }
        .email-container { background-color: #ffffff; border: 1px solid #e0e0e0; }
        .content-padding { padding-left: 30px; padding-right: 30px; }
        a { color: #2A9D8F; }
        @@media only screen and (max-width: 600px) {
            .content-padding { padding-left: 20px !important; padding-right: 20px !important; }
            .email-button { display: block !important; width: 100% !important; box-sizing: border-box !important; text-align: center !important; }
        }
        @@media screen and (prefers-color-scheme: dark) {
            body { background-color: #f4f4f4 !important; }
            .email-container { background-color: #ffffff !important; }
            .email-body { color: #333333 !important; }
        }
        [data-ogsc] body, [data-ogsb] body { background-color: #f4f4f4 !important; }
        [data-ogsc] .email-container, [data-ogsb] .email-container { background-color: #ffffff !important; }
        [data-ogsc] .email-body, [data-ogsb] .email-body { color: #333333 !important; }
    </style>
</head>
<body style="margin: 0; padding: 0; -webkit-text-size-adjust: 100%; background-color: #f4f4f4;">
    @php
        $logoUrl ??= (function () {
            $brand = \App\Support\BrandRegistry::current();
            $config = $brand ? \App\Support\BrandRegistry::configForBrand($brand->value) : null;
            $frontendUrl = rtrim(config('app.frontend_url'), '/');
            $logoPath = $config?->logoEmailPath ?? '/brands/rp/logo-email-64.png';
            return $frontendUrl . $logoPath;
        })();
    @endphp
    <div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;font-family:Arial,Helvetica,sans-serif;">
        @yield('preheader', 'Nachricht vom Reisinger Foto Portal')&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;
    </div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f4f4f4" style="background-color:#f4f4f4;margin:0;padding:0;">
        <tr>
            <td align="center" style="padding:20px 10px;">
                <!--[if mso]>
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" align="center" style="width:600px;">
                <tr><td>
                <![endif]-->
                <table role="presentation" class="email-container" width="100%" cellpadding="0" cellspacing="0" border="0" align="center" style="max-width:600px;background-color:#ffffff;border:1px solid #e0e0e0;">
                    <tr>
                        <td align="center" style="padding:30px 20px 10px 20px;">
                            <img src="{{ $logoUrl }}" alt="Logo" width="64" height="64" style="display:block;border-radius:8px;" />
                        </td>
                    </tr>
                    <tr>
                        <td class="content-padding email-body" style="padding:20px 30px 30px 30px;font-family:Arial,Helvetica,sans-serif;color:#333333;line-height:1.6;">
                            @yield('content')
                        </td>
                    </tr>
                </table>
                <!--[if mso]>
                </td></tr>
                </table>
                <![endif]-->
            </td>
        </tr>
    </table>
</body>
</html>
