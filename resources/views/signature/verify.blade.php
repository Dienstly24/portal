@extends('signature._layout')
@section('kopftitel', __('signing.verify_head'))
@section('inhalt')
<div class="karte">
    <h1>{{ __('signing.verify_heading') }}</h1>
    <p class="lead">
        {{ __('signing.verify_intro', ['name' => $signer->name, 'title' => $signature->title, 'email' => $signer->email]) }}
    </p>

    @if(!$codeSent && !session('success'))
    <form method="POST" action="{{ route('signature.code', $token) }}" style="margin-top:18px;">
        @csrf
        <button type="submit" class="knopf">{{ __('signing.verify_send_code') }}</button>
    </form>
    @endif

    <form method="POST" action="{{ route('signature.verify', $token) }}" style="margin-top:18px;">
        @csrf
        <div class="feld">
            <label for="code">{{ __('signing.verify_code_label') }}</label>
            {{-- inputmode/autocomplete: das Telefon zeigt die Zifferntastatur
                 und bietet den Code aus der Mail direkt zum Einsetzen an.
                 dir="ltr" auch auf Arabisch: eine Ziffernfolge wird nie
                 gespiegelt, sonst tippt der Nutzer sie verkehrt herum. --}}
            <input id="code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code"
                   pattern="[0-9]*" maxlength="6" required dir="ltr"
                   style="letter-spacing:8px;font-size:26px;text-align:center;font-weight:700;">
        </div>
        <button type="submit" class="knopf">{{ __('signing.verify_continue') }}</button>
    </form>

    <form method="POST" action="{{ route('signature.code', $token) }}" style="margin-top:12px;">
        @csrf
        <button type="submit" class="knopf knopf-still">{{ __('signing.verify_new_code') }}</button>
    </form>

    <p class="lead" style="margin-top:16px;font-size:13px;">
        {{ __('signing.verify_never_share') }}
    </p>
</div>
@endsection
