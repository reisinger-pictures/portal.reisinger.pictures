@extends('emails.layouts.app')

@section('title', $actionText)

@section('preheader', $introText)

@section('content')
    <h2 style="color:#2A9D8F;margin-top:0;font-size:20px;line-height:1.3;">Hallo {{ $userName }},</h2>
    <p style="color:#333333;line-height:1.6;margin-bottom:20px;">{{ $introText }}</p>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
            <td align="center">
                @include('emails.partials.button', ['url' => $actionUrl, 'label' => $actionText])
            </td>
        </tr>
    </table>
@endsection
