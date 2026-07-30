@extends('website.layout')

@php($isAr = app()->getLocale() === 'ar')

@section('title', $isAr ? 'شكراً لطلبكم – Dienstly24' : 'Danke für Ihre Anfrage – Dienstly24')
@section('robots', 'noindex, follow')

@section('content')
<div class="thanks-wrap">
  <div class="thanks-card">
    <div class="ok"><svg viewBox="0 0 24 24" fill="none" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg></div>
    <h1 class="display">{{ $isAr ? 'وصلنا طلبكم – شكراً لكم!' : 'Ihre Anfrage ist bei uns angekommen!' }}</h1>
    <p>{{ $isAr
        ? 'سنراجع طلبكم ونتواصل معكم عادةً خلال 24 ساعة – بالألمانية أو العربية، كما تفضلون.'
        : 'Wir prüfen Ihre Anfrage und melden uns in der Regel innerhalb von 24 Stunden – auf Deutsch oder Arabisch, ganz wie Sie möchten.' }}</p>
    <p>{{ $isAr
        ? 'إذا كان الأمر عاجلاً، يمكنكم الاتصال بنا مباشرة أو مراسلتنا عبر واتساب.'
        : 'Wenn es eilig ist, erreichen Sie uns direkt per Telefon oder WhatsApp.' }}</p>
    <div class="actions">
      <a href="{{ $isAr ? '/ar' : '/' }}" class="btn btn-primary">{{ $isAr ? 'العودة إلى الصفحة الرئيسية' : 'Zurück zur Startseite' }}</a>
      <a href="tel:{{ config('website.phone_e164') }}" class="btn btn-ghost-light telnum">{{ config('website.phone_display') }}</a>
    </div>
  </div>
</div>
@endsection
