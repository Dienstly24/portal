@extends('signature._layout')
@section('kopftitel', $declined ? 'Abgelehnt' : 'Erfolgreich unterschrieben')
@section('inhalt')
<div class="karte" style="text-align:center;">
    <div style="font-size:46px;line-height:1;margin-bottom:10px;">{{ $declined ? '✋' : '✅' }}</div>
    <h1>{{ $declined ? 'Sie haben die Unterschrift abgelehnt' : 'Erfolgreich unterschrieben' }}</h1>
    <p class="lead" style="margin-top:8px;">
        @if($declined)
            Wir haben Ihre Rückmeldung zu „{{ $signature->title }}“ erhalten und an Ihren Ansprechpartner
            weitergeleitet. Sie brauchen nichts weiter zu tun.
        @elseif($signature->isCompleted())
            Vielen Dank. Das Dokument „{{ $signature->title }}“ ist vollständig unterschrieben.
            Ihre Kopie geht an {{ $signer->email }}.
        @else
            Vielen Dank – Ihre Unterschrift ist gespeichert. Sobald auch die übrigen Unterzeichner
            unterschrieben haben, senden wir Ihnen das fertige Dokument an {{ $signer->email }}.
        @endif
    </p>

    @if(!$declined && $signature->isCompleted())
    <p style="margin-top:18px;">
        <a class="knopf" href="{{ route('signature.document', $token) }}" target="_blank" rel="noopener"
           style="text-decoration:none;">Unterschriebenes PDF ansehen</a>
    </p>
    <p class="lead" style="margin-top:16px;font-size:12.5px;word-break:break-all;">
        Prüfsumme (SHA-256): {{ $signature->signed_hash }}
    </p>
    @endif

    <p class="lead" style="margin-top:20px;font-size:13px;">
        Sie können dieses Fenster jetzt schließen.
    </p>
</div>
@endsection
