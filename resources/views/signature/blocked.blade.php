@extends('signature._layout')
@section('kopftitel', __('signing.brand_subtitle'))
@section('inhalt')
<div class="karte">
    <h1>{{ $completed ? __('signing.blocked_done_title') : __('signing.blocked_nothing_title') }}</h1>
    <p class="lead" style="margin-top:8px;">{{ $reason }}</p>

    @if($completed && $signer->hasSigned())
    <p class="lead" style="margin-top:14px;">
        {{ __('signing.blocked_copy_sent', ['email' => $signer->email]) }}
    </p>
    <p style="margin-top:14px;">
        <a href="{{ route('signature.document', request()->route('token')) }}" target="_blank" rel="noopener">
            {{ __('signing.view_signed_pdf') }}
        </a>
    </p>
    @endif

    <p class="lead" style="margin-top:18px;font-size:13px;">
        {!! __('signing.questions', ['link' => '<a href="'.e(route('support.form')).'">'.e(__('signing.contact_form')).'</a>']) !!}
    </p>
</div>
@endsection
