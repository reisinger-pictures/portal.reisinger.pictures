@extends('emails.layouts.app')

@section('title', $subject)

@section('preheader', 'Ihre Nachricht vom Reisinger Foto Portal')

@section('content')
    <div>{!! $customBody !!}</div>
@endsection
