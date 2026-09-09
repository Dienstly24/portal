@extends('signature._layout')
@section('kopftitel', 'E-Mail bestätigen')
@section('inhalt')
<div class="karte">
    <h1>Kurze Bestätigung 🔐</h1>
    <p class="lead">
        Guten Tag {{ $signer->name }}, bevor wir Ihnen das Dokument
        „{{ $signature->title }}“ zeigen, bestätigen wir kurz, dass Sie Zugriff auf
        <strong>{{ $signer->email }}</strong> haben.
    </p>

    @if(!$codeSent && !session('success'))
    <form method="POST" action="{{ route('signature.code', $token) }}" style="margin-top:18px;">
        @csrf
        <button type="submit" class="knopf">Bestätigungscode senden</button>
    </form>
    @endif

    <form method="POST" action="{{ route('signature.verify', $token) }}" style="margin-top:18px;">
        @csrf
        <div class="feld">
            <label for="code">Sechsstelliger Code aus der E-Mail</label>
            {{-- inputmode/autocomplete: das Telefon zeigt die Zifferntastatur
                 und bietet den Code aus der Mail direkt zum Einsetzen an. --}}
            <input id="code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code"
                   pattern="[0-9]*" maxlength="6" required
                   style="letter-spacing:8px;font-size:26px;text-align:center;font-weight:700;">
        </div>
        <button type="submit" class="knopf">Weiter zum Dokument</button>
    </form>

    <form method="POST" action="{{ route('signature.code', $token) }}" style="margin-top:12px;">
        @csrf
        <button type="submit" class="knopf knopf-still">Neuen Code anfordern</button>
    </form>

    <p class="lead" style="margin-top:16px;font-size:13px;">
        Der Code gilt 30 Minuten. Bitte geben Sie ihn niemals weiter – auch nicht an Mitarbeitende von Dienstly24.
    </p>
</div>
@endsection
