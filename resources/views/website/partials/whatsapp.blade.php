{{-- WhatsApp-Float-Button (P0-3): auf jeder Website-Seite, offizielles
     Icon als SVG (kein Emoji), vorbefuellter Text je Seite/Leistung.
     Styles hier im Partial, damit der Button auch auf Seiten mit eigenem
     CSS (Leistungsseiten) identisch funktioniert. --}}
<style>
.wa-float{position:fixed;bottom:20px;inset-inline-end:18px;z-index:150;display:flex;align-items:center;gap:10px;background:#25D366;color:#fff;border-radius:99px;padding:13px 18px;font-weight:700;font-size:.9rem;box-shadow:0 10px 26px rgba(11,61,32,.35);text-decoration:none;transition:transform .25s ease,box-shadow .25s ease;}
.wa-float:hover{transform:translateY(-3px);box-shadow:0 16px 34px rgba(11,61,32,.45);color:#fff;}
.wa-float svg{width:22px;height:22px;fill:#fff;flex:none;}
@media(max-width:640px){.wa-float{padding:13px;bottom:16px;}.wa-float .wa-label{display:none;}}
</style>
@php
    $isArWa = app()->getLocale() === 'ar';
    $waText = $waText ?? ($isArWa
        ? 'مرحباً Dienstly24، أريد استشارة بخصوص خدماتكم.'
        : 'Hallo Dienstly24, ich interessiere mich für eine Beratung.');
    $waHref = 'https://wa.me/' . config('website.whatsapp') . '?text=' . rawurlencode($waText);
@endphp
<a class="wa-float" href="{{ $waHref }}" target="_blank" rel="noopener"
   aria-label="{{ $isArWa ? 'تواصل معنا عبر واتساب' : 'Per WhatsApp schreiben' }}">
    <svg viewBox="0 0 32 32" aria-hidden="true"><path d="M16.04 3C9.02 3 3.32 8.7 3.32 15.72c0 2.24.59 4.43 1.71 6.36L3.2 29l7.11-1.86a12.66 12.66 0 0 0 5.73 1.38h.01c7.01 0 12.72-5.7 12.72-12.72 0-3.4-1.32-6.6-3.72-9A12.65 12.65 0 0 0 16.04 3zm0 23.38h-.01c-1.9 0-3.76-.51-5.38-1.47l-.39-.23-4.22 1.1 1.13-4.11-.25-.42a10.53 10.53 0 0 1-1.61-5.6c0-5.83 4.75-10.57 10.58-10.57 2.83 0 5.48 1.1 7.48 3.1a10.5 10.5 0 0 1 3.1 7.48c0 5.83-4.75 10.57-10.57 10.57zm5.8-7.92c-.32-.16-1.88-.93-2.17-1.03-.29-.11-.5-.16-.72.16-.21.32-.82 1.03-1.01 1.24-.19.21-.37.24-.69.08-.32-.16-1.34-.5-2.56-1.58-.95-.85-1.58-1.89-1.77-2.21-.19-.32-.02-.49.14-.65.14-.14.32-.37.48-.56.16-.19.21-.32.32-.53.11-.21.05-.4-.03-.56-.08-.16-.72-1.72-.98-2.36-.26-.62-.52-.54-.72-.55l-.61-.01c-.21 0-.56.08-.85.4-.29.32-1.12 1.09-1.12 2.65 0 1.56 1.14 3.07 1.3 3.29.16.21 2.25 3.44 5.45 4.82.76.33 1.36.53 1.82.68.77.24 1.46.21 2.01.13.61-.09 1.88-.77 2.15-1.51.27-.74.27-1.38.19-1.51-.08-.13-.29-.21-.61-.37z"/></svg>
    <span class="wa-label">WhatsApp</span>
</a>
