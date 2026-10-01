{{--
    "Erstwagen fuer ..." (01.10.2026): zeigt an einem Vertrag, welche anderen
    Vertraege ihre SF-Sondereinstufung auf ihn stuetzen. Erwartet $contract
    (mit geladenem sfDependents.vehicleDetail.contract). $dark = Cockpit-Stil.
--}}
@php
    $sfDeps = $contract->type === 'kfz'
        ? $contract->sfDependents->map(fn ($r) => $r->vehicleDetail?->contract)->filter()->unique('id')->values()
        : collect();
@endphp
@if($sfDeps->isNotEmpty())
<span class="sf-erstwagen-badge" style="display:inline-flex;flex-wrap:wrap;align-items:center;gap:4px;font-size:12px;font-weight:600;padding:3px 10px;border-radius:999px;{{ ($dark ?? false) ? 'background:#2A2618;color:#E8B25A;border:1px solid #4A4128;' : 'background:#FDF8EC;color:#7A6328;border:1px solid var(--gold);' }}">
    🔗 Erstwagen für:
    @foreach($sfDeps as $dep)
    <a href="{{ route('admin.contract.edit', $dep->id) }}" style="color:inherit;text-decoration:underline;">{{ \App\Models\VehicleSfReference::labelFor($dep) }}</a>@if(! $loop->last),@endif
    @endforeach
</span>
@endif
