@extends('layouts.admin')
@section('content')
{{-- Interessenten aus dem Website-Assistenten (Spezifikation Abschnitt 20).

     Ein Lead ist bewusst KEIN Kunde: der Besucher hat nichts unterschrieben
     und meist keine Akte. Hier steht, was die KI gesammelt hat, wie weit das
     Gespraech ist und was als naechstes zu tun ist - damit ein Mitarbeiter
     ohne Nachfragen weiterarbeiten kann.

     Sensible Angaben (Bankverbindung, Geburtsdatum) erhebt der
     Website-Assistent GRUNDSAETZLICH nicht - ein Interessent ist nicht
     identifiziert. --}}
<div class="page-header">
    <div class="breadcrumb">
        <a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span>
        <span>Interessenten</span>
    </div>
    <div>
        <div class="page-title">Interessenten von der Website</div>
        <div class="page-sub">Vom KI-Assistenten qualifizierte Anfragen. Angebot auswählen und den Interessenten kontaktieren.</div>
    </div>
</div>

@if(session('success'))<div style="background:#D9F4E6;color:#17A65B;padding:10px 16px;border-radius:8px;margin-bottom:16px;">{{ session('success') }}</div>@endif

<div class="card" style="margin-bottom:16px;">
    <form method="GET" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;">
        <label style="display:flex;flex-direction:column;gap:4px;font-size:12px;color:var(--ink-soft);">
            Zustand
            <select name="zustand" style="min-width:220px;">
                <option value="">Alle</option>
                @foreach(\App\Services\Ai\Assistant\Sales\ConversationState::LABELS as $key => $label)
                    <option value="{{ $key }}" @selected($zustand === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <button type="submit" class="btn btn-primary">Filtern</button>
    </form>
</div>

<div class="card">
    @if($leads->isEmpty())
        <div style="padding:24px;text-align:center;color:var(--ink-soft);">
            Noch keine Interessenten. Sobald ein Besucher den Assistenten auf der Website nutzt, erscheint er hier.
        </div>
    @else
    <div style="overflow-x:auto;">
        <table class="table">
            <thead>
                <tr>
                    <th>Eingang</th>
                    <th>Interessent</th>
                    <th>Anliegen</th>
                    <th>Angaben</th>
                    <th>Zustand</th>
                    <th>Nächster Schritt</th>
                </tr>
            </thead>
            <tbody>
            @foreach($leads as $lead)
                <tr>
                    <td style="white-space:nowrap;">{{ $lead->created_at->format('d.m.Y H:i') }}</td>
                    <td>
                        <strong>{{ $lead->displayName() }}</strong>
                        @php $kontakt = $lead->contactData(); @endphp
                        @if(!empty($kontakt['email']))<div style="font-size:12px;color:var(--ink-soft);">{{ $kontakt['email'] }}</div>@endif
                        @if(!empty($kontakt['phone']))<div style="font-size:12px;color:var(--ink-soft);">{{ $kontakt['phone'] }}</div>@endif
                    </td>
                    <td>{{ $lead->intentLabel() }}</td>
                    <td style="font-size:12px;">
                        @foreach($lead->collectedData() as $key => $wert)
                            @continue(in_array($key, ['name','email','phone'], true))
                            <div><span style="color:var(--ink-soft);">{{ $key }}:</span> {{ \Illuminate\Support\Str::limit($wert, 60) }}</div>
                        @endforeach
                    </td>
                    <td><span class="badge">{{ $lead->stateLabel() }}</span></td>
                    <td style="font-size:12px;">{{ $lead->next_action ?: 'Interessent kontaktieren' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <div style="margin-top:14px;">{{ $leads->links() }}</div>
    @endif
</div>
@endsection
