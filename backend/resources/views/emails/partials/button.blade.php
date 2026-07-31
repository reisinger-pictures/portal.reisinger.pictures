@php
    $buttonColor ??= '#2A9D8F';
@endphp
<!--[if mso]>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" align="center"><tr><td align="center">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center"><tr><td style="background-color:{{ $buttonColor }};padding:12px 24px;border-radius:4px;" align="center">
<a href="{{ $url }}" target="_blank" rel="noopener" style="color:#ffffff;font-weight:bold;text-decoration:none;font-size:14px;font-family:Arial,Helvetica,sans-serif;">{{ $label }}</a>
</td></tr></table>
</td></tr></table>
<![endif]-->
<!--[if !mso]><!-->
<a href="{{ $url }}" target="_blank" rel="noopener" class="email-button" style="display:inline-block;background-color:{{ $buttonColor }};color:#ffffff;padding:12px 24px;text-decoration:none;font-weight:bold;font-size:14px;border-radius:4px;font-family:Arial,Helvetica,sans-serif;">{{ $label }}</a>
<!--<![endif]-->
