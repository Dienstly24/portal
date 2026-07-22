{{--
    E-Scooter-Cockpit: kompakter Ueberblick oben auf der Bearbeiten-Seite,
    analog zum KFZ-Cockpit. Zeigt Kennzeichen, Fahrzeug, Fahrgestellnummer,
    Deckung (Haftpflicht/Teilkasko), Saison-Ablauf und den Einmalbeitrag.
    Erwartet $contract (mit geladenem vehicleDetail).
--}}
@php
    $veh = $contract->vehicleDetail;
@endphp
@if($contract->type === 'escooter')
@php
    $eur = fn($v) => number_format((float) $v, 2, ',', '.') . ' €';
    $d = fn($v) => $v ? \Carbon\Carbon::parse($v)->format('d.m.Y') : '—';
@endphp
<div class="card" style="max-width:980px;background:linear-gradient(135deg,#131A17,#0F1512);border-color:#0F1512;color:#fff;">
    <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
        <span style="font-size:34px;line-height:1;">🛴</span>
        <div style="min-width:200px;">
            <div style="font-size:17px;font-weight:800;letter-spacing:.02em;">
                {{ $veh?->license_plate ?: 'Kennzeichen —' }}
            </div>
            <div style="font-size:12.5px;color:#B9BFC9;">
                {{ trim(($veh?->manufacturer ?? '') . ' ' . ($veh?->model ?? '')) ?: 'E-Scooter —' }}
                @if($veh?->vin) · FIN {{ $veh->vin }}@endif
            </div>
        </div>
        <div style="margin-left:auto;text-align:right;">
            <div style="font-size:12px;color:#B9BFC9;">{{ $contract->insurer }}@if($contract->contract_number) · VSNR {{ $contract->contract_number }}@endif</div>
            @if($contract->hasPremium())
            <div style="font-size:15px;font-weight:800;color:#2BC777;">{{ $eur($contract->premium_amount) }} <span style="font-size:11.5px;color:#B9BFC9;font-weight:600;">/ {{ $contract->premiumIntervalLabel() }}</span></div>
            @endif
        </div>
    </div>

    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:14px;">
        @php $chip = 'display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:999px;font-size:12px;font-weight:700;'; @endphp
        <span style="{{ $chip }}background:#17A65B;color:#fff;">✓ Haftpflicht</span>
        @if($veh?->has_teilkasko)<span style="{{ $chip }}background:#17A65B;color:#fff;">✓ Teilkasko</span>
        @else<span style="{{ $chip }}background:#2A2E36;color:#8A919E;">✗ Teilkasko</span>@endif
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(215px,1fr));gap:10px;margin-top:14px;">
        <div style="background:#1B1E24;border:1px solid #2A2E36;border-radius:10px;padding:10px 12px;">
            <div style="font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:#8A919E;font-weight:700;">Versicherungsbeginn</div>
            <div style="font-size:14px;font-weight:800;margin-top:3px;">{{ $d($contract->start_date) }}</div>
        </div>
        <div style="background:#1B1E24;border:1px solid #2A2E36;border-radius:10px;padding:10px 12px;">
            <div style="font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:#8A919E;font-weight:700;">Versicherungsende</div>
            <div style="font-size:14px;font-weight:800;margin-top:3px;">{{ $d($contract->end_date) }}</div>
            <div style="font-size:11.5px;color:#8A919E;margin-top:3px;">bedarf keiner Kuendigung</div>
        </div>
    </div>
</div>
@endif
