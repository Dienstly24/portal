@extends('signature._layout')
@section('kopftitel', 'Elektronische Unterschrift')
@section('inhalt')
<div class="karte">
    <h1>{{ $completed ? 'Dieses Dokument ist fertig ✅' : 'Hier ist gerade nichts zu tun' }}</h1>
    <p class="lead" style="margin-top:8px;">{{ $reason }}</p>

    @if($completed && $signer->hasSigned())
    <p class="lead" style="margin-top:14px;">
        Ihre Kopie haben wir Ihnen per E-Mail an {{ $signer->email }} gesendet.
    </p>
    <p style="margin-top:14px;">
        <a href="{{ route('signature.document', request()->route('token')) }}" target="_blank" rel="noopener">
            Unterschriebenes PDF ansehen
        </a>
    </p>
    @endif

    <p class="lead" style="margin-top:18px;font-size:13px;">
        Fragen dazu? Schreiben Sie uns über <a href="{{ route('support.form') }}">unser Kontaktformular</a> –
        bitte mit dem Titel des Dokuments.
    </p>
</div>
@endsection
