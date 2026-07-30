@extends('website.layout')

@php
    use App\Models\MediaAsset;
    use App\Support\WebsiteHosts;
    $isAr = app()->getLocale() === 'ar';
    // Bild-Slots (Medienverwaltung /admin/medien) - serverseitig aufgeloest:
    // Slot belegt = Bild, sonst eingebaute Grafik (kein onerror mehr, P0-2).
    $heroImg = MediaAsset::forSlot('hero-startseite');
    $hamburgImg = MediaAsset::forSlot('ueber-uns-hamburg');
    $svcImgs = [
        'kfz-versicherung' => MediaAsset::forSlot('service-kfz-versicherung'),
        'krankenversicherung' => MediaAsset::forSlot('service-krankenversicherung'),
        'zahnzusatzversicherung' => MediaAsset::forSlot('service-zahnzusatzversicherung'),
        'kfz-zulassung' => MediaAsset::forSlot('service-kfz-zulassung'),
        'kennzeichen-per-post' => MediaAsset::forSlot('service-kennzeichen-per-post'),
        'strom-gas' => MediaAsset::forSlot('service-strom-gas'),
    ];
    $lPrefix = $isAr ? '/ar/leistungen/' : '/leistungen/';
    $go = $isAr ? 'التفاصيل وتقديم الطلب ←' : 'Mehr & anfragen →';
    $svcSizes = '(max-width:560px) 92vw, (max-width:860px) 45vw, 350px';
@endphp

@section('title', $isAr
    ? 'Dienstly24 – التأمين وتسجيل السيارات والطاقة | استشارة بالألمانية والعربية'
    : 'Dienstly24 – Versicherung, Kfz-Zulassung & Energie | Beratung DE & AR')

@section('head-extra')
<script type="application/ld+json">
{!! json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'InsuranceAgency',
    'name' => 'Dienstly24',
    'url' => WebsiteHosts::url('/'),
    'image' => WebsiteHosts::url('/images/og-image.jpg'),
    'description' => 'Anbieterunabhängige Beratung zu Versicherungen, Kfz-Zulassung und Energie – auf Deutsch und Arabisch.',
    'telephone' => '+49-179-9673909',
    'email' => config('website.email'),
    'priceRange' => '€€',
    'address' => ['@type' => 'PostalAddress', 'streetAddress' => 'Furtweg 51a', 'postalCode' => '22523', 'addressLocality' => 'Hamburg', 'addressCountry' => 'DE'],
    'areaServed' => ['@type' => 'Country', 'name' => 'Deutschland'],
    'availableLanguage' => ['de', 'ar'],
    'openingHoursSpecification' => [[
        '@type' => 'OpeningHoursSpecification',
        'dayOfWeek' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
        'opens' => '09:00', 'closes' => '18:00',
    ]],
    'sameAs' => [config('website.facebook')],
    'hasOfferCatalog' => [
        '@type' => 'OfferCatalog',
        'name' => 'Leistungen',
        'itemListElement' => array_map(fn ($s) => ['@type' => 'Offer', 'itemOffered' => ['@type' => 'Service', 'name' => $s]], [
            'Kfz-Versicherung', 'Krankenversicherung', 'Zahnzusatzversicherung',
            'Kfz-Zulassungsservice', 'Kennzeichen per Post', 'Strom- und Gasberatung',
        ]),
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
</script>
<script type="application/ld+json">
{!! json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'FAQPage',
    'mainEntity' => array_map(fn ($f) => [
        '@type' => 'Question', 'name' => $f[0],
        'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f[1]],
    ], $isAr ? [
        ['هل الاستشارة مجانية فعلاً؟', 'نعم، الاستشارة الأولى مجانية تماماً وغير ملزمة.'],
        ['هل تتحدثون العربية أيضاً؟', 'نعم، فريقنا يقدم الاستشارة بالألمانية والعربية – كما هو أنسب لكم.'],
        ['كم تستغرق معالجة طلبي؟', 'عادةً نتواصل معكم خلال 24 ساعة.'],
        ['كيف يمكنني إرسال طلب؟', 'أسهل طريقة هي نموذج الاتصال، أو واتساب، أو الهاتف على الرقم ‎+49 179 9673909.'],
    ] : [
        ['Ist die Beratung wirklich kostenlos?', 'Ja, die Erstberatung ist für Sie komplett kostenlos und unverbindlich.'],
        ['Sprechen Sie auch Arabisch?', 'Ja, unser Team berät Sie auf Deutsch und auf Arabisch – ganz wie es für Sie bequemer ist.'],
        ['Wie lange dauert die Bearbeitung meiner Anfrage?', 'In der Regel melden wir uns innerhalb von 24 Stunden bei Ihnen.'],
        ['Wie kann ich eine Anfrage stellen?', 'Am einfachsten über das Kontaktformular, per WhatsApp oder telefonisch unter +49 179 9673909.'],
    ]),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
</script>
@endsection

@section('content')
{{-- Hero --}}
<section class="hero"><div class="hero-facet" aria-hidden="true"></div><div class="container">
  <div class="hero-grid">
    <div>
      <span class="eyebrow"><span class="dot"></span><span>{{ $isAr ? 'خدمات الوساطة لكم في هامبورغ – وفي جميع أنحاء ألمانيا' : 'Ihr Makler-Service in Hamburg – deutschlandweit' }}</span></span>
      <h1 class="display"><span>{{ $isAr ? 'جميع أنواع التأمين.' : 'Alle Versicherungen.' }}</span><br><span class="gold">{{ $isAr ? 'جهة اتصال واحدة.' : 'Ein Ansprechpartner.' }}</span></h1>
      <p class="lead">{{ $isAr
          ? 'استشارة مستقلة عن شركات التأمين حول التأمين وتسجيل السيارات والطاقة – مجاناً وبلغة واضحة وبالألمانية والعربية.'
          : 'Anbieterunabhängige Beratung zu Versicherungen, Kfz-Zulassung und Energie – kostenlos, verständlich und auf Deutsch & Arabisch.' }}</p>
      <div class="hero-actions">
        <a href="#leistungen" class="btn btn-primary">{{ $isAr ? 'اختاروا الخدمة وأرسلوا طلبكم' : 'Leistung wählen & anfragen' }}</a>
        <a href="tel:{{ config('website.phone_e164') }}" class="btn btn-ghost">{{ $isAr ? 'اتصلوا بنا مباشرة' : 'Direkt anrufen' }}</a>
      </div>
    </div>
    <div class="hero-visual {{ $heroImg ? 'hat-bild' : '' }}" aria-hidden="true">
      @include('website.partials.picture', ['asset' => $heroImg, 'imgClass' => 'hero-shield-img', 'sizes' => '250px', 'lazy' => false])
      <svg viewBox="0 0 440 440" fill="none" xmlns="http://www.w3.org/2000/svg">
        <defs>
          <linearGradient id="shieldG" x1="120" y1="60" x2="330" y2="400" gradientUnits="userSpaceOnUse">
            <stop offset="0" stop-color="#1EC975"/><stop offset=".55" stop-color="#128A4B"/><stop offset="1" stop-color="#0A3D22"/>
          </linearGradient>
          <linearGradient id="goldG" x1="140" y1="120" x2="300" y2="320" gradientUnits="userSpaceOnUse">
            <stop offset="0" stop-color="#D1C18F"/><stop offset="1" stop-color="#B8A16B"/>
          </linearGradient>
          <radialGradient id="glowG" cx=".5" cy=".45" r=".55">
            <stop offset="0" stop-color="#1EC975" stop-opacity=".28"/><stop offset="1" stop-color="#1EC975" stop-opacity="0"/>
          </radialGradient>
        </defs>
        <circle cx="220" cy="215" r="205" fill="url(#glowG)"/>
        <circle cx="220" cy="215" r="168" stroke="#B8A16B" stroke-opacity=".35" stroke-width="1.5" stroke-dasharray="3 9"/>
        <g class="schild-kern">
        <path d="M220 62 L330 100 V212 C330 296 282 348 220 382 C158 348 110 296 110 212 V100 Z" fill="url(#shieldG)" stroke="url(#goldG)" stroke-width="3.5"/>
        <path d="M220 62 V382 M110 100 L330 100 M110 212 H330 M220 62 L110 212 M220 62 L330 212 M110 100 L220 382 M330 100 L220 382" stroke="#F5F3EC" stroke-opacity=".1" stroke-width="1.5"/>
        <path d="M172 218 L206 254 L272 176" stroke="#0A3D22" stroke-opacity=".5" stroke-width="24" stroke-linecap="round" stroke-linejoin="round"/>
        <path d="M172 214 L206 250 L272 172" stroke="url(#goldG)" stroke-width="17" stroke-linecap="round" stroke-linejoin="round"/>
        </g>
        <g class="chipf" transform="translate(66 96)">
          <rect x="-30" y="-30" width="60" height="60" rx="15" fill="#1A241E" stroke="#27342C"/>
          <path d="M-15 6 h30 M-13 6 v-7 l5 -8 h16 l5 8 v7" stroke="#D1C18F" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>
          <circle cx="-8" cy="10" r="3.6" stroke="#D1C18F" stroke-width="2.4"/><circle cx="8" cy="10" r="3.6" stroke="#D1C18F" stroke-width="2.4"/>
        </g>
        <g class="chipf f2" transform="translate(374 120)">
          <rect x="-30" y="-30" width="60" height="60" rx="15" fill="#1A241E" stroke="#27342C"/>
          <path d="M0 12 C-15 0 -10 -13 -2 -9 L0 -7 L2 -9 C10 -13 15 0 0 12 Z" stroke="#D1C18F" stroke-width="2.4" stroke-linejoin="round"/>
        </g>
        <g class="chipf f3" transform="translate(58 300)">
          <rect x="-30" y="-30" width="60" height="60" rx="15" fill="#1A241E" stroke="#27342C"/>
          <path d="M3 -14 L-9 3 H-1 L-3 14 L9 -3 H1 Z" stroke="#D1C18F" stroke-width="2.4" stroke-linejoin="round"/>
        </g>
        <g class="chipf f4" transform="translate(382 306)">
          <rect x="-30" y="-30" width="60" height="60" rx="15" fill="#1A241E" stroke="#27342C"/>
          <path d="M-14 -1 L0 -12 L14 -1 M-10 -3 V12 H10 V-3 M-3 12 V3 H3 V12" stroke="#D1C18F" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>
        </g>
      </svg>
    </div>
  </div>
  <div class="trust-row">
    <span class="trust-pill"><svg viewBox="0 0 24 24" fill="none" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg><span>{{ $isAr ? 'مجاني وغير ملزم' : 'Kostenlos & unverbindlich' }}</span></span>
    <span class="trust-pill"><svg viewBox="0 0 24 24" fill="none" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg><span>{{ $isAr ? 'الألمانية والعربية' : 'Deutsch & Arabisch' }}</span></span>
    <span class="trust-pill"><svg viewBox="0 0 24 24" fill="none" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg><span>{{ $isAr ? 'جهة اتصال شخصية ثابتة' : 'Persönlicher Ansprechpartner' }}</span></span>
  </div>
</div><svg class="hero-skyline" viewBox="0 0 1440 110" preserveAspectRatio="none" aria-hidden="true"><path d="M0 110 V84 H55 V66 H88 V90 H138 V58 H168 L173 38 L178 58 H208 V90 H258 V72 H298 V48 H328 V90 H378 V76 H428 V52 H458 L464 26 L470 52 H498 V90 H558 V68 H608 V90 H658 V44 H688 L695 16 L702 44 H728 V90 H788 V62 H838 V90 H888 V52 H918 V90 H978 V72 H1028 V38 H1058 L1064 18 L1070 38 H1098 V90 H1158 V68 H1208 V90 H1268 V58 H1308 V90 H1368 V78 H1440 V110 Z" fill="#17513F"/></svg></section>

{{-- Zahlenband --}}
<section class="band"><div class="container band-grid">
  <div class="band-item"><svg viewBox="0 0 32 32" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4.2"/><path d="M4 26c0-5 4-7.5 8-7.5s8 2.5 8 7.5"/><circle cx="22.5" cy="13" r="3.2"/><path d="M21 18.6c3.5.2 7 2.4 7 6.9"/></svg><div><b>100 %</b><span>{{ $isAr ? 'استشارة مستقلة' : 'Unabhängige Beratung' }}</span></div></div>
  <div class="band-item"><svg viewBox="0 0 32 32" fill="none" stroke-width="1.8" stroke-linejoin="round"><path d="M16 4 L19.6 11.6 L28 12.7 L21.8 18.4 L23.4 26.6 L16 22.6 L8.6 26.6 L10.2 18.4 L4 12.7 L12.4 11.6 Z"/></svg><div><b>3.000+</b><span>{{ $isAr ? 'عميل راضٍ' : 'Zufriedene Kunden' }}</span></div></div>
  <div class="band-item"><svg viewBox="0 0 32 32" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 3 C10.5 3 6.5 7.2 6.5 12 C6.5 19 16 28 16 28 S25.5 19 25.5 12 C25.5 7.2 21.5 3 16 3 Z"/><circle cx="16" cy="12" r="3.6"/></svg><div><b>{{ $isAr ? 'هامبورغ' : 'Hamburg' }}</b><span>{{ $isAr ? 'وفي جميع أنحاء ألمانيا' : '& deutschlandweit' }}</span></div></div>
  <div class="band-item"><svg viewBox="0 0 32 32" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="6" width="24" height="16" rx="4"/><path d="M12 22 L15 27 L18 22"/><path d="M10 12 h12 M10 16 h8"/></svg><div><b>{{ $isAr ? 'الألمانية والعربية' : 'Deutsch & Arabisch' }}</b><span>{{ $isAr ? 'في خدمتكم دائماً' : 'Für Sie da' }}</span></div></div>
</div></section>

{{-- Leistungen --}}
<section class="services" id="leistungen"><div class="container">
  <div class="section-head center reveal">
    <span class="kicker">{{ $isAr ? 'خدماتنا' : 'Unsere Leistungen' }}</span>
    <h2 class="title display">{{ $isAr ? 'خدماتنا المقدمة لكم' : 'Unsere Leistungen für Sie' }}</h2>
    <p>{{ $isAr ? 'اختاروا الخدمة المطلوبة – ستجدون كل المعلومات ويمكنكم إرسال طلبكم خلال ثوانٍ.' : 'Wählen Sie eine Leistung – Sie erhalten alle Infos und stellen in wenigen Sekunden Ihre Anfrage.' }}</p>
  </div>
  <div class="grid3">
    <a class="svc reveal" href="{{ $lPrefix }}kfz-versicherung">
      @include('website.partials.picture', ['asset' => $svcImgs['kfz-versicherung'], 'imgClass' => 'svc-bild', 'sizes' => $svcSizes])
      @unless($svcImgs['kfz-versicherung'])<div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 15h18M5 15v-3l2-4h10l2 4v3M6 15v2.5M18 15v2.5"/><circle cx="8.2" cy="15" r="0.4"/><circle cx="15.8" cy="15" r="0.4"/></svg></div>@endunless
      <h3>{{ $isAr ? 'تأمين السيارات' : 'Kfz-Versicherung' }}</h3>
      <p>{{ $isAr ? 'نشرح لكم بوضوح تأمين المسؤولية والتأمين الجزئي والشامل – ونجد لكم التغطية المناسبة.' : 'Haftpflicht, Teil- und Vollkasko verständlich erklärt – wir finden den passenden Schutz.' }}</p>
      <span class="go">{{ $go }}</span></a>
    <a class="svc reveal" href="{{ $lPrefix }}krankenversicherung">
      @include('website.partials.picture', ['asset' => $svcImgs['krankenversicherung'], 'imgClass' => 'svc-bild', 'sizes' => $svcSizes])
      @unless($svcImgs['krankenversicherung'])<div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linejoin="round"><path d="M9.5 3.5h5v6h6v5h-6v6h-5v-6h-6v-5h6Z"/></svg></div>@endunless
      <h3>{{ $isAr ? 'التأمين الصحي' : 'Krankenversicherung' }}</h3>
      <p>{{ $isAr ? 'قانوني أو خاص – نقدم لكم المشورة لاختيار الأنسب لوضعكم.' : 'Gesetzlich oder privat – wir beraten zu den Optionen, die zu Ihrer Situation passen.' }}</p>
      <span class="go">{{ $go }}</span></a>
    <a class="svc reveal" href="{{ $lPrefix }}zahnzusatzversicherung">
      @include('website.partials.picture', ['asset' => $svcImgs['zahnzusatzversicherung'], 'imgClass' => 'svc-bild', 'sizes' => $svcSizes])
      @unless($svcImgs['zahnzusatzversicherung'])<div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5.2C9.6 3.4 5.6 4 5.6 7.8c0 2.6 1.7 3.6 2.1 6 .3 1.9.7 4.4 1.5 5.6h1.1l.9-5.6c.5-.7 1.1-.7 1.6 0l.9 5.6h1.1c.8-1.2 1.2-3.7 1.5-5.6.4-2.4 2.1-3.4 2.1-6 0-3.8-4-4.4-6.4-2.6Z"/></svg></div>@endunless
      <h3>{{ $isAr ? 'التأمين التكميلي للأسنان' : 'Zahnzusatzversicherung' }}</h3>
      <p>{{ $isAr ? 'تعويض أعلى لتركيبات الأسنان والعلاجات – نوضح لكم ما يستحق فعلاً.' : 'Höhere Erstattung bei Zahnersatz und Behandlungen – wir zeigen, was sich lohnt.' }}</p>
      <span class="go">{{ $go }}</span></a>
    <a class="svc reveal" href="{{ $lPrefix }}kfz-zulassung">
      @include('website.partials.picture', ['asset' => $svcImgs['kfz-zulassung'], 'imgClass' => 'svc-bild', 'sizes' => $svcSizes])
      @unless($svcImgs['kfz-zulassung'])<div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="5.5" y="4.5" width="13" height="16" rx="2"/><path d="M9.5 4.5V3h5v1.5M9 10h6M9 13.5h6M9 17h3.5"/></svg></div>@endunless
      <h3>{{ $isAr ? 'خدمة تسجيل السيارات' : 'Kfz-Zulassungsservice' }}</h3>
      <p>{{ $isAr ? 'تسجيل المركبات ونقلها وإلغاء تسجيلها دون مراجعة الدوائر الرسمية ودون انتظار.' : 'An-, Um- und Abmeldung ohne Behördengang und ohne Warteschlange.' }}</p>
      <span class="go">{{ $go }}</span></a>
    <a class="svc reveal" href="{{ $lPrefix }}kennzeichen-per-post">
      @include('website.partials.picture', ['asset' => $svcImgs['kennzeichen-per-post'], 'imgClass' => 'svc-bild', 'sizes' => $svcSizes])
      @unless($svcImgs['kennzeichen-per-post'])<div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="8" width="19" height="8" rx="2"/><path d="M6 8v8M8.5 12h3M14 12h4.5"/></svg></div>@endunless
      <h3>{{ $isAr ? 'اللوحات عبر البريد' : 'Kennzeichen per Post' }}</h3>
      <p>{{ $isAr ? 'لوحات جديدة مختومة تصل إلى منزلكم بكل راحة – مع إمكانية اختيار رقم مميز.' : 'Neue Kennzeichen versiegelt und bequem nach Hause geliefert – auch mit Wunschkennzeichen.' }}</p>
      <span class="go">{{ $go }}</span></a>
    <a class="svc reveal" href="{{ $lPrefix }}strom-gas">
      @include('website.partials.picture', ['asset' => $svcImgs['strom-gas'], 'imgClass' => 'svc-bild', 'sizes' => $svcSizes])
      @unless($svcImgs['strom-gas'])<div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linejoin="round"><path d="M13 3 6 13.5h5L10 21l8-11h-5l0-7Z"/></svg></div>@endunless
      <h3>{{ $isAr ? 'الكهرباء والغاز' : 'Strom & Gas' }}</h3>
      <p>{{ $isAr ? 'نفحص التعرفة ونوفر لكم عند تغيير المزوّد – ونتولى عنكم إجراءات الإلغاء والتسجيل.' : 'Tarif prüfen und beim Anbieterwechsel sparen – Kündigung und Anmeldung übernehmen wir.' }}</p>
      <span class="go">{{ $go }}</span></a>
  </div>
  <p style="text-align:center;margin-top:26px;color:var(--muted);font-size:.9rem;">{{ $isAr ? '… والعديد من أنواع التأمين الأخرى. لا تترددوا في سؤالنا.' : '… und viele weitere Versicherungen. Fragen Sie einfach an.' }}</p>
</div></section>

{{-- Ablauf --}}
<section id="ablauf" class="ablauf"><div class="container">
  <div class="section-head center reveal">
    <span class="kicker">{{ $isAr ? 'بهذه البساطة' : "So einfach geht's" }}</span>
    <h2 class="title display">{{ $isAr ? 'ثلاث خطوات تفصلكم عن عرضكم' : 'In drei Schritten zu Ihrem Angebot' }}</h2>
  </div>
  <div class="steps">
    <div class="step reveal"><div class="n">1</div><h3>{{ $isAr ? 'أرسلوا طلبكم' : 'Anfrage stellen' }}</h3><p>{{ $isAr ? 'أخبرونا باختصار بما تحتاجونه – عبر الموقع أو واتساب أو الهاتف.' : 'Sagen Sie uns kurz, was Sie brauchen – online, per WhatsApp oder telefonisch.' }}</p></div>
    <div class="step reveal"><div class="n">2</div><h3>{{ $isAr ? 'نقارن العروض لكم' : 'Wir vergleichen für Sie' }}</h3><p>{{ $isAr ? 'نفحص التعرفات المناسبة باستقلالية تامة عن الشركات ونشرح لكم الفروق.' : 'Anbieterunabhängig prüfen wir passende Tarife und erklären die Unterschiede.' }}</p></div>
    <div class="step reveal"><div class="n">3</div><h3>{{ $isAr ? 'عرضكم الشخصي' : 'Persönliches Angebot' }}</h3><p>{{ $isAr ? 'تحصلون على عرضكم مشروحاً بوضوح – مع جهة اتصال شخصية ثابتة.' : 'Sie erhalten Ihr Angebot verständlich erklärt – und einen festen Ansprechpartner.' }}</p></div>
  </div>
</div></section>

{{-- Warum Dienstly24 --}}
<section id="ueber"><div class="container">
  <div class="promise reveal">
    <div class="promise-visual {{ $hamburgImg ? 'hat-foto' : '' }}" aria-hidden="true">
      @if($hamburgImg)
        @include('website.partials.picture', ['asset' => $hamburgImg, 'imgClass' => 'promise-foto', 'sizes' => '(max-width:840px) 92vw, 420px'])
      @else
        <svg viewBox="0 0 600 90" preserveAspectRatio="none" aria-hidden="true"><path d="M0 90 V70 H40 V56 H70 V74 H110 V48 H135 L140 30 L145 48 H170 V74 H215 V60 H255 V40 H285 V74 H330 V64 H375 V44 H400 L406 20 L412 44 H438 V74 H490 V56 H535 V74 H600 V90 Z" fill="#1A5A45"/></svg>
        <img class="promise-logo" src="/images/logo-white.png" alt="" width="250" height="63" loading="lazy">
      @endif
    </div>
    <div>
      <span class="kicker">{{ $isAr ? 'لماذا Dienstly24؟' : 'Warum Dienstly24?' }}</span>
      <h2 class="title display">{{ $isAr ? 'ميزتكم – وعدنا' : 'Ihr Vorteil – Unser Versprechen' }}</h2>
      <p class="promise-lead">{{ $isAr
          ? 'نقدم استشارة واضحة وصادقة حول التأمين وتسجيل السيارات والطاقة – بدلاً من المصطلحات المعقدة تحصلون على إجابات واضحة.'
          : 'Wir bieten verständliche, ehrliche Beratung rund um Versicherungen, Kfz-Zulassung und Energie – statt Fachbegriffen bekommen Sie klare Antworten.' }}</p>
      <ul class="plist">
        <li><span class="pic"><svg viewBox="0 0 24 24" fill="none" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg></span><div><b>{{ $isAr ? 'مستقلون وموضوعيون' : 'Unabhängig & objektiv' }}</b><p>{{ $isAr ? 'مستقلون عن شركات التأمين – ولا نلزمكم بأي شركة.' : 'Anbieterunabhängig – wir binden Sie an keine Gesellschaft.' }}</p></div></li>
        <li><span class="pic"><svg viewBox="0 0 24 24" fill="none" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg></span><div><b>{{ $isAr ? 'وفروا الوقت والمال' : 'Zeit & Geld sparen' }}</b><p>{{ $isAr ? 'جهة اتصال واحدة لكل شيء – نجعل الأمور سهلة عليكم.' : 'Ein Ansprechpartner für alles – wir machen es Ihnen einfach.' }}</p></div></li>
        <li><span class="pic"><svg viewBox="0 0 24 24" fill="none" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg></span><div><b>{{ $isAr ? 'بوضوح وقربٍ منكم' : 'Verständlich & nah' }}</b><p>{{ $isAr ? 'استشارة بالألمانية والعربية – ومتابعة شخصية من البداية إلى النهاية.' : 'Beratung auf Deutsch und Arabisch – persönlich betreut von Anfang bis Ende.' }}</p></div></li>
      </ul>
    </div>
  </div>
</div></section>

{{-- Kooperationen --}}
<section id="partner" class="partner"><div class="container">
  <div class="section-head center reveal">
    <span class="kicker">{{ $isAr ? 'شراكاتنا' : 'Kooperationen' }}</span>
    <h2 class="title display">{{ $isAr ? 'تحالفات قوية – وصول إلى عروض كثيرة' : 'Starke Pools – Zugang zu vielen Tarifen' }}</h2>
    <p>{{ $isAr
        ? 'من خلال تعاوننا مع تجمعات الوسطاء ومنصات المقارنة نقارن لكم عروض عدد كبير من الشركات باستقلالية تامة.'
        : 'Über unsere Kooperationen mit Maklerpools und Plattformen vergleichen wir anbieterunabhängig die Tarife zahlreicher Anbieter.' }}</p>
  </div>
  <p class="pool-label">{{ $isAr ? 'تجمعاتنا ومنصاتنا' : 'Unsere Pools & Plattformen' }}</p>
  <div class="strip reveal">
    <span class="chip">Fonds Finanz<small>Maklerpool</small></span>
    <span class="chip">tarifcheck<small>Vergleichsplattform</small></span>
    <span class="chip">TeamGermany<small>Maklerpool</small></span>
    <span class="chip">Stromkreis<small>Energie-Plattform</small></span>
    <span class="chip">CHECK24<small>Vergleichsportal</small></span>
  </div>
  <p class="pool-label" style="margin-top:32px;">{{ $isAr ? 'وصول إلى عروض شركات منها' : 'Zugang zu Tarifen u. a. von' }}</p>
  <div class="strip reveal">
    <span class="chip">Allianz<small>Versicherung</small></span>
    <span class="chip">AXA<small>Versicherung</small></span>
    <span class="chip">Telekom<small>Internet & Mobilfunk</small></span>
    <span class="chip">Vattenfall<small>Energie</small></span>
    <span class="chip">LichtBlick<small>Energie</small></span>
  </div>
</div></section>

{{-- Kundenstimmen --}}
<section class="stimmen on-dark" id="stimmen"><div class="container">
  <div class="section-head center reveal">
    <span class="kicker">{{ $isAr ? 'آراء العملاء' : 'Kundenstimmen' }}</span>
    <h2 class="title display">{{ $isAr ? 'ماذا يقول عملاؤنا عنا' : 'Was Kunden über uns sagen' }}</h2>
  </div>
  <div class="tgrid">
    <div class="tcard reveal"><p class="q">{{ $isAr ? '«أخيراً وجدت من يشرح لي الفروق بين تعرفات تأمين السيارات بوضوح – دون مصطلحات معقدة.»' : '„Endlich jemand, der mir die Unterschiede zwischen den Kfz-Tarifen wirklich erklärt hat – ohne Fachchinesisch.“' }}</p><div class="who"><div class="av">Y</div><div><b>Yasmin H.</b><span>Frankfurt</span></div></div></div>
    <div class="tcard reveal"><p class="q">{{ $isAr ? '«الاستشارة باللغة العربية ساعدتني كثيراً، لأنني لا أفهم بعد كل شيء بالألمانية.»' : '„Die Beratung auf Arabisch hat mir sehr geholfen, weil ich noch nicht alles auf Deutsch verstehe.“' }}</p><div class="who"><div class="av">O</div><div><b>Omar S.</b><span>Köln</span></div></div></div>
    <div class="tcard reveal"><p class="q">{{ $isAr ? '«كانت لدي جهة اتصال ثابتة طوال العملية، وهذا منحني شعوراً بالأمان.»' : '„Ich hatte einen festen Ansprechpartner während des ganzen Prozesses. Das hat mir Sicherheit gegeben.“' }}</p><div class="who"><div class="av">L</div><div><b>Laila M.</b><span>München</span></div></div></div>
  </div>
  <p style="text-align:center;margin-top:28px;"><a href="{{ config('website.facebook') }}" target="_blank" rel="noopener" class="btn btn-ghost">{{ $isAr ? 'شاهدوا جميع التقييمات على فيسبوك' : 'Alle Bewertungen auf Facebook ansehen' }}</a></p>
</div></section>

{{-- FAQ --}}
<section id="faq"><div class="container">
  <div class="section-head center reveal">
    <span class="kicker">{{ $isAr ? 'أسئلة شائعة' : 'Häufige Fragen' }}</span>
    <h2 class="title display">{{ $isAr ? 'معلومات مفيدة' : 'Gut zu wissen' }}</h2>
  </div>
  <div class="faq reveal" style="max-width:760px;margin:0 auto;">
    <details open><summary>{{ $isAr ? 'هل الاستشارة مجانية فعلاً؟' : 'Ist die Beratung wirklich kostenlos?' }}</summary><p>{{ $isAr ? 'نعم، الاستشارة الأولى مجانية تماماً وغير ملزمة.' : 'Ja, die Erstberatung ist für Sie komplett kostenlos und unverbindlich.' }}</p></details>
    <details><summary>{{ $isAr ? 'هل تتحدثون العربية أيضاً؟' : 'Sprechen Sie auch Arabisch?' }}</summary><p>{{ $isAr ? 'نعم، فريقنا يقدم الاستشارة بالألمانية والعربية – كما هو أنسب لكم.' : 'Ja, unser Team berät Sie auf Deutsch und auf Arabisch – ganz wie es für Sie bequemer ist.' }}</p></details>
    <details><summary>{{ $isAr ? 'كم تستغرق معالجة طلبي؟' : 'Wie lange dauert die Bearbeitung meiner Anfrage?' }}</summary><p>{{ $isAr ? 'عادةً نتواصل معكم خلال 24 ساعة.' : 'In der Regel melden wir uns innerhalb von 24 Stunden bei Ihnen.' }}</p></details>
    <details><summary>{{ $isAr ? 'كيف يمكنني إرسال طلب؟' : 'Wie kann ich eine Anfrage stellen?' }}</summary><p>{{ $isAr ? 'أسهل طريقة هي نموذج الاتصال أدناه، أو واتساب، أو الهاتف.' : 'Am einfachsten über das Kontaktformular unten, per WhatsApp oder telefonisch.' }}</p></details>
  </div>
</div></section>

{{-- Kontakt: serverseitiges Formular -> Lead/Ticket im System (P0-1). --}}
<section class="kontakt" id="kontakt"><div class="container"><div class="contact-grid">
  <div class="reveal">
    <span class="kicker">{{ $isAr ? 'اتصلوا بنا' : 'Kontakt' }}</span>
    <h2 class="title display">{{ $isAr ? 'اكتبوا لنا طلبكم' : 'Schreiben Sie uns Ihr Anliegen' }}</h2>
    <p style="color:var(--muted);margin-bottom:24px;">{{ $isAr ? 'نرد عليكم عادةً خلال 24 ساعة – بالألمانية أو العربية.' : 'Wir melden uns in der Regel innerhalb von 24 Stunden – auf Deutsch oder Arabisch.' }}</p>
    <ul class="cinfo">
      <li><span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.13.96.36 1.9.7 2.8a2 2 0 0 1-.45 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.27a2 2 0 0 1 2.1-.45c.9.34 1.84.57 2.8.7A2 2 0 0 1 22 16.9z"/></svg></span><a href="tel:{{ config('website.phone_e164') }}" class="telnum">{{ config('website.phone_display') }}</a></li>
      <li><span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/></svg></span><a href="mailto:{{ config('website.email') }}">{{ config('website.email') }}</a></li>
      <li><span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg></span><span>{{ $isAr ? 'الإثنين–الجمعة، 9:00–18:00' : 'Mo–Fr, 9:00–18:00 Uhr' }}</span></li>
      <li><span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg></span><span>Furtweg 51a, 22523 Hamburg</span></li>
    </ul>
  </div>
  <form class="form reveal" action="/kontakt" method="post">
    @csrf
    <input type="hidden" name="lang" value="{{ $isAr ? 'ar' : 'de' }}">
    {{-- Honeypot: unsichtbar fuer Menschen, Pflichtfalle fuer Bots. --}}
    <div class="hp-field" aria-hidden="true">
      <label for="hp-website">Website</label>
      <input type="text" id="hp-website" name="website" tabindex="-1" autocomplete="off">
    </div>
    @if($errors->any())
      <div class="form-errors" role="alert">
        {{ $isAr ? 'يرجى التحقق من الحقول المحددة:' : 'Bitte prüfen Sie die markierten Felder:' }}
        <ul style="list-style:disc;padding-inline-start:18px;margin-top:6px;">
          @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul>
      </div>
    @endif
    <div class="row">
      <div class="field"><label for="f-name">{{ $isAr ? 'الاسم' : 'Name' }}</label><input type="text" id="f-name" name="name" value="{{ old('name') }}" required maxlength="150" @if($errors->has('name')) class="invalid" @endif></div>
      <div class="field"><label for="f-kontakt">{{ $isAr ? 'البريد الإلكتروني أو الهاتف' : 'E-Mail oder Telefon' }}</label><input type="text" id="f-kontakt" name="kontakt" value="{{ old('kontakt') }}" required maxlength="190" @if($errors->has('kontakt')) class="invalid" @endif></div>
    </div>
    <div class="field"><label for="f-leistung">{{ $isAr ? 'الخدمة المطلوبة' : 'Gewünschte Leistung' }}</label>
      <select id="f-leistung" name="leistung" @if($errors->has('leistung')) class="invalid" @endif>
        @foreach([
            'Kfz-Versicherung' => $isAr ? 'تأمين السيارات' : 'Kfz-Versicherung',
            'Krankenversicherung' => $isAr ? 'التأمين الصحي' : 'Krankenversicherung',
            'Zahnzusatzversicherung' => $isAr ? 'التأمين التكميلي للأسنان' : 'Zahnzusatzversicherung',
            'Kfz-Zulassungsservice' => $isAr ? 'خدمة تسجيل السيارات' : 'Kfz-Zulassungsservice',
            'Kennzeichen per Post' => $isAr ? 'اللوحات عبر البريد' : 'Kennzeichen per Post',
            'Strom & Gas' => $isAr ? 'الكهرباء والغاز' : 'Strom & Gas',
            'Sonstiges' => $isAr ? 'أخرى' : 'Sonstiges',
        ] as $value => $label)
          <option value="{{ $value }}" @selected(old('leistung') === $value)>{{ $label }}</option>
        @endforeach
      </select>
    </div>
    <div class="field"><label for="f-nachricht">{{ $isAr ? 'رسالتكم' : 'Ihre Nachricht' }}</label><textarea id="f-nachricht" name="nachricht" rows="4" maxlength="5000">{{ old('nachricht') }}</textarea></div>
    <div class="consent"><input type="checkbox" required id="c1" name="consent" value="1" @checked(old('consent'))><label for="c1" style="margin:0;"><span>{{ $isAr ? 'أوافق على معالجة بياناتي لغرض الرد على طلبي.' : 'Ich stimme der Verarbeitung meiner Angaben zur Bearbeitung meiner Anfrage zu.' }}</span> <a href="/datenschutz">{{ $isAr ? 'حماية البيانات' : 'Datenschutz' }}</a></label></div>
    <button type="submit" class="btn btn-primary" style="width:100%;">{{ $isAr ? 'إرسال الطلب' : 'Anfrage senden' }}</button>
    <p style="font-size:.72rem;color:var(--muted);text-align:center;margin-top:12px;">{{ $isAr ? 'يُرسل طلبكم إلينا مباشرة بشكل آمن ويُعامل بسرية. بدلاً من ذلك يمكنكم التواصل عبر واتساب أو الهاتف.' : 'Ihre Anfrage wird direkt und sicher an uns übermittelt und vertraulich behandelt. Alternativ per WhatsApp oder Telefon.' }}</p>
  </form>
</div></div></section>
@endsection
