@extends('website.legal-layout')

@section('title', 'Datenschutzerklärung')

@section('content')
<!-- WICHTIG: Vor Livegang von einem Datenschutzbeauftragten/Rechtsanwalt prüfen und vervollständigen lassen. -->
<h1>Datenschutzerklärung</h1>
<h2>1. Verantwortlicher</h2>
<div class="card"><p><strong>Dienstly24</strong><br>Ahmad Albhre<br>Furtweg 51a, 22523 Hamburg<br>E-Mail: <a href="mailto:info@dienstly24.de">info@dienstly24.de</a><br>Telefon: <a href="tel:+491799673909">+49 179 9673909</a></p></div>
<h2>2. Erhebung und Speicherung personenbezogener Daten</h2>
<p>Beim Besuch dieser Website erfasst unser Hosting-Anbieter automatisch bestimmte technische Informationen (z.&nbsp;B. IP-Adresse, Datum und Uhrzeit des Zugriffs, aufgerufene Seite, verwendeter Browser).</p>
<!-- TODO: Tatsächlichen Hoster (Hostinger) benennen und dessen Datenschutzhinweise verlinken. -->
<h2>3. Kontaktformular</h2>
<p>Wenn Sie uns über das Kontaktformular eine Anfrage senden, werden Ihre Angaben aus dem Formular (Name, Kontaktdaten, gewünschte Leistung, Nachricht) zur Bearbeitung Ihrer Anfrage und für den Fall von Anschlussfragen in unserem Kundenverwaltungssystem gespeichert; Sie erhalten eine Eingangsbestätigung per E-Mail, sofern Sie eine E-Mail-Adresse angegeben haben. Diese Daten geben wir nicht ohne Ihre Einwilligung weiter.</p>
<p><strong>Nachweis Ihrer Einwilligung:</strong> Zum Nachweis nach Art. 7 Abs. 1 DSGVO speichern wir beim Absenden den Zeitpunkt, Ihre IP-Adresse und den Wortlaut der Einwilligungserklärung, der Sie zugestimmt haben.</p>
<p><strong>Speicherdauer:</strong> Anfragen, aus denen keine Kundenbeziehung entsteht, werden nach 6 Monaten automatisch gelöscht.</p>
<h2>4. WhatsApp-Kontakt</h2>
<p>Wenn Sie uns über den WhatsApp-Button kontaktieren, gelten zusätzlich die Datenschutzhinweise von WhatsApp (Meta). Die Nachrichten werden auf Ihrem Gerät über die WhatsApp-App versendet.</p>
<h2>5. Weitergabe von Daten bei Versicherungsanfragen</h2>
<p>Betrifft Ihre Anfrage die Vermittlung eines Versicherungsvertrags, geben wir die dafür erforderlichen Angaben an NESA Versicherung und Finanzen (Nour Eddin Sheikh Alsouk, Stresemannstr. 12, 58095 Hagen) weiter, in deren Auftrag und unter deren Haftung die Versicherungsvermittlung erfolgt, sowie ggf. an das jeweilige Versicherungsunternehmen, um Ihnen ein passendes Angebot erstellen zu können.</p>
<h2>6. Cookies und Einwilligung</h2>
{{-- DIESER ABSCHNITT BESCHREIBT, WAS TATSAECHLICH LAEUFT.
     Er haengt an `App\Support\Matomo::aktiv()` - ist die Messung auf
     dem Server nicht eingerichtet, steht hier auch nicht, dass gemessen
     wird. Eine Datenschutzerklaerung, die einen Dienst nennt, den es
     nicht gibt, ist genauso falsch wie eine, die einen verschweigt; und
     eine, die von Hand nachgezogen werden muss, wird es irgendwann
     nicht mehr. --}}
<p><strong>Notwendige Cookies.</strong> Für den Betrieb von Website und Portal setzen wir technisch notwendige Cookies: das Sitzungs-Cookie (in ihm steht auch, ob Sie die deutsche oder die arabische Fassung nutzen), den Schutz vor Formularmissbrauch (CSRF-Token) sowie ein Cookie, das Ihre Entscheidung aus dem Cookie-Hinweis speichert. Rechtsgrundlage: § 25 Abs. 2 Nr. 2 TTDSG, Art. 6 Abs. 1 lit. f DSGVO. Diese Cookies enthalten keine Tracking-Merkmale.</p>
<p><strong>Ihre Entscheidung gilt für beide Adressen.</strong> Das Einwilligungs-Cookie wird auf der gemeinsamen Domain <code>dienstly24.de</code> gesetzt, damit Sie auf <code>www.dienstly24.de</code> und im Kundenportal <code>portal.dienstly24.de</code> nur einmal gefragt werden. Es wird 12 Monate gespeichert und enthält ausschließlich Ihre Auswahl – keine Kennung, mit der Sie wiedererkannt werden könnten.</p>
<p><strong>Widerruf jederzeit:</strong> Über den Link „Cookie-Einstellungen“ im Seitenfuß können Sie Ihre Auswahl jederzeit ändern oder widerrufen (Art. 7 Abs. 3 DSGVO). Der Widerruf ist genauso einfach wie die Zustimmung.</p>
@if(\App\Support\Matomo::aktiv())
<p><strong>Reichweitenmessung mit Matomo.</strong> Zur Auswertung der Nutzung unserer Website setzen wir Matomo ein – eine Analyse-Software, die wir <em>auf unserem eigenen Server betreiben</em>. Es werden keine Daten an Dritte übertragen und kein externer Analyse-Dienst eingebunden.</p>
<ul>
<li>Die Messung setzt <strong>keine Cookies</strong> und legt nichts auf Ihrem Endgerät ab.</li>
<li>Ihre <strong>IP-Adresse wird gekürzt</strong> gespeichert und ist damit nicht mehr einer Person zuzuordnen.</li>
<li>Eine <strong>„Do Not Track“-Einstellung</strong> Ihres Browsers wird beachtet.</li>
<li>Erfasst werden: aufgerufene Seiten, Verweildauer, ungefähre Herkunftsregion, Gerätetyp und Browser, die verweisende Seite sowie Klicks auf unsere Kontaktmöglichkeiten (Telefon, WhatsApp, Kontaktformular, Kundenportal). <strong>Eingaben aus Formularen werden nicht übertragen.</strong></li>
<li>Es findet <strong>keine Profilbildung</strong>, keine Werbe-Auswertung und keine Zusammenführung mit anderen Daten statt.</li>
<li>Die Rohdaten werden nach {{ (int) config('analytics.matomo.log_retention_days', 180) }} Tagen gelöscht; erhalten bleiben nur zusammengefasste Statistiken ohne Personenbezug.</li>
</ul>
<p>Rechtsgrundlage ist Ihre Einwilligung nach Art. 6 Abs. 1 lit. a DSGVO und § 25 Abs. 1 TTDSG. <strong>Ohne Ihre Zustimmung findet keine Messung statt</strong> – die Analyse-Software wird dann gar nicht erst geladen.</p>
@else
<p><strong>Analyse und Statistik.</strong> Derzeit ist keine Reichweitenmessung im Einsatz. Sollte sie eingerichtet werden, geschieht dies mit einer Software auf unserem eigenen Server und erst nach Ihrer Zustimmung über den Cookie-Hinweis; diese Erklärung wird dann entsprechend ergänzt.</p>
@endif
<p><strong>Keine Werbe- oder Drittanbieterdienste.</strong> Es sind keine Werbe-Netzwerke, keine Profilbildung über mehrere Websites hinweg und keine Weitergabe oder Veräußerung von Daten zu Werbezwecken im Einsatz.</p>
<p>Eine vollständige Aufstellung finden Sie in der <a href="/cookie-richtlinie">Cookie-Richtlinie</a>.</p>
<p><strong>Schriftarten:</strong> Alle Schriftarten dieser Website werden lokal von unserem eigenen Server geladen. Es findet keine Verbindung zu Servern von Google (Google Fonts) oder anderen Drittanbietern statt; Ihre IP-Adresse wird dadurch an keinen Schriften-Dienst übertragen.</p>
<h2>7. Ihre Rechte</h2>
<p>Sie haben jederzeit das Recht auf Auskunft, Berichtigung, Löschung oder Einschränkung der Verarbeitung Ihrer bei uns gespeicherten personenbezogenen Daten sowie ein Recht auf Datenübertragbarkeit und Widerspruch. Wenden Sie sich hierzu an: <a href="mailto:info@dienstly24.de">info@dienstly24.de</a></p>
<h2>8. Beschwerderecht</h2>
<p>Sie haben das Recht, sich bei einer Datenschutz-Aufsichtsbehörde über die Verarbeitung Ihrer personenbezogenen Daten durch uns zu beschweren.</p>
@endsection
