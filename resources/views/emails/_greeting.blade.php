{{-- Zentrale Anrede für ALLE Mails (Final Polish Punkt 4).
     Bevorzugt ein Customer-Objekt ($greetingCustomer); sonst eine
     bereits berechnete Zeile ($greetingLine); sonst Fallback über den
     Namen. So nutzt jede Mail dieselbe Logik. --}}
@php
    $line = null;
    if (!empty($greetingCustomer)) {
        $line = $greetingCustomer->salutationLine();
    } elseif (!empty($greetingLine)) {
        $line = $greetingLine;
    } elseif (!empty($greetingName)) {
        $line = 'Guten Tag ' . $greetingName;
    } else {
        $line = 'Sehr geehrte Damen und Herren';
    }
@endphp
<p style="font-size:15px;color:#333;">{{ $line }},</p>
