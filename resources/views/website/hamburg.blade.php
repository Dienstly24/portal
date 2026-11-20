@extends('website.layout')

@php
    use App\Support\WebsiteHosts;
    $isAr = app()->getLocale() === 'ar';
    $hSlug = \App\Http\Controllers\WebsiteController::HAMBURG_SLUG;
    $lPrefix = $isAr ? '/ar/leistungen/' : '/leistungen/';
    $telE164 = config('website.phone_e164');
    $telAnzeige = config('website.phone_display');
    $waText = $isAr
        ? 'مرحباً Dienstly24، لدي طلب. أنا في هامبورغ.'
        : 'Hallo Dienstly24, ich habe ein Anliegen. Ich bin aus Hamburg.';
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
    ? 'Dienstly24 في هامبورغ | كل شيء أونلاين بالعربية والألمانية'
    : 'Dienstly24 in Hamburg | Service komplett online')

@section('description', $isAr
    ? 'منصّة خدمة وتواصل مقرّها هامبورغ – كل شيء أونلاين، بالعربية والألمانية. التأمين وتسجيل السيارات والكهرباء والغاز. لا داعي للحضور إلى أي مكتب. مجاناً وبدون التزام.'
    : 'Service- und Kontaktplattform aus Hamburg – komplett online, auf Deutsch und Arabisch. Versicherungen, Kfz-Zulassung sowie Strom & Gas. Sie müssen nirgendwo hinkommen. Kostenlos und unverbindlich.')

@section('og-title', $isAr ? 'Dienstly24 في هامبورغ – كل شيء أونلاين' : 'Dienstly24 in Hamburg – komplett online')

@section('head-extra')
{{-- Strukturierte Daten: Aufbau in App\Services\Seo\StructuredData (nie
     als Array in der Vorlage - Blade macht aus "at-context" sonst eine
     eigene Direktive, Audit 15.09.2026).

     HIER IST DER `LocalBusiness`-BLOCK RICHTIG, waehrend er auf den
     Leistungsseiten bewusst fehlt: dies ist die Seite ueber den SITZ des
     Betriebs. Sie beschreibt denselben einen Betrieb wie die Startseite,
     nicht einen zweiten - es gibt weiterhin genau eine Anschrift im
     gesamten Auftritt.

     KEIN BESUCHSBETRIEB (Betreiber-Klarstellung 19.09.2026): gearbeitet
     wird ausschliesslich online. Die Zeiten sind ERREICHBARKEITS-Zeiten,
     keine Einladung vorbeizukommen; die Seite sagt das ausdruecklich. --}}
{!! \App\Services\Seo\StructuredData::script(\App\Services\Seo\StructuredData::unternehmen()) !!}
{!! \App\Services\Seo\StructuredData::script(\App\Services\Seo\StructuredData::breadcrumbList([
    [__('Startseite'), $isAr ? '/ar' : '/'],
    [$isAr ? 'Dienstly24 في هامبورغ' : 'Dienstly24 in Hamburg', ($isAr ? '/ar/' : '/').$hSlug],
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
        ? 'Dienstly24 في هامبورغ – كل شيء أونلاين'
        : 'Dienstly24 in Hamburg – komplett online' }}</h1>
    <p>{{ $isAr
        ? 'مقرّنا في هامبورغ، والعمل كلّه أونلاين: هاتف، واتساب، والدردشة – بالعربية والألمانية. لا تحتاجون للحضور إلى أي مكتب، ولا حتى لتسجيل السيارة.'
        : 'Unser Sitz ist in Hamburg, gearbeitet wird vollständig online: Telefon, WhatsApp und Chat – auf Deutsch und auf Arabisch. Sie müssen zu keinem Termin erscheinen, auch nicht für die Kfz-Zulassung.' }}</p>
  </div>

  {{-- EHRLICH AN DER ERSTINFORMATION AUSGERICHTET, genau wie die
       Seite /leistungen/so-arbeiten-wir: Dienstly24 haelt
       die Erlaubnis nach Paragraph 34d GewO NICHT selbst. Eine
       Ortsseite, die das verschweigt, waere die bequemere - und eine
       falsche Angabe ueber das eigene Unternehmen. --}}
  <p class="hh-hinweis">{{ $isAr
      ? 'ملاحظة: Dienstly24 ليست وسيط تأمين ولا تقدّم توصيات. وساطة عقود التأمين يتولاها حصراً شريكنا، وهو وسيط تأمين مرخّص وفق المادة 34d الفقرة 1 من قانون مزاولة الحرف (GewO)، وعلى مسؤوليته. نحن نضعكم على تواصل معه فقط – التفاصيل الكاملة في صفحة المعلومات الأولى.'
      : 'Hinweis: Dienstly24 ist kein Versicherungsvermittler und gibt keine Empfehlungen. Die Vermittlung von Versicherungsverträgen übernimmt ausschließlich unser Kooperationspartner, ein zugelassener Versicherungsmakler mit Erlaubnis nach § 34d Abs. 1 GewO, in eigener Verantwortung. Wir stellen nur den Kontakt her – alle Angaben dazu stehen in der' }}
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
            ? 'تصوّرون الأوراق بالهاتف وترسلونها عبر واتساب – ونحفظها في ملفكم.'
            : 'Unterlagen mit dem Handy abfotografieren und per WhatsApp schicken. Wir legen sie in Ihrer Akte ab.' }}</li>
        <li>{{ $isAr
            ? 'لأسئلة التأمين نضعكم على تواصل مع شريكنا المرخّص – هاتفياً أو كتابةً.'
            : 'Für Fragen zu Versicherungen stellen wir den Kontakt zu unserem zugelassenen Partner her – am Telefon oder schriftlich.' }}</li>
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
          ? 'قبل تسجيل السيارة نضعكم على تواصل مع شريكنا بخصوص التأمين.'
          : 'Vor der Zulassung stellen wir für die Versicherung den Kontakt zu unserem Partner her.' }}</span>
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
          ? 'قانوني أو خاص – لأسئلتكم نضعكم على تواصل مع شريكنا المرخّص.'
          : 'Gesetzlich oder privat – für Ihre Fragen stellen wir den Kontakt zu unserem zugelassenen Partner her.' }}</span>
    </a>
    <a href="{{ $lPrefix }}strom-gas">
      <strong>{{ $isAr ? 'الكهرباء والغاز' : 'Strom & Gas' }}</strong>
      <span>{{ $isAr
          ? 'عند الانتقال إلى سكن جديد – الإلغاء والتسجيل علينا.'
          : 'Beim Umzug in eine neue Wohnung – Kündigung und Anmeldung übernehmen wir.' }}</span>
    </a>
    <a href="{{ $lPrefix }}so-arbeiten-wir">
      <strong>{{ $isAr ? 'هكذا نعمل' : 'So arbeiten wir' }}</strong>
      <span>{{ $isAr
          ? 'ما تقوم به Dienstly24، وما يتولاه شريكنا المرخّص.'
          : 'Was Dienstly24 übernimmt – und was unser zugelassener Partner.' }}</span>
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
          ? 'لا. نحن نعمل أونلاين بالكامل، ولا يوجد استقبال للزوار في المقرّ. كل شيء يتم بالهاتف أو واتساب أو الدردشة – بما في ذلك تسجيل السيارة والتوقيع.'
          : 'Nein. Wir arbeiten vollständig online, einen Besuchsbetrieb gibt es an unserem Sitz nicht. Alles läuft über Telefon, WhatsApp oder den Chat – auch die Kfz-Zulassung und die Unterschrift.' }}</p>
    </details>
    <details>
      <summary>{{ $isAr ? 'كيف أرسل أوراقي؟' : 'Wie schicke ich Ihnen meine Unterlagen?' }}</summary>
      <p>{{ $isAr
          ? 'صورة بالهاتف تكفي – عبر واتساب. المفيد: البوالص الحالية أو أرقام العقود، إثبات الهوية، وحسب الموضوع أوراق السيارة أو بيانات المزوّد السابق. إذا نقص شيء نقول لكم ذلك – ولا نخمّن أي بيان.'
          : 'Ein Handyfoto genügt – per WhatsApp. Hilfreich sind: bestehende Policen oder Vertragsnummern, ein Ausweisdokument und – je nach Anliegen – Fahrzeugpapiere oder Angaben zum bisherigen Anbieter. Fehlt etwas, sagen wir Ihnen das; geraten wird nichts.' }}</p>
    </details>
    <details>
      <summary>{{ $isAr ? 'هل التواصل بالعربية متاح فعلاً؟' : 'Kann ich wirklich auf Arabisch schreiben?' }}</summary>
      <p>{{ $isAr
          ? 'نعم. التواصل معنا متاح بالألمانية وبالعربية، وأنتم تختارون اللغة الأسهل عليكم.'
          : 'Ja. Der Kontakt mit uns ist auf Deutsch und auf Arabisch möglich – Sie wählen, was Ihnen leichter fällt.' }}</p>
    </details>
    <details>
      <summary>{{ $isAr ? 'هل تعملون خارج هامبورغ أيضاً؟' : 'Sind Sie auch außerhalb von Hamburg für mich da?' }}</summary>
      <p>{{ $isAr
          ? 'نعم، في كل ألمانيا. وبما أنّ كل شيء أونلاين، فالخدمة واحدة تماماً أينما كنتم؛ هامبورغ هي مقرّنا فقط.'
          : 'Ja, deutschlandweit. Da ohnehin alles online läuft, ist der Service überall derselbe – Hamburg ist unser Sitz, mehr nicht.' }}</p>
    </details>
    <details>
      <summary>{{ $isAr ? 'هل يكلّفني التواصل معكم شيئاً؟' : 'Kostet mich der Kontakt etwas?' }}</summary>
      <p>{{ $isAr
          ? 'لا. التواصل معنا مجاني وغير ملزم. أما شروط شريكنا المرخّص فتجدونها في صفحة المعلومات الأولى.'
          : 'Nein. Der Kontakt mit uns ist kostenlos und unverbindlich. Die Angaben unseres zugelassenen Partners stehen in der Erstinformation.' }}</p>
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
