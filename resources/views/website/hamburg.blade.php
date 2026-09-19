@extends('website.layout')

@php
    use App\Support\WebsiteHosts;
    $isAr = app()->getLocale() === 'ar';
    $hSlug = \App\Http\Controllers\WebsiteController::HAMBURG_SLUG;
    $lPrefix = $isAr ? '/ar/leistungen/' : '/leistungen/';
    $addr = config('website.address');
    $telE164 = config('website.phone_e164');
    $telAnzeige = config('website.phone_display');
    $waText = $isAr
        ? 'مرحباً Dienstly24، أريد موعداً في مكتبكم بهامبورغ.'
        : 'Hallo Dienstly24, ich möchte einen Termin in Ihrem Büro in Hamburg.';
    $waLink = 'https://wa.me/'.config('website.whatsapp').'?text='.rawurlencode($waText);

    /*
     * OEFFNUNGSZEITEN AUS DER EINEN QUELLE (`config/website.php`). Sie
     * standen hier als Text und noch einmal im Schema - ein Google-
     * Unternehmensprofil wird gegen genau diese Angabe abgeglichen, und
     * zwei Quellen heissen frueher oder spaeter zwei Antworten.
     */
    $zeiten = config('website.opening_hours');
    $tagKurz = ['Monday' => ['Montag', 'الاثنين'], 'Friday' => ['Freitag', 'الجمعة']];
    $vonTag = $tagKurz[reset($zeiten['tage'])][$isAr ? 1 : 0] ?? '';
    $bisTag = $tagKurz[end($zeiten['tage'])][$isAr ? 1 : 0] ?? '';
    $zeitZeile = $isAr
        ? $vonTag.' – '.$bisTag.'، '.$zeiten['von'].' – '.$zeiten['bis']
        : $vonTag.' bis '.$bisTag.', '.$zeiten['von'].' – '.$zeiten['bis'].' Uhr';

    /*
     * DER RUECKWEG ZUM UNTERNEHMENSPROFIL - nur wenn es WIRKLICH
     * existiert (`WEBSITE_GOOGLE_BUSINESS` in der Server-.env). Ohne
     * Eintrag erscheint der Knopf gar nicht: ein Link auf ein Profil,
     * das es nicht gibt, ist eine falsche Angabe ueber das eigene
     * Unternehmen - dieselbe Regel wie bei `sameAs`.
     */
    $profil = config('website.google_business');
@endphp

@section('title', $isAr
    ? 'وسيط تأمين في هامبورغ | استشارة حضورية بالعربية والألمانية | Dienstly24'
    : 'Versicherungsmakler in Hamburg | Beratung vor Ort | Dienstly24')

@section('description', $isAr
    ? 'استشارة تأمين في هامبورغ – حضورياً في مكتبنا في Furtweg 51a، بالعربية والألمانية. تأمين السيارات والتأمين الصحي وتسجيل السيارات والكهرباء والغاز. مجاناً وبدون التزام.'
    : 'Versicherungsberatung in Hamburg – persönlich in unserem Büro im Furtweg 51a, auf Deutsch und Arabisch. Kfz, Krankenversicherung, Kfz-Zulassung sowie Strom & Gas. Kostenlos und unverbindlich.')

@section('og-title', $isAr ? 'وسيط تأمين في هامبورغ – Dienstly24' : 'Versicherungsmakler in Hamburg – Dienstly24')

@section('head-extra')
{{-- Strukturierte Daten: Aufbau in App\Services\Seo\StructuredData (nie
     als Array in der Vorlage - Blade macht aus "at-context" sonst eine
     eigene Direktive, Audit 15.09.2026).

     HIER IST DER `InsuranceAgency`-BLOCK MIT ANSCHRIFT RICHTIG, waehrend
     er auf den Leistungsseiten bewusst fehlt: Dies ist die Seite UEBER
     DEN STANDORT. Sie beschreibt dasselbe eine Buero wie die Startseite,
     nicht ein zweites - es gibt weiterhin genau eine Anschrift im
     gesamten Auftritt. --}}
{!! \App\Services\Seo\StructuredData::script(\App\Services\Seo\StructuredData::insuranceAgency()) !!}
{!! \App\Services\Seo\StructuredData::script(\App\Services\Seo\StructuredData::breadcrumbList([
    [__('Startseite'), $isAr ? '/ar' : '/'],
    [$isAr ? 'وسيط تأمين في هامبورغ' : 'Versicherungsmakler in Hamburg', ($isAr ? '/ar/' : '/').$hSlug],
])) !!}
@endsection

@section('content')

<section class="services"><div class="container">
  <nav class="krumen-w" aria-label="{{ __('Brotkrumen') }}">
    <a href="{{ $isAr ? '/ar' : '/' }}">{{ __('Startseite') }}</a>
    <span aria-hidden="true">›</span>
    <span aria-current="page">{{ $isAr ? 'هامبورغ' : 'Hamburg' }}</span>
  </nav>

  <div class="section-head">
    <span class="kicker">{{ $isAr ? 'هامبورغ' : 'Hamburg' }}</span>
    <h1 class="title display">{{ $isAr
        ? 'وسيط تأمين في هامبورغ – استشارة حضورية'
        : 'Versicherungsmakler in Hamburg – Beratung vor Ort' }}</h1>
    <p>{{ $isAr
        ? 'مكتبنا في هامبورغ، والاستشارة بالعربية والألمانية. تعالوا شخصياً، أو اتصلوا، أو اكتبوا لنا – كما يناسبكم.'
        : 'Unser Büro steht in Hamburg, beraten wird auf Deutsch und auf Arabisch. Kommen Sie persönlich vorbei, rufen Sie an oder schreiben Sie uns – wie es Ihnen lieber ist.' }}</p>
  </div>

  {{-- EHRLICH AN DER ERSTINFORMATION AUSGERICHTET, genau wie die
       nationale Seite /leistungen/versicherungsmakler: Dienstly24 haelt
       die Erlaubnis nach Paragraph 34d GewO NICHT selbst. Eine
       Ortsseite, die das verschweigt, waere die bequemere - und eine
       falsche Angabe ueber das eigene Unternehmen. --}}
  <p class="hh-hinweis">{{ $isAr
      ? 'ملاحظة: وساطة عقود التأمين تتم عبر وسيط تأمين مرخّص وفق المادة 34d الفقرة 1 من قانون مزاولة الحرف (GewO). وDienstly24 تعمل كوسيط مرتبط تعاقدياً وتحت مسؤوليته – التفاصيل الكاملة في صفحة المعلومات الأولى.'
      : 'Hinweis: Die Vermittlung von Versicherungsverträgen erfolgt über einen zugelassenen Versicherungsmakler mit Erlaubnis nach § 34d Abs. 1 GewO. Dienstly24 tritt dabei als vertraglich gebundener Vermittler unter dessen Haftung auf – alle Angaben dazu stehen in der' }}
      <a href="{{ url('/erstinformation') }}">{{ $isAr ? 'Erstinformation' : 'Erstinformation' }}</a>.</p>

  {{-- Der Standort: die einzigen Angaben, die es nur hier gibt. --}}
  <div class="hh-karten">
    <div class="hh-karte">
      <h2>{{ $isAr ? 'مكتبنا' : 'Unser Büro' }}</h2>
      <p class="hh-adresse">
        <strong>Dienstly24</strong><br>
        {{ $addr['street'] }}<br>
        {{ $addr['zip'] }} {{ $addr['city'] }}
      </p>
      <p class="hh-zeit">{{ $zeitZeile }}</p>
      <p class="hh-klein">{{ $isAr
          ? 'يُفضَّل الاتصال قبل الحضور حتى نخصّص لكم وقتاً كافياً.'
          : 'Am besten kurz anrufen oder schreiben – dann planen wir in Ruhe Zeit für Sie ein.' }}</p>
      <div class="hh-wege">
        <a class="btn btn-primary" href="tel:{{ $telE164 }}" data-cta="telefon" data-cta-seite="hamburg">
          <span dir="ltr">{{ $telAnzeige }}</span>
        </a>
        <a class="btn btn-ghost" href="{{ $waLink }}" target="_blank" rel="noopener"
           data-cta="whatsapp" data-cta-seite="hamburg">WhatsApp</a>
        <a class="btn btn-ghost" href="{{ ($isAr ? '/ar' : '') }}/#kontakt" data-cta="formular" data-cta-seite="hamburg">
          {{ $isAr ? 'نموذج الاتصال' : 'Kontaktformular' }}
        </a>
      </div>
      @if($profil)
        {{-- Zweiter Weg des Zwei-Wege-Links: das Profil verweist auf die
             Website, die Website auf das Profil. Dort stehen Anfahrt,
             Karte und die Bewertungen echter Kunden. --}}
        <p class="hh-klein hh-profil">
          <a href="{{ $profil }}" target="_blank" rel="noopener"
             data-cta="google-profil" data-cta-seite="hamburg">
            {{ $isAr ? 'ملفّنا على خرائط Google – الوصول والتقييمات' : 'Unser Profil bei Google – Anfahrt und Bewertungen' }}
          </a>
        </p>
      @endif
    </div>

    <div class="hh-karte">
      <h2>{{ $isAr ? 'ما الذي يميّز الموعد الحضوري' : 'Was ein Termin vor Ort bringt' }}</h2>
      <ul class="hh-liste">
        <li>{{ $isAr
            ? 'إحضار الأوراق معكم: نصوّرها ونحفظها في ملفكم مباشرة.'
            : 'Unterlagen mitbringen: Wir erfassen sie direkt und legen sie in Ihrer Akte ab.' }}</li>
        <li>{{ $isAr
            ? 'شرح العقود القائمة بندًا بندًا، وما يغطّيه فعلاً وما لا يغطّيه.'
            : 'Bestehende Verträge gemeinsam durchgehen – was wirklich gedeckt ist und was nicht.' }}</li>
        <li>{{ $isAr
            ? 'التوقيع على المستندات في المكتب، أو رقمياً إذا فضّلتم ذلك.'
            : 'Unterschriften im Büro erledigen – oder digital, wenn Ihnen das lieber ist.' }}</li>
        <li>{{ $isAr
            ? 'الاستشارة بالعربية أو بالألمانية، كما يناسبكم.'
            : 'Beratung auf Arabisch oder auf Deutsch – ganz wie Sie möchten.' }}</li>
      </ul>
      <p class="hh-klein">{{ $isAr
          ? 'ولمن هو خارج هامبورغ: الاستشارة نفسها متاحة هاتفياً وأونلاين في كل ألمانيا.'
          : 'Und wer nicht in Hamburg ist: Dieselbe Beratung gibt es deutschlandweit telefonisch und online.' }}</p>
    </div>
  </div>
</div></section>

{{-- Die Leistungen bekommen HIER nur den lokalen Satz und den Link -
     der Fachtext steht auf der jeweiligen Leistungsseite und wird nicht
     ein zweites Mal erzaehlt (Duplicate Content, Auftrag Abschnitt 28). --}}
<section class="ablauf"><div class="container">
  <div class="section-head center">
    <span class="kicker">{{ $isAr ? 'الخدمات في هامبورغ' : 'In Hamburg gefragt' }}</span>
    <h2 class="title display">{{ $isAr ? 'ما نساعدكم فيه هنا' : 'Womit wir hier am häufigsten helfen' }}</h2>
  </div>
  <div class="hh-links">
    <a href="{{ $lPrefix }}kfz-versicherung">
      <strong>{{ $isAr ? 'تأمين السيارات' : 'Kfz-Versicherung' }}</strong>
      <span>{{ $isAr
          ? 'مقارنة التعرفات قبل تسجيل السيارة – ورقم eVB نتكفّل به ضمن الوساطة.'
          : 'Tarife vergleichen, bevor das Auto zugelassen wird – die eVB-Nummer läuft über uns.' }}</span>
    </a>
    <a href="{{ $lPrefix }}kfz-zulassung">
      <strong>{{ $isAr ? 'تسجيل السيارات' : 'Kfz-Zulassung' }}</strong>
      <span>{{ $isAr
          ? 'التسجيل والنقل وإلغاء التسجيل – دون أن تذهبوا بأنفسكم إلى دائرة المرور.'
          : 'An-, Um- und Abmeldung – ohne dass Sie selbst zur Zulassungsstelle müssen.' }}</span>
    </a>
    <a href="{{ $lPrefix }}krankenversicherung">
      <strong>{{ $isAr ? 'التأمين الصحي' : 'Krankenversicherung' }}</strong>
      <span>{{ $isAr
          ? 'قانوني أو خاص – نوضّح الفروق بلغة مفهومة.'
          : 'Gesetzlich oder privat – wir erklären die Unterschiede in verständlicher Sprache.' }}</span>
    </a>
    <a href="{{ $lPrefix }}strom-gas">
      <strong>{{ $isAr ? 'الكهرباء والغاز' : 'Strom & Gas' }}</strong>
      <span>{{ $isAr
          ? 'مراجعة التعرفة عند الانتقال إلى سكن جديد – والإلغاء والتسجيل علينا.'
          : 'Beim Umzug in eine neue Wohnung den Tarif prüfen – Kündigung und Anmeldung übernehmen wir.' }}</span>
    </a>
    <a href="{{ $lPrefix }}versicherungsmakler">
      <strong>{{ $isAr ? 'كيف تعمل الوساطة' : 'Wie die Beratung funktioniert' }}</strong>
      <span>{{ $isAr
          ? 'الفرق بين الوسيط والوكيل، ومعنى توكيل الوساطة، والتكلفة.'
          : 'Makler, Vertreter, Maklervollmacht und was das kostet – ausführlich erklärt.' }}</span>
    </a>
  </div>
</div></section>

<section><div class="container">
  <div class="section-head center">
    <span class="kicker">FAQ</span>
    <h2 class="title display">{{ $isAr ? 'أسئلة عن الموعد في هامبورغ' : 'Fragen zum Termin in Hamburg' }}</h2>
  </div>
  <div class="faq">
    <details>
      <summary>{{ $isAr ? 'هل يمكنني الحضور بدون موعد؟' : 'Kann ich ohne Termin vorbeikommen?' }}</summary>
      <p>{{ $isAr
          ? 'يُفضَّل أن تتصلوا أو تكتبوا لنا قبل الحضور. هكذا نضمن أن يكون هناك شخص متفرّغ لكم ولا تنتظروا.'
          : 'Rufen Sie am besten kurz an oder schreiben Sie uns. So ist jemand für Sie da und Sie warten nicht.' }}</p>
    </details>
    <details>
      <summary>{{ $isAr ? 'ما الأوراق التي أحضرها معي؟' : 'Welche Unterlagen soll ich mitbringen?' }}</summary>
      <p>{{ $isAr
          ? 'ما هو موجود لديكم: البوالص الحالية أو أرقام العقود، وإثبات الهوية، وحسب الموضوع أوراق السيارة أو بيانات المزوّد السابق. إذا نقص شيء نقول لكم ذلك – ولا نخمّن أي بيان.'
          : 'Was da ist: bestehende Policen oder Vertragsnummern, ein Ausweisdokument und – je nach Anliegen – Fahrzeugpapiere oder Angaben zum bisherigen Anbieter. Fehlt etwas, sagen wir Ihnen das; geraten wird nichts.' }}</p>
    </details>
    <details>
      <summary>{{ $isAr ? 'هل الاستشارة بالعربية متاحة فعلاً؟' : 'Wird wirklich auch auf Arabisch beraten?' }}</summary>
      <p>{{ $isAr
          ? 'نعم. الاستشارة بالألمانية وبالعربية، وأنتم تختارون اللغة الأسهل عليكم.'
          : 'Ja. Wir beraten auf Deutsch und auf Arabisch – Sie wählen, was Ihnen leichter fällt.' }}</p>
    </details>
    <details>
      <summary>{{ $isAr ? 'هل تعملون خارج هامبورغ أيضاً؟' : 'Beraten Sie auch außerhalb von Hamburg?' }}</summary>
      <p>{{ $isAr
          ? 'نعم، في كل ألمانيا هاتفياً وأونلاين. المكتب في هامبورغ إضافة لمن يفضّل اللقاء الشخصي.'
          : 'Ja, deutschlandweit telefonisch und online. Das Büro in Hamburg ist das Zusätzliche für alle, die das persönliche Gespräch möchten.' }}</p>
    </details>
    <details>
      <summary>{{ $isAr ? 'قديش بتكلّف الاستشارة؟' : 'Was kostet die Beratung?' }}</summary>
      <p>{{ $isAr
          ? 'لا شيء عليكم. أجر الوساطة (Courtage) تدفعه شركة التأمين وهو أصلاً ضمن القسط. التفاصيل في صفحة المعلومات الأولى.'
          : 'Für Sie nichts. Die Vergütung erfolgt über eine Courtage, die die Versicherungsgesellschaft zahlt und die im Beitrag bereits enthalten ist. Einzelheiten stehen in der Erstinformation.' }}</p>
    </details>
  </div>
</div></section>

<style>
.krumen-w{display:flex;flex-wrap:wrap;gap:6px;align-items:center;font-size:.85rem;color:var(--muted);margin-bottom:18px;}
.krumen-w a{color:var(--muted);text-decoration:none;}
.krumen-w a:hover{text-decoration:underline;}
.hh-hinweis{font-size:.86rem;line-height:1.65;color:var(--muted);max-width:820px;margin:0 0 26px;}
.hh-karten{display:grid;grid-template-columns:1fr 1fr;gap:20px;}
@media(max-width:820px){.hh-karten{grid-template-columns:1fr;}}
.hh-karte{border:1px solid var(--line);border-radius:18px;padding:24px;background:var(--card);}
.hh-karte h2{font-size:1.05rem;margin:0 0 12px;}
.hh-adresse{font-size:1rem;line-height:1.7;margin:0 0 6px;}
.hh-zeit{font-size:.92rem;font-weight:600;margin:0 0 6px;}
.hh-klein{font-size:.85rem;color:var(--muted);line-height:1.6;margin:8px 0 0;}
.hh-wege{display:flex;flex-wrap:wrap;gap:10px;margin-top:16px;}
.hh-wege .btn{min-height:44px;}
.hh-liste{margin:0;padding-inline-start:20px;display:grid;gap:9px;font-size:.93rem;line-height:1.6;}
.hh-links{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;}
@media(max-width:860px){.hh-links{grid-template-columns:1fr 1fr;}}
@media(max-width:560px){.hh-links{grid-template-columns:1fr;}}
.hh-links a{display:block;padding:18px;border:1px solid var(--line);border-radius:16px;background:var(--card);text-decoration:none;color:inherit;transition:border-color .2s,transform .2s;}
.hh-links a:hover{border-color:var(--emerald);transform:translateY(-3px);}
.hh-links strong{display:block;font-size:.98rem;margin-bottom:5px;}
.hh-links span{font-size:.85rem;color:var(--muted);line-height:1.55;}
</style>
@endsection
