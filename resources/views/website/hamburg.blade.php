@extends('website.layout')

@php
    use App\Support\WebsiteHosts;
    $isAr = app()->getLocale() === 'ar';
    $hSlug = \App\Http\Controllers\WebsiteController::HAMBURG_SLUG;
    $lPrefix = $isAr ? '/ar/leistungen/' : '/leistungen/';
    $telE164 = config('website.phone_e164');
    $telAnzeige = config('website.phone_display');
    $waText = $isAr
        ? 'مرحباً Dienstly24، أريد استشارة. أنا في هامبورغ.'
        : 'Hallo Dienstly24, ich haette gern eine Beratung. Ich bin aus Hamburg.';
    $waLink = 'https://wa.me/'.config('website.whatsapp').'?text='.rawurlencode($waText);

    /*
     * OEFFNUNGSZEITEN AUS DER EINEN QUELLE (`config/website.php`). Sie
     * standen hier als Text und noch einmal im Schema - ein Google-
     * Unternehmensprofil wird gegen genau diese Angabe abgeglichen, und
     * zwei Quellen heissen frueher oder spaeter zwei Antworten.
     */
    $zeitZeile = \App\Support\Erreichbarkeit::zeile($isAr);

@endphp

@section('title', $isAr
    ? 'وسيط تأمين في هامبورغ | كل شيء أونلاين بالعربية والألمانية | Dienstly24'
    : 'Versicherungsmakler in Hamburg | komplett online | Dienstly24')

@section('description', $isAr
    ? 'وسيط تأمين مقرّه هامبورغ – كل شيء أونلاين، بالعربية والألمانية. تأمين السيارات والتأمين الصحي وتسجيل السيارات والكهرباء والغاز. لا داعي للحضور إلى أي مكتب. مجاناً وبدون التزام.'
    : 'Versicherungsmakler mit Sitz in Hamburg – komplett online, auf Deutsch und Arabisch. Kfz, Krankenversicherung, Kfz-Zulassung sowie Strom & Gas. Sie müssen nirgendwo hinkommen. Kostenlos und unverbindlich.')

@section('og-title', $isAr ? 'وسيط تأمين في هامبورغ – كل شيء أونلاين' : 'Versicherungsmakler in Hamburg – komplett online')

@section('head-extra')
{{-- Strukturierte Daten: Aufbau in App\Services\Seo\StructuredData (nie
     als Array in der Vorlage - Blade macht aus "at-context" sonst eine
     eigene Direktive, Audit 15.09.2026).

     HIER IST DER `InsuranceAgency`-BLOCK RICHTIG, waehrend er auf den
     Leistungsseiten bewusst fehlt: dies ist die Seite ueber den SITZ des
     Betriebs. Sie beschreibt denselben einen Betrieb wie die Startseite,
     nicht einen zweiten - es gibt weiterhin genau eine Anschrift im
     gesamten Auftritt.

     KEIN BESUCHSBETRIEB (Betreiber-Klarstellung 19.09.2026): gearbeitet
     wird ausschliesslich online. Die Zeiten sind ERREICHBARKEITS-Zeiten,
     keine Einladung vorbeizukommen; die Seite sagt das ausdruecklich. --}}
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
        ? 'وسيط تأمين في هامبورغ – كل شيء أونلاين'
        : 'Versicherungsmakler in Hamburg – komplett online' }}</h1>
    <p>{{ $isAr
        ? 'مقرّنا في هامبورغ، والعمل كلّه أونلاين: هاتف، واتساب، وبوابة العملاء – بالعربية والألمانية. لا تحتاجون للحضور إلى أي مكتب، ولا حتى لتسجيل السيارة.'
        : 'Unser Sitz ist in Hamburg, gearbeitet wird vollständig online: Telefon, WhatsApp und Kundenportal – auf Deutsch und auf Arabisch. Sie müssen zu keinem Termin erscheinen, auch nicht für die Kfz-Zulassung.' }}</p>
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
      <h2>{{ $isAr ? 'كيف تصلون إلينا' : 'So erreichen Sie uns' }}</h2>
      <p class="hh-zeit">{{ $zeitZeile }}</p>
      <p class="hh-klein">{{ $isAr
          ? 'نردّ خلال ساعات العمل. خارجها اكتبوا لنا وسنعاود الاتصال.'
          : 'In dieser Zeit sind wir erreichbar. Ausserhalb schreiben Sie uns einfach – wir melden uns zurück.' }}</p>
      <div class="hh-wege">
        <a class="btn btn-primary" href="tel:{{ $telE164 }}" data-cta="telefon" data-cta-seite="hamburg">
          <span dir="ltr">{{ $telAnzeige }}</span>
        </a>
        <a class="btn btn-ghost-light" href="{{ $waLink }}" target="_blank" rel="noopener"
           data-cta="whatsapp" data-cta-seite="hamburg">WhatsApp</a>
        <a class="btn btn-ghost-light" href="{{ ($isAr ? '/ar' : '') }}/#kontakt" data-cta="formular" data-cta-seite="hamburg">
          {{ $isAr ? 'نموذج الاتصال' : 'Kontaktformular' }}
        </a>
      </div>
    </div>

    <div class="hh-karte">
      <h2>{{ $isAr ? 'كيف تسير الأمور أونلاين' : 'So läuft es online ab' }}</h2>
      <ul class="hh-liste">
        <li>{{ $isAr
            ? 'تصوّرون الأوراق بالهاتف وترسلونها عبر واتساب أو ترفعونها في بوابة العملاء – ونحفظها في ملفكم.'
            : 'Unterlagen mit dem Handy abfotografieren – per WhatsApp schicken oder im Kundenportal hochladen. Wir legen sie in Ihrer Akte ab.' }}</li>
        <li>{{ $isAr
            ? 'نراجع عقودكم القائمة ونشرح ما يغطّيه فعلاً وما لا يغطّيه – هاتفياً أو كتابةً.'
            : 'Bestehende Verträge gehen wir durch und erklären, was wirklich gedeckt ist und was nicht – am Telefon oder schriftlich.' }}</li>
        <li>{{ $isAr
            ? 'التوقيع رقمي: يصلكم رابط، وتوقّعون بإصبعكم على الهاتف.'
            : 'Unterschrieben wird digital: Sie bekommen einen Link und zeichnen mit dem Finger auf dem Telefon.' }}</li>
        <li>{{ $isAr
            ? 'تسجيل السيارة نتكفّل به كاملاً – لا أنتم ولا نحن نذهب بكم إلى دائرة المرور.'
            : 'Die Kfz-Zulassung übernehmen wir komplett – Sie müssen nicht zur Zulassungsstelle.' }}</li>
      </ul>
      <p class="hh-klein">{{ $isAr
          ? 'لأنّ كل شيء أونلاين، لا فرق إن كنتم في هامبورغ أو في أي مكان آخر في ألمانيا.'
          : 'Weil alles online läuft, macht es keinen Unterschied, ob Sie in Hamburg wohnen oder anderswo in Deutschland.' }}</p>
    </div>
  </div>

  {{-- Zweiter Weg des Zwei-Wege-Links: das Profil verweist auf die
       Website, die Website auf das Profil. Bewusst als Vertrauens-Karte
       mit den BEWERTUNGEN und ausdruecklich OHNE Anfahrt - es gibt
       keinen Besuchsbetrieb. --}}
  @include('website.partials.google_trust', ['seite' => 'hamburg'])
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
    <h2 class="title display">{{ $isAr ? 'أسئلة متكرّرة' : 'Häufige Fragen' }}</h2>
  </div>
  <div class="faq">
    <details>
      <summary>{{ $isAr ? 'هل يجب أن آتي إلى المكتب؟' : 'Muss ich zu Ihnen ins Büro kommen?' }}</summary>
      <p>{{ $isAr
          ? 'لا. نحن نعمل أونلاين بالكامل، ولا يوجد استقبال للزوار في المقرّ. كل شيء يتم بالهاتف أو واتساب أو بوابة العملاء – بما في ذلك تسجيل السيارة والتوقيع.'
          : 'Nein. Wir arbeiten vollständig online, einen Besuchsbetrieb gibt es an unserem Sitz nicht. Alles läuft über Telefon, WhatsApp oder das Kundenportal – auch die Kfz-Zulassung und die Unterschrift.' }}</p>
    </details>
    <details>
      <summary>{{ $isAr ? 'كيف أرسل أوراقي؟' : 'Wie schicke ich Ihnen meine Unterlagen?' }}</summary>
      <p>{{ $isAr
          ? 'صورة بالهاتف تكفي – عبر واتساب أو رفعاً في بوابة العملاء. المفيد: البوالص الحالية أو أرقام العقود، إثبات الهوية، وحسب الموضوع أوراق السيارة أو بيانات المزوّد السابق. إذا نقص شيء نقول لكم ذلك – ولا نخمّن أي بيان.'
          : 'Ein Handyfoto genügt – per WhatsApp oder als Upload im Kundenportal. Hilfreich sind: bestehende Policen oder Vertragsnummern, ein Ausweisdokument und – je nach Anliegen – Fahrzeugpapiere oder Angaben zum bisherigen Anbieter. Fehlt etwas, sagen wir Ihnen das; geraten wird nichts.' }}</p>
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
          ? 'نعم، في كل ألمانيا. وبما أنّ كل شيء أونلاين، فالخدمة واحدة تماماً أينما كنتم؛ هامبورغ هي مقرّنا فقط.'
          : 'Ja, deutschlandweit. Da ohnehin alles online läuft, ist die Beratung überall dieselbe – Hamburg ist unser Sitz, mehr nicht.' }}</p>
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
