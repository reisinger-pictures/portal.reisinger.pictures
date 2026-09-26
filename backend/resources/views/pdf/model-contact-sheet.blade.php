<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Model Contact Sheet — {{ $displayName ?? '' }}</title>
    <style>
        @page { margin: 30px 32px 46px; }
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #333; font-size: 12px; line-height: 1.45; }
        h1, h2, h3 { margin: 0; }

        table.header { width: 100%; border-bottom: 2px solid {{ $primaryColor }}; padding-bottom: 10px; margin-bottom: 16px; }
        table.header td { vertical-align: middle; border: none; padding: 0; }
        .brand { font-size: 10px; color: {{ $secondaryColor }}; text-transform: uppercase; letter-spacing: 1px; font-weight: bold; }
        .title { font-size: 23px; font-weight: bold; color: {{ $primaryColor }}; }
        .meta { text-align: right; font-size: 11px; color: #666; vertical-align: top; }
        .variant-badge { display: inline-block; padding: 2px 8px; border: 1px solid {{ $secondaryColor }}; font-size: 10px; color: {{ $primaryColor }}; text-transform: uppercase; letter-spacing: 1px; }

        .section-title { font-size: 13px; font-weight: bold; color: {{ $secondaryColor }}; border-bottom: 1px solid #ddd; padding-bottom: 3px; margin: 16px 0 8px; page-break-after: avoid; }

        table.facts, table.answers { width: 100%; border-collapse: collapse; }
        table.facts td, table.answers td { padding: 4px 8px; vertical-align: top; border-bottom: 1px solid #f0f0f0; }
        table.facts td.label, table.answers td.label { width: 36%; color: #777; }

        table.matrix { width: 100%; border-collapse: collapse; margin-top: 4px; }
        table.matrix th, table.matrix td { border: 1px solid #e2e2e2; padding: 5px 8px; text-align: left; }
        table.matrix th { background-color: #f7f7f7; color: {{ $primaryColor }}; font-size: 11px; }

        table.photos { width: 100%; border-collapse: separate; border-spacing: 10px 6px; }
        table.photos td { vertical-align: top; text-align: center; width: 33%; border: none; padding: 0; }
        .primary-wrap { text-align: center; margin-bottom: 6px; page-break-inside: avoid; }
        .photo-frame { border: 1px solid #e2e2e2; padding: 3px; display: inline-block; }
        .photo-caption { font-size: 9px; color: #666; margin-top: 3px; }
        .photo-flag { font-size: 9px; font-weight: bold; color: {{ $primaryColor }}; }
        .vis-internal { color: #b45309; }

        .answer-section { page-break-inside: avoid; }
        .footer { margin-top: 22px; border-top: 1px solid #ddd; padding-top: 6px; font-size: 9px; color: #888; text-align: center; }

        .watermark { position: fixed; transform: rotate(-38deg); font-size: 32px; font-weight: bold; color: #ededed; letter-spacing: 2px; white-space: nowrap; }
    </style>
</head>
<body>
@if($watermarkText)
    {{-- Wiederholtes, dezentes Diagonal-Wasserzeichen über alle Seiten (extern). --}}
    @for($row = 0; $row < 5; $row++)
        @for($col = 0; $col < 3; $col++)
            <div class="watermark" style="top: {{ $row * 170 - 30 }}px; left: {{ $col * 220 - 70 }}px;">{{ $watermarkText }} &middot; Nur zur Ansicht</div>
        @endfor
    @endfor
@endif

@php
    $primaryPhoto = $photos[0] ?? null;
    $thumbnails = array_slice($photos, 1);
@endphp

<table class="header">
    <tr>
        <td>
            @if($logoDataUri)
                <img src="{{ $logoDataUri }}" style="max-height: 52px; margin-bottom: 4px;"><br>
            @endif
            <span class="brand">{{ $brandName }}</span><br>
            <span class="title">Model Contact Sheet</span>
        </td>
        <td class="meta">
            <span class="variant-badge">{{ $isExternal ? 'Extern' : 'Intern' }}</span><br>
            <strong>{{ $displayName ?? '—' }}</strong><br>
            Stand: {{ $generatedAt->format('d.m.Y H:i') }}
        </td>
    </tr>
</table>

@if($photos !== [])
    <div class="section-title">Fotos ({{ count($photos) }})</div>
    @if($primaryPhoto)
        <div class="primary-wrap">
            <span class="photo-frame">
                <img src="{{ $primaryPhoto['dataUri'] }}" width="{{ $primaryPhoto['width'] }}" height="{{ $primaryPhoto['height'] }}">
            </span>
            <div class="photo-caption">{{ $primaryPhoto['caption'] }}</div>
            @unless($isExternal)
                <div class="photo-flag {{ $primaryPhoto['visibility'] === 'internal' ? 'vis-internal' : '' }}">
                    {{ $primaryPhoto['visibility'] === 'internal' ? 'Intern (nicht öffentlich)' : 'Öffentlich' }}
                </div>
            @endunless
        </div>
    @endif

    @if($thumbnails !== [])
        <table class="photos">
            @foreach(array_chunk($thumbnails, 3) as $chunk)
                <tr>
                    @foreach($chunk as $photo)
                        <td>
                            <span class="photo-frame">
                                <img src="{{ $photo['dataUri'] }}" width="{{ $photo['width'] }}" height="{{ $photo['height'] }}">
                            </span>
                            <div class="photo-caption">{{ $photo['caption'] }}</div>
                            @unless($isExternal)
                                <div class="photo-flag {{ $photo['visibility'] === 'internal' ? 'vis-internal' : '' }}">
                                    {{ $photo['visibility'] === 'internal' ? 'Intern (nicht öffentlich)' : 'Öffentlich' }}
                                </div>
                            @endunless
                        </td>
                    @endforeach
                    {{-- Leere Zellen halten das 3er-Raster stabil. --}}
                    @for($i = count($chunk); $i < 3; $i++)
                        <td></td>
                    @endfor
                </tr>
            @endforeach
        </table>
    @endif
@endif

@if($isExternal)
    <div class="section-title">Steckbrief</div>
    <table class="facts">
        <tr><td class="label">Anzeigename</td><td>{{ $displayName ?? '—' }}</td></tr>
        <tr><td class="label">Ort</td><td>{{ $city ?? '—' }}</td></tr>
    </table>
@else
    <div class="section-title">Kontakt &amp; Basisdaten</div>
    <table class="facts">
        @foreach($facts as $fact)
            <tr><td class="label">{{ $fact['label'] }}</td><td>{{ $fact['value'] }}</td></tr>
        @endforeach
        <tr><td class="label">Altersnachweis</td><td>{{ $ageProofStatus }}</td></tr>
    </table>
@endif

@if($matrix !== [])
    <div class="section-title">Erfahrung &amp; Bereitschaft</div>
    <table class="matrix">
        <thead>
            <tr>
                <th style="width: 46%;">Kategorie</th>
                <th style="width: 27%;">Erfahrung</th>
                <th style="width: 27%;">Bereitschaft</th>
            </tr>
        </thead>
        <tbody>
            @foreach($matrix as $row)
                <tr>
                    <td>{{ $row['category'] }}</td>
                    <td>{{ $row['experience'] ?? '–' }}</td>
                    <td>{{ $row['willingness'] ?? '–' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

@unless($isExternal)
    @foreach($answersSections as $section)
        <div class="answer-section">
            <div class="section-title">{{ $section['label'] }}</div>
            <table class="answers">
                @foreach($section['items'] as $item)
                    <tr><td class="label">{{ $item['label'] }}</td><td>{{ $item['value'] }}</td></tr>
                @endforeach
            </table>
        </div>
    @endforeach
@endunless

<div class="footer">
    {{ $brandName }} &middot; Model Contact Sheet ({{ $isExternal ? 'Extern' : 'Intern' }}) &middot; erzeugt am {{ $generatedAt->format('d.m.Y H:i') }}
</div>
</body>
</html>
