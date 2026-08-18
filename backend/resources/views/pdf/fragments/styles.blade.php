@php
    $primaryColor = $primaryColor ?? '#1E5631';
    $secondaryColor = $secondaryColor ?? '#A4B494';

    // dompdf-fester heller Border-Ton: $secondaryColor zu 75 % mit Weiß gemischt
    // (festes Hex, kein rgba()/lighten() – dompdf rendert Alpha nicht zuverlässig)
    $hex = ltrim($secondaryColor, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    $lightMix = '';
    for ($i = 0; $i < 6; $i += 2) {
        $channel = hexdec(substr($hex, $i, 2));
        $lightMix .= str_pad(dechex((int) round($channel + (255 - $channel) * 0.75)), 2, '0', STR_PAD_LEFT);
    }
    $lightBorder = '#' . $lightMix;
@endphp
<style>
    body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #333; font-size: 13px; line-height: 1.5; orphans: 2; widows: 2; }
    .invoice-details { margin-bottom: 40px; width: 100%; }
    .invoice-details td { vertical-align: top; padding: 0; border: none; }
    h1, h2, h3, h4, h5, h6 { color: {{ $secondaryColor }}; page-break-after: avoid; }
    table.items { width: 100%; border-collapse: collapse; }
    table.items thead { display: table-header-group; }
    table.items th, table.items td { padding: 12px; border-bottom: 1px solid {{ $lightBorder }}; text-align: left; }
    table.items th { background-color: #f8f9fa; font-weight: bold; color: {{ $primaryColor }}; }
    .text-right { text-align: right !important; }
    .total-row td { font-size: 16px; font-weight: bold; border-top: 2px solid {{ $secondaryColor }}; padding-top: 15px; }
    .editor-content { font-size: 11px; color: #555; }
    .editor-content h1, .editor-content h2, .editor-content h3, .editor-content h4, .editor-content h5, .editor-content h6 { margin: 0.8em 0 0.4em; }
    .editor-content p { margin: 0 0 0.75em; }
    .editor-content ul, .editor-content ol { margin: 0 0 0.75em; padding-left: 1.4em; }
    .editor-content table { width: 100%; border-collapse: collapse; margin: 15px 0; }
    .editor-content table td, .editor-content table th { border: 1px solid {{ $lightBorder }}; padding: 8px; text-align: left; }
    .editor-content table th { background-color: #f2f2f2; font-weight: bold; }
    .editor-content p, .editor-content li { page-break-inside: avoid; }
    .footer { width: 100%; font-size: 10px; color: #888; border-top: 1px solid {{ $lightBorder }}; padding-top: 10px; text-align: center; }
    @if($footerAbsolute ?? true)
        .footer { position: absolute; bottom: 30px; }
    @else
        .footer { position: relative; bottom: 0; margin-top: 50px; }
    @endif
</style>
