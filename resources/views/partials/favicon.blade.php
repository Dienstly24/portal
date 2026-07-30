{{-- Favicon / App-Icon (Dienstly-D-Symbol). Zentral, damit alle Seiten
     dieselbe Marke im Browser-Tab zeigen. Quelle: Slot "favicon" aus
     /admin/medien; ohne zugewiesenes Bild die mitgelieferten Dateien. --}}
<link rel="icon" type="image/png" sizes="32x32" href="{{ \App\Support\BrandAssets::favicon(32) }}">
<link rel="icon" type="image/png" sizes="512x512" href="{{ \App\Support\BrandAssets::favicon(512) }}">
<link rel="apple-touch-icon" href="{{ \App\Support\BrandAssets::favicon(180) }}">
<meta name="theme-color" content="#131A17">
