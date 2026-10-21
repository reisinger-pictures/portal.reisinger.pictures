<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Model Contact Sheets — {{ $brandName }}</title>
    <style>
        @page { margin: 26px 26px 40px; }
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #333; font-size: 11px; line-height: 1.35; }
        h1, h2, h3 { margin: 0; }

        table.header { width: 100%; border-bottom: 2px solid {{ $primaryColor }}; padding-bottom: 8px; margin-bottom: 10px; }
        table.header td { vertical-align: middle; border: none; padding: 0; }
        .brand { font-size: 10px; color: {{ $secondaryColor }}; text-transform: uppercase; letter-spacing: 1px; font-weight: bold; }
        .title { font-size: 21px; font-weight: bold; color: {{ $primaryColor }}; }
        .meta { text-align: right; font-size: 10px; color: #666; vertical-align: top; }
        .variant-badge { display: inline-block; padding: 2px 8px; border: 1px solid {{ $secondaryColor }}; font-size: 10px; color: {{ $primaryColor }}; text-transform: uppercase; letter-spacing: 1px; }

        {{-- Kachel-Grid: zwei Spalten je Zeile, jede Kachel eine Profilauswahl. --}}
        table.tiles { width: 100%; border-collapse: separate; border-spacing: 9px 7px; }
        table.tiles td { vertical-align: top; width: 50%; border: 1px solid #e2e2e2; padding: 7px; }
        .tile-index { font-size: 8px; color: #888; letter-spacing: 0.4px; }
        .tile-name { font-size: 13px; font-weight: bold; color: {{ $primaryColor }}; }
        .tile-city { font-size: 10px; color: #666; }
        .tile-email { font-size: 10px; color: #444; }
        .tile-photo { text-align: center; margin: 4px 0; }
        .photo-caption { font-size: 8px; color: #777; }
        .tile-matrix { width: 100%; border-collapse: collapse; margin-top: 4px; }
        table.tiles .tile-matrix td { border: none; padding: 1px 0; font-size: 8px; color: #444; }
        table.tiles .tile-matrix td.category { color: #555; }

        {{-- D-41: Ausgelassene Fotos werden je Kachel benannt (Anzahl + Grund), PII-frei. --}}
        .omission-note { margin-top: 5px; padding: 4px 6px; border: 1px solid #d9d9d9; background-color: #f7f7f7; font-size: 8px; color: #555; }
        .omission-note .omission-title { font-weight: bold; color: {{ $primaryColor }}; }
        .omission-note ul { margin: 2px 0 0; padding-left: 14px; }

        {{-- Phase 4: Profile, die der Aufrufer nicht liefern konnte, werden benannt (kein stilles Verengen). --}}
        .profile-omission-note { margin-bottom: 8px; padding: 4px 6px; border: 1px solid #d9d9d9; background-color: #f7f7f7; font-size: 9px; color: #555; }
        .profile-omission-note .omission-title { font-weight: bold; color: {{ $primaryColor }}; }

        {{-- Byte-Budget der Sammlung: nicht eingebettete Fotos werden auf Dokumentebene benannt (Anzahl + Grund). --}}
        .budget-omission-note { margin-bottom: 8px; padding: 4px 6px; border: 1px solid #d9d9d9; background-color: #f7f7f7; font-size: 9px; color: #555; }
        .budget-omission-note .omission-title { font-weight: bold; color: {{ $primaryColor }}; }
        .footer { margin-top: 16px; border-top: 1px solid #ddd; padding-top: 6px; font-size: 9px; color: #888; text-align: center; }

        .watermark { position: fixed; transform: rotate(-38deg); font-size: 32px; font-weight: bold; color: #ededed; letter-spacing: 2px; white-space: nowrap; }
    </style>
</head>
<body>
@if($watermarkText)
    {{-- Wiederholtes, dezentes Diagonal-Wasserzeichen über alle Seiten (nur extern). --}}
    @for($row = 0; $row < 5; $row++)
        @for($col = 0; $col < 3; $col++)
            <div class="watermark" style="top: {{ $row * 170 - 30 }}px; left: {{ $col * 220 - 70 }}px;">{{ $watermarkText }} &middot; Nur zur Ansicht</div>
        @endfor
    @endfor
@endif

<table class="header">
    <tr>
        <td>
            @if($logoDataUri)
                <img src="{{ $logoDataUri }}" style="max-height: 46px; margin-bottom: 4px;"><br>
            @endif
            <span class="brand">{{ $brandName }}</span><br>
            <span class="title">Model Contact Sheets</span>
        </td>
        <td class="meta">
            <span class="variant-badge">{{ $isExternal ? 'Extern' : 'Intern' }}</span><br>
            <strong>{{ $total }} {{ $total === 1 ? 'Model' : 'Models' }}</strong><br>
            Stand: {{ $generatedAt->format('d.m.Y H:i') }}
        </td>
    </tr>
</table>

@if(($omittedProfiles ?? 0) > 0)
    {{-- Phase 4: Ein stilles Verengen der Auswahl ist nicht erlaubt — der Bogen nennt die Anzahl. --}}
    <div class="profile-omission-note">
        <span class="omission-title">{{ $omittedProfiles }} {{ $omittedProfiles === 1 ? 'Profil' : 'Profile' }} nicht mehr verfügbar</span>
    </div>
@endif

@if(($budgetOmission['count'] ?? 0) > 0)
    {{-- Byte-Budget der Sammlung: nicht eingebettete Fotos werden benannt (Anzahl + Grund, PII-frei). --}}
    <div class="budget-omission-note">
        <span class="omission-title">{{ $budgetOmission['count'] }} {{ $budgetOmission['count'] === 1 ? 'Foto' : 'Fotos' }} ausgelassen: {{ $budgetOmission['reason'] }}</span>
    </div>
@endif

<table class="tiles">
    @foreach(array_chunk($tiles, 2) as $chunk)
        <tr>
            @foreach($chunk as $tile)
                <td>
                    <div class="tile-index">Profil {{ $tile['index'] }} von {{ $tile['total'] }}</div>
                    <div class="tile-name">{{ $tile['displayName'] ?? '—' }}</div>
                    <div class="tile-city">{{ $tile['city'] ?? '—' }}</div>
                    @if(! empty($tile['email']))
                        <div class="tile-email">{{ $tile['email'] }}</div>
                    @endif
                    @if(! empty($tile['photo']))
                        <div class="tile-photo">
                            <img src="{{ $tile['photo']['dataUri'] }}" width="{{ $tile['photo']['width'] }}" height="{{ $tile['photo']['height'] }}"><br>
                            @if(! empty($tile['photo']['caption']))
                                <span class="photo-caption">{{ $tile['photo']['caption'] }}</span>
                            @endif
                        </div>
                    @endif
                    @if(! empty($tile['matrix']))
                        <table class="tile-matrix">
                            @foreach($tile['matrix'] as $row)
                                <tr>
                                    <td class="category">{{ $row['category'] }}</td>
                                    <td>{{ $row['experience'] ?? '–' }}</td>
                                    <td>{{ $row['willingness'] ?? '–' }}</td>
                                </tr>
                            @endforeach
                        </table>
                    @endif
                    @if(($tile['omissions']['total'] ?? 0) > 0)
                        <div class="omission-note">
                            <span class="omission-title">{{ $tile['omissions']['title'] }}: {{ $tile['omissions']['total'] }}</span>
                            <ul>
                                @if($tile['omissions']['checkTotal'] > 0)
                                    @foreach($tile['omissions']['checkLines'] as $line)
                                        <li>{{ $line }}</li>
                                    @endforeach
                                @endif
                                @if($tile['omissions']['limitTotal'] > 0)
                                    <li>{{ $tile['omissions']['limitLine'] }}</li>
                                @endif
                            </ul>
                        </div>
                    @endif
                </td>
            @endforeach
            @if(count($chunk) === 1)
                {{-- Leere Zelle hält das 2er-Raster stabil. --}}
                <td></td>
            @endif
        </tr>
    @endforeach
</table>

<div class="footer">
    {{ $brandName }} &middot; Model Contact Sheets ({{ $isExternal ? 'Extern' : 'Intern' }}) &middot; {{ $total }} {{ $total === 1 ? 'Model' : 'Models' }} &middot; erzeugt am {{ $generatedAt->format('d.m.Y H:i') }}
</div>
</body>
</html>
