@php
    use App\Support\GoogleBewertung;

    /*
     | VERTRAUENS-KARTE ZUM GOOGLE-UNTERNEHMENSPROFIL.
     |
     | Bewusst KEINE eingebettete Karte (Betreiber-Wunsch 19.09.2026
     | "die Kunden sollen den Eintrag sehen" - aber NICHT vorbeikommen):
     |   1. Ein Google-Kartenrahmen sendet die IP JEDES Besuchers an
     |      Google, bevor er zugestimmt hat - dieselbe Klasse
     |      Fremdzugriff wie die Google-Schriften, die deshalb lokal
     |      liegen ("Website-Seiten laden NIE externe Ressourcen").
     |   2. Er verlangt `frame-src`/`script-src` fuer Google-Hosts und
     |      nimmt damit einen Teil der SEC-4-Haertung zurueck.
     |   3. Er braucht eine dritte Einwilligungs-Kategorie - wer
     |      ablehnt, saehe gar nichts.
     |   4. Und er sagt "komm vorbei", waehrend der Betrieb
     |      ausschliesslich online arbeitet.
     |
     | Was Vertrauen wirklich traegt, sind die BEWERTUNGEN. Die holt der
     | Server im Hintergrund; hier wird nur gelesen.
     |
     | OHNE PROFIL-LINK ERSCHEINT NICHTS - ein Vertrauenshinweis ohne
     | Ziel ist keiner. Ohne abgerufene Zahlen erscheint die Karte ohne
     | Zahlen: erfunden wird nie eine.
     */
    $profil = GoogleBewertung::profilUrl();
    $stand = $profil ? GoogleBewertung::stand() : null;
    $seite = $seite ?? 'unbekannt';
@endphp

@if($profil)
<div class="gtrust">
  <div class="gtrust-kopf">
    <span class="gtrust-mark" aria-hidden="true">G</span>
    <strong>{{ $isAr ? 'موجودون على Google' : 'Sie finden uns bei Google' }}</strong>
  </div>

  @if($stand)
    @php $sterne = GoogleBewertung::sterne($stand['rating']); @endphp
    <p class="gtrust-note">
      <span class="gtrust-sterne" role="img"
            aria-label="{{ $isAr
                ? 'التقييم '.number_format($stand['rating'], 1).' من 5'
                : 'Bewertung '.number_format($stand['rating'], 1, ',', '.').' von 5' }}">
        @foreach($sterne as $stern)
          <span class="gs gs-{{ $stern }}" aria-hidden="true">★</span>
        @endforeach
      </span>
      <span class="gtrust-zahl" dir="ltr">{{ number_format($stand['rating'], 1, ',', '.') }}</span>
      <span class="gtrust-anzahl">{{ $isAr
          ? '('.$stand['anzahl'].' تقييماً)'
          : '('.$stand['anzahl'].' '.($stand['anzahl'] === 1 ? 'Bewertung' : 'Bewertungen').')' }}</span>
    </p>
  @endif

  {{-- Der Satz, der die Karte vom Gegenteil trennt: sichtbar sein,
       ohne zum Besuch einzuladen. --}}
  <p class="gtrust-note">{{ $isAr
      ? 'ملفّنا مؤكَّد على Google – لكن العمل كلّه أونلاين: لا حاجة لزيارة أي مكتب.'
      : 'Unser Profil bei Google ist bestätigt – gearbeitet wird trotzdem vollständig online. Sie müssen nirgendwo hinkommen.' }}</p>

  <a class="btn btn-ghost-light gtrust-btn" href="{{ $profil }}" target="_blank" rel="noopener"
     data-cta="google-profil" data-cta-seite="{{ $seite }}">
    {{ $isAr ? 'اقرأوا آراء العملاء على Google' : 'Bewertungen bei Google lesen' }}
  </a>

  @if($stand)
    {{-- Pflicht-Quellenangabe: wer Places-Daten ausserhalb einer
         Google-Karte anzeigt, muss die Herkunft nennen. Als TEXT, nicht
         als Logo von einem Google-Server - sonst waere genau der
         Fremdzugriff wieder da, den diese Karte vermeidet. --}}
    <p class="gtrust-quelle">{{ $isAr ? 'التقييمات من Google' : 'Bewertungen von Google' }}</p>
  @endif
</div>
{{-- Das Website-Layout hat KEINEN styles-Stack. Ein @push liefe hier
     ins Leere - ohne Fehlermeldung, die Karte waere nur unformatiert
     (dieselbe Falle wie @push nach @stack, SEC-4). Der Block steht
     deshalb direkt hier, @once verhindert die Dopplung, wenn die Karte
     auf einer Seite zweimal eingebunden wird. --}}
@once
<style>
/* Bausteine der Vertrauens-Karte. Farben ausschliesslich ueber die
   Marken-Tokens (brand.css / site.css) - GOLD ist hier richtig, weil die
   Sterne ein AKZENT sind und keine Aktion; Smaragd bleibt der
   Aktionsfarbe vorbehalten. */
.gtrust{border:1px solid var(--line);border-radius:16px;padding:18px 20px;background:var(--card);margin-top:22px;}
.gtrust-kopf{display:flex;align-items:center;gap:10px;margin-bottom:10px;}
.gtrust-kopf strong{font-size:.98rem;}
.gtrust-mark{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:50%;
  border:1px solid var(--line);font-weight:700;font-size:.9rem;flex:none;}
.gtrust-sterne{letter-spacing:1px;}
.gs{color:var(--line);}
.gs-voll{color:var(--gold);}
/* Halber Stern ohne zweites Zeichen: derselbe Stern, zur Haelfte in der
   Akzentfarbe. So kann die Anzeige nicht von der Zahl abweichen. */
.gs-halb{background:linear-gradient(90deg,var(--gold) 50%,var(--line) 50%);
  -webkit-background-clip:text;background-clip:text;color:transparent;}
[dir="rtl"] .gs-halb{background:linear-gradient(270deg,var(--gold) 50%,var(--line) 50%);
  -webkit-background-clip:text;background-clip:text;}
.gtrust-zahl{font-weight:700;margin-inline-start:6px;}
.gtrust-anzahl{color:var(--muted);font-size:.88rem;margin-inline-start:4px;}
.gtrust-note{font-size:.88rem;color:var(--muted);line-height:1.6;margin:8px 0 0;}
.gtrust-btn{margin-top:14px;min-height:44px;}
.gtrust-quelle{font-size:.75rem;color:var(--muted);margin:10px 0 0;}
</style>
@endonce
@endif

