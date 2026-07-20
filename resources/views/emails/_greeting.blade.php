{{-- Zentrale Anrede für ALLE Mails (Final Polish Punkt 4).
     Bevorzugt ein Customer-Objekt ($greetingCustomer); sonst eine
     bereits berechnete Zeile ($greetingLine); sonst Fallback über den
     Namen. So nutzt jede Mail dieselbe Logik. --}}
@php
    $lng = ($lang ?? (app()->getLocale() === 'ar' ? 'ar' : 'de')) === 'ar' ? 'ar' : 'de';
    $line = null;
    if (!empty($greetingCustomer)) {
        $line = $greetingCustomer->salutationLineFor($lng);
    } elseif (!empty($greetingLine)) {
        $line = $greetingLine;
    } elseif (!empty($greetingName)) {
        $line = ($lng === 'ar' ? 'مرحباً ' : 'Guten Tag ') . $greetingName;
    } else {
        $line = $lng === 'ar' ? 'حضرة السادة المحترمين' : 'Sehr geehrte Damen und Herren';
    }
@endphp
<p style="font-size:15px;color:#333;" dir="{{ $lng === 'ar' ? 'rtl' : 'ltr' }}">{{ $line }},</p>
