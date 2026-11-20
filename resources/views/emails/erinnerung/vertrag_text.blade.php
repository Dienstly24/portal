@php($r = $e->rahmen)
{!! $r['gruss'] !!}@if($e->anrede !== '') {!! $e->anrede !!}@endif{!! $e->sprache === 'ar' ? '،' : ',' !!}

@foreach($e->absaetze as $absatz)
{!! $absatz !!}

@endforeach
{!! $r['chat'] !!}: {!! $e->chatUrl !!}
@if($e->whatsappUrl)
{!! $r['whatsapp'] !!}: {!! $e->whatsappUrl !!}
@endif
{!! $r['antworten'] !!}@if($e->telefon !== '') {!! $r['telefon'] !!}: {!! $e->sprache === 'ar' ? "\u{2066}".$e->telefon."\u{2069}" : $e->telefon !!}@endif


{!! $r['daten'] !!}
@foreach($e->zeilen as $label => $wert)
- {!! $label !!}: {!! $wert !!}
@endforeach

{!! $r['schluss'] !!}
{!! $r['team'] !!}

--
{!! $r['impressum'] !!}: {!! $e->impressumUrl !!}
{!! $r['datenschutz'] !!}: {!! $e->datenschutzUrl !!}
@if($e->abmeldeUrl)
{!! $r['abbestellen_frage'] !!} {!! $r['abbestellen'] !!}: {!! $e->abmeldeUrl !!}
@endif
