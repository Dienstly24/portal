@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><a href="{{ route('admin.settings') }}">Einstellungen</a><span class="breadcrumb-sep">›</span><span>E-Mail-Postfächer</span></div>
    <div style="display:flex;align-items:center;justify-content:space-between;">
        <div>
            <div class="page-title">E-Mail-Postfächer</div>
            <div class="page-sub">Zentrale Verwaltung der Postfächer für die automatisierte E-Mail-Verarbeitung.</div>
        </div>
        <a href="{{ route('admin.email_accounts.create') }}" class="btn btn-gold">+ Postfach hinzufügen</a>
    </div>
</div>

@if(session('success'))<div style="background:#E4F0E7;color:#3B7A57;padding:10px 16px;border-radius:8px;margin-bottom:16px;">{{ session('success') }}</div>@endif
@if(session('error'))<div style="background:#FBE9E9;color:#B3261E;padding:10px 16px;border-radius:8px;margin-bottom:16px;">{{ session('error') }}</div>@endif

<div class="card" style="padding:0;overflow:hidden;">
    <table>
        <thead><tr style="background:#FAFAF8;">
            <th style="padding:12px 20px;">Postfach</th>
            <th>Anbieter</th>
            <th>Ordner</th>
            <th>Status</th>
            <th>Letzter Sync</th>
            <th>Mails</th>
            <th></th>
        </tr></thead>
        <tbody>
        @forelse($accounts as $a)
        <tr>
            <td style="padding:14px 20px;">
                <div style="font-weight:600;font-size:14px;">{{ $a->name }}</div>
                <div style="font-size:12px;color:var(--ink-soft);">{{ $a->email_address }}</div>
                @if($a->last_error)
                    <div style="font-size:11px;color:#B3261E;margin-top:4px;">⚠ {{ Str::limit($a->last_error, 80) }}</div>
                @endif
            </td>
            <td>{{ \App\Models\EmailAccount::PROVIDERS[$a->provider] ?? $a->provider }}</td>
            <td style="font-size:12px;color:var(--ink-soft);">{{ implode(', ', $a->watchedFolders()) }}</td>
            <td>
                <span class="badge {{ $a->is_active ? 'badge-active' : 'badge-pending' }}">{{ $a->is_active ? 'Aktiv' : 'Inaktiv' }}</span>
            </td>
            <td style="font-size:13px;color:var(--ink-soft);">{{ $a->last_synced_at?->format('d.m.Y H:i') ?? 'noch nie' }}</td>
            <td style="font-size:13px;color:var(--ink-soft);">{{ $a->messages_count }}</td>
            <td style="padding-right:20px;white-space:nowrap;">
                <form method="POST" action="{{ route('admin.email_accounts.test', $a->id) }}" style="display:inline;">@csrf<button type="submit" class="btn btn-ghost btn-sm">Testen</button></form>
                <a href="{{ route('admin.email_accounts.edit', $a->id) }}" class="btn btn-ghost btn-sm">Bearbeiten</a>
                <form method="POST" action="{{ route('admin.email_accounts.toggle', $a->id) }}" style="display:inline;">@csrf @method('PUT')<button type="submit" class="btn btn-ghost btn-sm">{{ $a->is_active ? 'Deaktivieren' : 'Aktivieren' }}</button></form>
            </td>
        </tr>
        @empty
        <tr><td colspan="7" style="text-align:center;padding:32px;color:var(--ink-soft);">Noch keine Postfächer eingerichtet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
