@extends('emails.layouts.app')

@section('title', 'Auswahl abgeschlossen')

@section('preheader', 'Der Kunde ' . $clientName . ' hat die Auswahl in der Galerie ' . $galleryName . ' abgeschlossen.')

@section('content')
    <h2 style="color:#2A9D8F;margin-top:0;font-size:20px;line-height:1.3;">Hallo {{ $notifiedUserName }},</h2>
    <p style="color:#333333;line-height:1.6;margin-bottom:20px;">Der Kunde <b>{{ $clientName }}</b> ({{ $clientEmail }}) hat die Auswahl in der Galerie <b>{{ $galleryName }}</b> soeben abgeschlossen.</p>
@endsection
