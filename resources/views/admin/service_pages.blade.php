@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><span>Leistungsseiten</span></div>
    <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:10px;">
        <div>
            <div class="page-title">Leistungsseiten</div>
            <div class="page-sub">Oeffentliche Seiten unter <code>/leistungen/…</code> – Definition, Kurzinfos und FAQ je Leistung, zweisprachig (DE/AR). Das Anfrageformular jeder Seite erzeugt ein Ticket.</div>
        </div>
        <a href="{{ route('admin.service_pages.create') }}" class="btn btn-primary">➕ Neue Leistungsseite</a>
    </div>
</div>

@if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
@if($errors->any())<div class="alert alert-error">{{ $errors->first() }}</div>@endif

<div class="card">
    <table style="width:100%;border-collapse:collapse;font-size:14px;">
        <thead>
            <tr style="text-align:left;border-bottom:1px solid var(--line);">
                <th style="padding:10px 8px;">#</th>
                <th style="padding:10px 8px;">Leistung</th>
                <th style="padding:10px 8px;">Slug</th>
                <th style="padding:10px 8px;">Status</th>
                <th style="padding:10px 8px;text-align:right;">Aktionen</th>
            </tr>
        </thead>
        <tbody>
        @forelse($pages as $page)
            <tr style="border-bottom:1px solid var(--line);">
                <td style="padding:10px 8px;color:var(--ink-soft);">{{ $page->sort_order }}</td>
                <td style="padding:10px 8px;">
                    <span style="font-size:16px;">{{ $page->icon }}</span>
                    <strong>{{ $page->title_de }}</strong>
                    @if($page->title_ar)<div style="color:var(--ink-soft);font-size:12px;" dir="rtl">{{ $page->title_ar }}</div>@endif
                </td>
                <td style="padding:10px 8px;"><a href="{{ url('/leistungen/' . $page->slug) }}" target="_blank" rel="noopener"><code>{{ $page->slug }}</code> ↗</a></td>
                <td style="padding:10px 8px;">
                    @if($page->is_active)
                        <span style="background:#D9F4E6;color:#17A65B;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;">Sichtbar</span>
                    @else
                        <span style="background:#F1EFE8;color:#5F5E5A;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;">Verborgen</span>
                    @endif
                </td>
                <td style="padding:10px 8px;text-align:right;white-space:nowrap;">
                    <a href="{{ route('admin.service_pages.edit', $page) }}" class="btn btn-ghost" style="padding:6px 12px;">Bearbeiten</a>
                    <form method="POST" action="{{ route('admin.service_pages.toggle', $page) }}" style="display:inline;">
                        @csrf
                        <button type="submit" class="btn btn-ghost" style="padding:6px 12px;">{{ $page->is_active ? 'Verbergen' : 'Anzeigen' }}</button>
                    </form>
                    <form method="POST" action="{{ route('admin.service_pages.delete', $page) }}" style="display:inline;" onsubmit="return confirm('Diese Leistungsseite wirklich loeschen?');">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-ghost" style="padding:6px 12px;color:#A32D2D;">Loeschen</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="5" style="padding:20px 8px;color:var(--ink-soft);">Noch keine Leistungsseiten. Legen Sie die erste an.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
