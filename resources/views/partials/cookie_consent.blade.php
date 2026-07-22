{{--
    Cookie-/Einwilligungs-Banner (DSGVO / TTDSG §25).
    Erscheint einmalig unten, bis der Besucher eine Wahl trifft. Es werden
    ausschliesslich technisch notwendige Cookies (Session, CSRF, Sprache,
    "Angemeldet bleiben") ohne Einwilligung gesetzt; optionale Cookies erst
    nach aktiver Zustimmung. Die Wahl wird in einem eigenen First-Party-Cookie
    (365 Tage) festgehalten und ist im Footer ueber "Cookie-Richtlinie"
    jederzeit widerrufbar. Selbsttragend (Inline-CSS/JS, kein SVG), RTL-faehig.
--}}
@php $rtl = app()->getLocale() === 'ar'; @endphp
<div id="d24-cookie" role="dialog" aria-live="polite" aria-label="{{ __('Cookie-Hinweis') }}"
     dir="{{ $rtl ? 'rtl' : 'ltr' }}"
     style="display:none;position:fixed;z-index:9999;inset-inline:0;bottom:0;padding:16px;background:#131A17;border-top:1px solid rgba(255,255,255,.14);box-shadow:0 -12px 40px rgba(0,0,0,.45);font-family:'Inter',Arial,sans-serif;">
  <div style="max-width:1080px;margin:0 auto;display:flex;flex-wrap:wrap;align-items:center;gap:14px 22px;justify-content:space-between;">
    <div style="flex:1 1 320px;min-width:260px;color:#c6cbd3;font-size:13px;line-height:1.55;">
      <strong style="color:#fff;display:block;margin-bottom:3px;font-size:14px;">🍪 {{ __('Cookies und Datenschutz') }}</strong>
      {{ __('Wir verwenden technisch notwendige Cookies fuer den Betrieb des Portals. Optionale Cookies (z. B. fuer Komfort und Statistik) setzen wir nur mit Ihrer Einwilligung.') }}
      <a href="{{ route('legal', 'cookie-richtlinie') }}" style="color:#3ddc8e;text-decoration:none;">{{ __('Cookie-Richtlinie') }}</a>
      · <a href="{{ route('legal', 'datenschutz') }}" style="color:#3ddc8e;text-decoration:none;">{{ __('Datenschutzerklärung') }}</a>
    </div>
    <div style="display:flex;gap:10px;flex:none;flex-wrap:wrap;">
      <button type="button" onclick="d24CookieChoice('essential')"
              style="background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.2);color:#dde0e5;font-size:13.5px;font-weight:600;padding:10px 18px;border-radius:10px;cursor:pointer;">
        {{ __('Nur notwendige') }}
      </button>
      <button type="button" onclick="d24CookieChoice('all')"
              style="background:linear-gradient(180deg,#19b463,#128a4b);border:1px solid #1fc06e;color:#fff;font-size:13.5px;font-weight:700;padding:10px 20px;border-radius:10px;cursor:pointer;">
        {{ __('Alle akzeptieren') }}
      </button>
    </div>
  </div>
</div>
<script>
(function(){
  function getCookie(n){return document.cookie.split('; ').find(r=>r.startsWith(n+'='))?.split('=')[1];}
  function setCookie(n,v){var d=new Date();d.setTime(d.getTime()+365*24*60*60*1000);document.cookie=n+'='+v+';expires='+d.toUTCString()+';path=/;SameSite=Lax';}
  window.d24CookieChoice=function(choice){
    setCookie('cookie_consent',choice);
    try{localStorage.setItem('cookie_consent',choice);}catch(e){}
    var el=document.getElementById('d24-cookie');if(el)el.style.display='none';
    document.dispatchEvent(new CustomEvent('d24:cookie-consent',{detail:{choice:choice}}));
  };
  if(!getCookie('cookie_consent')){
    var el=document.getElementById('d24-cookie');if(el)el.style.display='block';
  }
})();
</script>
