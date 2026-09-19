@extends('website.legal-layout')

@section('title', 'Cookie-Richtlinie')

@section('content')
<h1>Cookie-Richtlinie</h1>
{{-- WIE BEI DER DATENSCHUTZERKLAERUNG: diese Seite beschreibt, was
     TATSAECHLICH laeuft, und haengt dafuer an
     `App\Support\Matomo::aktiv()`. Bis zum 19.09.2026 stand hier der
     Satz "ein Cookie-Banner ist nicht erforderlich" - er war richtig,
     solange es keine Messung gab, und waere am Tag der Einrichtung
     still falsch geworden. --}}
<p>Diese Seite listet vollständig auf, was auf <strong>www.dienstly24.de</strong> und im Kundenportal <strong>portal.dienstly24.de</strong> auf Ihrem Gerät gespeichert oder von dort gelesen wird.</p>

<h2>1. Notwendige Cookies – immer aktiv</h2>
<p>Ohne diese funktionieren Login, Formulare und Sprachwahl nicht. Sie enthalten keine Tracking-Merkmale und werden nicht für Werbung oder Profilbildung verwendet. Rechtsgrundlage: § 25 Abs. 2 Nr. 2 TTDSG, Art. 6 Abs. 1 lit. f DSGVO.</p>
<ul>
<li><strong>Sitzungs-Cookie</strong> – hält Ihre Sitzung und merkt sich darin auch, ob Sie die deutsche oder die arabische Fassung nutzen. Läuft mit dem Ende der Sitzung ab.</li>
<li><strong>CSRF-Token</strong> – schützt Formulare gegen missbräuchliches Absenden durch fremde Seiten.</li>
<li><strong>Einwilligungs-Cookie</strong> – speichert ausschließlich Ihre Auswahl aus diesem Hinweis, damit Sie nicht bei jedem Besuch erneut gefragt werden. Gültig 12 Monate, gesetzt auf der gemeinsamen Domain <code>dienstly24.de</code>, damit Ihre Entscheidung für Website und Portal zugleich gilt.</li>
<li><strong>„Angemeldet bleiben“</strong> – nur im Kundenportal und nur, wenn Sie es beim Login selbst auswählen.</li>
</ul>

<h2>2. Analyse / Statistik – optional</h2>
@if(\App\Support\Matomo::aktiv())
<p>Wir messen die Nutzung unserer Website mit <strong>Matomo auf unserem eigenen Server</strong>. Kein Drittanbieter, keine Weitergabe.</p>
<ul>
<li><strong>Setzt keine Cookies</strong> und speichert nichts auf Ihrem Gerät – die Zuordnung eines Besuchs geschieht ohne Kennung.</li>
<li>Ihre <strong>IP-Adresse wird gekürzt</strong> verarbeitet.</li>
<li>Die <strong>„Do Not Track“-Einstellung</strong> Ihres Browsers wird beachtet.</li>
<li>Rohdaten werden nach {{ (int) config('analytics.matomo.log_retention_days', 180) }} Tagen gelöscht.</li>
<li><strong>Ohne Ihre Zustimmung wird die Software gar nicht erst geladen.</strong> Sie können das im Netzwerk-Reiter Ihres Browsers selbst nachprüfen.</li>
</ul>
<p>Rechtsgrundlage: Ihre Einwilligung, Art. 6 Abs. 1 lit. a DSGVO, § 25 Abs. 1 TTDSG.</p>
@else
<p>Derzeit nicht im Einsatz. Sollte eine Reichweitenmessung eingerichtet werden, erfolgt sie auf unserem eigenen Server und erst nach Ihrer Zustimmung; diese Seite wird dann ergänzt.</p>
@endif

<h2>3. Marketing und Werbung</h2>
<p><strong>Nicht im Einsatz.</strong> Es gibt keine Werbe-Netzwerke, kein Retargeting, keine Profilbildung über mehrere Websites hinweg und keine Weitergabe oder Veräußerung von Daten zu Werbezwecken.</p>

<h2>4. Externe Medien und Drittanbieter</h2>
<p><strong>Nicht im Einsatz.</strong> Unsere Seiten laden keine fremden Ressourcen: Schriftarten liegen auf unserem eigenen Server, es gibt keine eingebetteten Karten, Videos oder Social-Media-Bausteine. Links zu Facebook, WhatsApp oder Behördenseiten sind gewöhnliche Verweise – sie werden erst aufgerufen, wenn Sie sie anklicken.</p>

<h2>5. Ihre Auswahl ändern</h2>
<p>Über den Link <strong>„Cookie-Einstellungen“</strong> im Seitenfuß können Sie Ihre Entscheidung jederzeit ansehen, ändern oder widerrufen (Art. 7 Abs. 3 DSGVO). Der Widerruf ist genauso einfach wie die Zustimmung und gilt ab sofort.</p>
<p>Wenn Sie ablehnen, funktionieren Website und Kundenportal vollständig weiter: Sie können sich anmelden, Anfragen senden, Unterlagen hochladen und alle Leistungen nutzen.</p>

<p>Weitere Informationen finden Sie in unserer <a href="/datenschutz">Datenschutzerklärung</a>.</p>
@endsection
