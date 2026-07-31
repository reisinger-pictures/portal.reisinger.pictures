@extends('emails.layouts.app')

@section('title', 'SMTP Test')

@section('preheader', 'Reisinger Portal – SMTP-Verbindungstest')

@section('content')
    <h2 style="margin:0 0 16px 0;font-size:20px;line-height:1.3;color:#333333;">SMTP-Verbindungstest</h2>
    <p>Diese E-Mail wurde manuell über den Super-Admin-Bereich des Portals versendet.</p>
    <p><strong>Empfänger:</strong> {{ $recipient }}<br />
    <strong>Mailer:</strong> {{ $mailer }}<br />
    <strong>Zeitpunkt:</strong> {{ now()->format('d.m.Y H:i:s') }}</p>
    <p>Wenn du diese Nachricht erhältst, ist der E-Mail-Versand korrekt konfiguriert.</p>
@endsection
