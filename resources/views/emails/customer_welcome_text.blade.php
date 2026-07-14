@php
    $hello = 'Hallo';
    if ($customer->gender === 'male') { $hello = 'Hallo Herr ' . (\Illuminate\Support\Str::afterLast(trim($customerName), ' ') ?: $customerName); }
    elseif ($customer->gender === 'female') { $hello = 'Hallo Frau ' . (\Illuminate\Support\Str::afterLast(trim($customerName), ' ') ?: $customerName); }
    elseif (trim($customerName) !== '') { $hello = 'Hallo ' . $customerName; }
@endphp
Willkommen bei Dienstly24
=========================

{{ $hello }},
Ihr Kundenportal ist jetzt bereit - Ihr direkter Draht zu Vertraegen,
Dokumenten und zu uns.
@if($magicLoginUrl)

JETZT AUTOMATISCH ANMELDEN
Ein Klick genuegt, 90 Tage gueltig, nur mit dieser E-Mail-Adresse.
Danach koennen Sie ein eigenes Passwort festlegen:
{!! $magicLoginUrl !!}
@endif

IHRE ZUGANGSDATEN
-----------------
Portal:  {{ $portalBase }}
E-Mail:  {{ $loginEmail }}
@if($mode === 'birthdate')
Passwort: Ihr Geburtsdatum im Format TT.MM.JJJJ (Beispiel: 01.01.1990)
@elseif($mode === 'setlink')
Passwort festlegen: {!! $setPasswordUrl !!}
@else
Startpasswort: {{ $plainPassword }}
@endif

WAS KOENNEN SIE IM PORTAL TUN?
------------------------------
- Vertraege:   {{ $portalBase }}/contracts
- Dokumente:   {{ $portalBase }}/documents
- Support:     {{ $portalBase }}/tickets/create
- Meine Daten: {{ $portalBase }}/profile

BRAUCHEN SIE HILFE?
-------------------
Unser Team hilft Ihnen gerne: info@dienstly24.de
Anfrage senden: {!! $supportUrl !!}

SICHERHEIT: Wir fragen niemals per E-Mail oder Telefon nach Ihrem Passwort.
Passwort vergessen? Nutzen Sie "Passwort vergessen" auf der Login-Seite.

--
Dienstly24 - Ihr Partner fuer Finanzdienstleistungen
Website:     https://dienstly24.de
Impressum:   {{ $portalBase }}/impressum
Datenschutz: {{ $portalBase }}/datenschutz

Diese E-Mail wurde automatisch versendet.
