@extends('emails.layouts.app')

@section('title', 'Benachrichtigung')

@section('preheader', $mailSubject)

@section('content')
    <h2 style="color:#2A9D8F;margin-top:0;font-size:20px;line-height:1.3;">Hallo {{ $userName }},</h2>
    <div style="color:#333333;line-height:1.6;margin-bottom:20px;">{!! $messageBody !!}</div>
@endsection
