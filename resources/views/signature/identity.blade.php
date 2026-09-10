@extends('signature._layout')
@section('kopftitel', __('signing.dob_title'))
@section('inhalt')
<div class="karte">
    <h1>{{ __('signing.dob_title') }}</h1>
    <p class="lead">{{ __('signing.dob_lead') }}</p>

    @if($blocked)
    <div class="hinweis hinweis-warn" style="margin-top:16px;">{{ __('signing.dob_blocked') }}</div>
    @else
    <form method="POST" action="{{ route('signature.identity', $token) }}" style="margin-top:18px;">
        @csrf
        <div class="feld">
            <label for="geburtsdatum">{{ __('signing.dob_label') }}</label>
            {{-- dir="ltr" auch auf Arabisch: ein Datum ist eine Ziffernfolge
                 und wird nie gespiegelt. inputmode/placeholder zeigen die
                 erwartete Form, ohne sie zu erzwingen - der Server nimmt
                 mehrere Schreibweisen an. --}}
            <input id="geburtsdatum" type="text" name="geburtsdatum" required dir="ltr"
                   inputmode="numeric" autocomplete="bday" maxlength="20" placeholder="TT.MM.JJJJ"
                   style="font-size:20px;text-align:center;letter-spacing:1px;">
        </div>
        <button type="submit" class="knopf">{{ __('signing.dob_submit') }}</button>
    </form>
    @endif

    <p class="lead" style="margin-top:16px;font-size:13px;">
        {!! __('signing.questions', ['link' => '<a href="'.e(route('support.form')).'">'.e(__('signing.contact_form')).'</a>']) !!}
    </p>
</div>
@endsection
