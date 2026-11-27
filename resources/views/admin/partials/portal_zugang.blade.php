{{-- Portal-Status (echter Zustand statt "aktiv/inaktiv"-Raten) --}}
@php $portalUser = $customer->user; $ps = $customer->portalStatus(); @endphp
{{-- Karte unten in der Uebersicht (Vorlage 27.11.2026): Titel, Status,
     ein Satz zum Zustand; alles Weitere aufklappbar. --}}
<details class="kd-karte kk-portal" id="kk-portal">
    <summary class="kk-portal-zeile">
        <span class="kd-karte-icon">🌐</span>
        <span style="min-width:0;">
            <span class="kd-karte-titel" style="display:inline-flex;">Portal-Zugang</span>
            <span style="background:{{ $ps['bg'] }};color:{{ $ps['color'] }};border-radius:999px;padding:2px 10px;font-size:12px;font-weight:600;margin-left:6px;">{{ $ps['label'] }}</span>
            <span class="kk-portal-satz">
                @if($portalUser?->first_login_at)
                    Der Kunde hat Zugang zum Kundenportal (zuletzt angemeldet {{ $portalUser->last_login_at?->lokal()->format('d.m.Y') ?? $portalUser->first_login_at->lokal()->format('d.m.Y') }}).
                @elseif($portalUser?->portal_password_set_at)
                    Zugang eingerichtet – der Kunde hat sich noch nicht angemeldet.
                @else
                    Der Kunde hat noch keinen Zugang zum Kundenportal.
                @endif
            </span>
        </span>
        <span class="kk-portal-mehr">Details anzeigen</span>
    </summary>
    <div style="display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;align-items:flex-start;margin-top:12px;">
        <div style="min-width:260px;">
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:10px;">
                <span style="background:{{ $ps['bg'] }};color:{{ $ps['color'] }};border-radius:12px;padding:3px 12px;font-size:12.5px;font-weight:600;">{{ $ps['label'] }}</span>
                <span style="font-size:12.5px;color:var(--ink-soft);">Account vorhanden: <strong>{{ $portalUser && $portalUser->hasRealEmail() ? 'Ja' : 'Nein' }}</strong></span>
                <span style="font-size:12.5px;color:var(--ink-soft);">Passwort gesetzt: <strong>{{ $portalUser?->portal_password_set_at ? 'Ja' : 'Nein' }}</strong></span>
            </div>
            <div style="display:grid;grid-template-columns:auto auto;gap:2px 18px;font-size:12.5px;color:var(--ink-soft);">
                <span>Einladung gesendet:</span><strong>{{ $portalUser?->invitation_sent_at?->lokal()->format('d.m.Y H:i') ?? '—' }}</strong>
                {{-- Bewusst "Passwort eingerichtet" statt "Account aktiviert": das
                     Startpasswort setzt das SYSTEM beim Einladungsversand - ob der
                     Kunde aktiv wurde, zeigt erst "Erster Login". --}}
                <span>Passwort eingerichtet:</span><strong>{{ $portalUser?->portal_password_set_at?->lokal()->format('d.m.Y H:i') ?? '—' }}</strong>
                <span>Erster Login:</span><strong>{{ $portalUser?->first_login_at?->lokal()->format('d.m.Y H:i') ?? '—' }}</strong>
                <span>Letzter Login:</span><strong>{{ $portalUser?->last_login_at?->lokal()->format('d.m.Y H:i') ?? '—' }}</strong>
            </div>
            @if(!$customer->birth_date)
            <div style="margin-top:10px;background:#FEF3C7;border:1px solid #F0E0B0;border-radius:8px;padding:9px 12px;font-size:12.5px;color:#92400E;line-height:1.55;max-width:520px;">
                ⚠ <strong>Kein Geburtsdatum hinterlegt:</strong> Die Einladung enthält dann <strong>kein Startpasswort</strong>
                (Regel: Geburtsdatum TT.MM.JJJJ), sondern nur einen zeitlich begrenzten Link zum Passwort-Setzen –
                viele Kunden aktivieren den Zugang so nie.
                <a href="{{ route('admin.customer.edit', $customer->id) }}" style="color:#92400E;font-weight:700;">Geburtsdatum jetzt ergänzen →</a>
            </div>
            @endif
        </div>
        @if(auth()->user()->role === 'admin')
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-start;">
            <form method="POST" action="{{ route('admin.customer.portal.invite', $customer->id) }}"
                data-confirm="{{ $customer->birth_date
                    ? 'Einladung mit Startpasswort (Geburtsdatum TT.MM.JJJJ) an den Kunden senden?'
                    : 'ACHTUNG: Kein Geburtsdatum hinterlegt! Die Einladung enthaelt dann KEIN Startpasswort, nur einen zeitlich begrenzten Passwort-Link. Besser zuerst das Geburtsdatum ergaenzen (Bearbeiten). Trotzdem jetzt senden?' }}');">
                @csrf<button type="submit" class="btn btn-emerald btn-sm">📧 Einladung senden</button>
            </form>
            <form method="POST" action="{{ route('admin.customer.portal.reset_link', $customer->id) }}">
                @csrf<button type="submit" class="btn btn-ghost btn-sm">🔑 Reset-Link senden</button>
            </form>
            <form method="POST" action="{{ route('admin.customer.portal.reset', $customer->id) }}"
                data-h-submit="1ad1ce2c1c">
                @csrf<button type="submit" class="btn btn-ghost btn-sm">↺ Portal zurücksetzen</button>
            </form>
            <form method="POST" action="{{ route('admin.customer.portal.toggle', $customer->id) }}"
                data-confirm="{{ ($portalUser?->is_active ?? true) ? 'Portal-Login für diesen Kunden deaktivieren?' : 'Portal-Login wieder aktivieren?' }}">
                @csrf<button type="submit" class="btn btn-ghost btn-sm">{{ ($portalUser?->is_active ?? true) ? '🚫 Deaktivieren' : '✅ Aktivieren' }}</button>
            </form>
        </div>
        @endif
    </div>
</details>
