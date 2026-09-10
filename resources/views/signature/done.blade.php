@extends('signature._layout')
@section('kopftitel', $declined ? __('signing.done_head_declined') : __('signing.done_head_signed'))
@section('inhalt')
<div class="karte" style="text-align:center;">
    <div style="font-size:46px;line-height:1;margin-bottom:10px;">{{ $declined ? '✋' : '✅' }}</div>
    <h1>{{ $declined ? __('signing.done_declined_title') : __('signing.done_head_signed') }}</h1>
    <p class="lead" style="margin-top:8px;">
        @if($declined)
            {{ __('signing.done_declined_body', ['title' => $signature->title]) }}
        @elseif($signature->isCompleted())
            {{ __('signing.done_completed_body', ['title' => $signature->title, 'email' => $signer->email]) }}
        @else
            {{ __('signing.done_partial_body', ['email' => $signer->email]) }}
        @endif
    </p>

    @if(!$declined && $signature->isCompleted())
    <p style="margin-top:18px;">
        <a class="knopf" href="{{ route('signature.document', $token) }}" target="_blank" rel="noopener"
           style="text-decoration:none;">{{ __('signing.download') }}</a>
    </p>
    <p class="lead" style="margin-top:16px;font-size:12.5px;word-break:break-all;">
        {{ __('signing.checksum', ['hash' => $signature->signed_hash]) }}
    </p>
    @endif

    <p class="lead" style="margin-top:20px;font-size:13px;">
        {{ __('signing.close_window') }}
    </p>
</div>
@endsection
