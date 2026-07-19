@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb">
        <a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span>
        <a href="{{ route('admin.customers') }}">Kunden</a><span class="breadcrumb-sep">›</span>
        <a href="{{ route('admin.customer', $customer->id) }}">{{ $customer->user?->name }}</a><span class="breadcrumb-sep">›</span>
        <span>Timeline</span>
    </div>
    <div class="page-title">Timeline — {{ $customer->user?->name }}</div>
</div>

<div style="max-width:700px;">
@php
$allEvents = collect();
foreach($customer->timeline as $t) {
    $allEvents->push(['date' => $t->created_at, 'type' => $t->type, 'title' => $t->title, 'desc' => $t->description, 'user' => $t->user?->name, 'url' => null]);
}
foreach($customer->contracts as $c) {
    $allEvents->push(['date' => $c->created_at, 'type' => 'contract', 'title' => 'Vertrag hinzugefügt: ' . $c->insurer, 'desc' => $c->contract_number, 'user' => $c->added_by ?? 'System', 'url' => route('admin.contract.edit', $c->id)]);
}
foreach($customer->tickets as $t) {
    $allEvents->push(['date' => $t->created_at, 'type' => 'ticket', 'title' => 'Anfrage: ' . $t->subject, 'desc' => ucfirst($t->status), 'user' => $customer->user?->name, 'url' => route('admin.ticket', $t->id)]);
}
foreach($customer->documents as $d) {
    $allEvents->push(['date' => $d->created_at, 'type' => 'document', 'title' => 'Dokument hochgeladen: ' . $d->file_name, 'desc' => ucfirst($d->category), 'user' => 'System', 'url' => route('admin.documents.download', $d->id)]);
}
$allEvents = $allEvents->sortByDesc('date');
$typeIcons = ['contract'=>'📄','ticket'=>'💬','document'=>'📎','appointment'=>'📅','note'=>'📝','approval'=>'✅','default'=>'📌'];
$typeColors = ['contract'=>'#185FA5','ticket'=>'#B5651D','document'=>'#17A65B','appointment'=>'#6D28D9','note'=>'#5F5E5A','default'=>'var(--petrol)'];
@endphp

@forelse($allEvents as $event)
@php
$icon = $typeIcons[$event['type']] ?? $typeIcons['default'];
$color = $typeColors[$event['type']] ?? $typeColors['default'];
@endphp
<div style="display:flex;gap:14px;margin-bottom:20px;">
    <div style="display:flex;flex-direction:column;align-items:center;">
        <div style="width:36px;height:36px;border-radius:50%;background:{{ $color }}20;border:2px solid {{ $color }};display:flex;align-items:center;justify-content:center;font-size:16px;flex:none;">{{ $icon }}</div>
        <div style="width:2px;flex:1;background:var(--line);margin-top:4px;"></div>
    </div>
    <div style="flex:1;padding-bottom:16px;">
        @if(!empty($event['url']))
        <a href="{{ $event['url'] }}" style="font-weight:600;font-size:13px;color:inherit;">{{ $event['title'] }} <span style="color:var(--ink-soft);font-weight:400;">→</span></a>
        @else
        <div style="font-weight:600;font-size:13px;">{{ $event['title'] }}</div>
        @endif
        @if($event['desc'])<div style="font-size:12px;color:var(--ink-soft);margin-top:2px;">{{ $event['desc'] }}</div>@endif
        <div style="font-size:11px;color:var(--ink-soft);margin-top:4px;">
            {{ $event['user'] ?? 'System' }} · {{ \Carbon\Carbon::parse($event['date'])->format('d.m.Y H:i') }}
        </div>
    </div>
</div>
@empty
<div style="text-align:center;padding:32px;color:var(--ink-soft);">Keine Timeline-Einträge vorhanden.</div>
@endforelse
</div>
@endsection
