@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><span>Aktivitätsprotokoll</span></div>
    <div class="page-title">Aktivitätsprotokoll</div>
    <div class="page-sub">Alle Aktivitäten im System</div>
</div>

@php
$actionLabels = [
    'employee_created' => ['👤 Mitarbeiter erstellt','badge-active'],
    'employee_updated' => ['✏️ Mitarbeiter aktualisiert','badge-pending'],
    'employee_deleted' => ['🗑 Mitarbeiter gelöscht','badge-rejected'],
    'login' => ['🔑 Angemeldet','badge-open'],
    'ticket_created' => ['💬 Ticket erstellt','badge-open'],
    'ticket_replied' => ['↩️ Ticket beantwortet','badge-pending'],
    'contract_created' => ['📄 Vertrag erstellt','badge-active'],
    'approval_approved' => ['✅ Änderung genehmigt','badge-active'],
    'approval_rejected' => ['❌ Änderung abgelehnt','badge-rejected'],
    'customer_created' => ['👥 Kunde erstellt','badge-active'],
    'data_imported' => ['📥 Daten importiert','badge-open'],
    'email_sent' => ['📧 E-Mail gesendet','badge-open'],
];
@endphp

<div class="card" style="padding:0;overflow:hidden;">
    <table>
        <thead><tr style="background:#FAFAF8;">
            <th style="padding:12px 20px;">Zeitpunkt</th>
            <th>Benutzer</th>
            <th>Aktion</th>
            <th>Details</th>
        </tr></thead>
        <tbody>
        @forelse($logs as $log)
        @php
            $label = $actionLabels[$log->action] ?? [$log->action, 'badge-closed'];
            $meta = $log->meta ? json_decode($log->meta, true) : [];
        @endphp
        <tr>
            <td style="padding:13px 20px;font-size:13px;color:var(--ink-soft);white-space:nowrap;">
                {{ $log->created_at->format('d.m.Y H:i') }}
            </td>
            <td>
                <div style="display:flex;align-items:center;gap:8px;">
                    <div style="width:28px;height:28px;border-radius:50%;background:var(--petrol);color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex:none;">
                        {{ strtoupper(substr($log->user?->name ?? 'S', 0, 2)) }}
                    </div>
                    <span style="font-size:13px;font-weight:600;">{{ $log->user?->name ?? 'System' }}</span>
                </div>
            </td>
            <td><span class="badge {{ $label[1] }}">{{ $label[0] }}</span></td>
            <td style="font-size:13px;color:var(--ink-soft);">
                @if(isset($meta['name'])) {{ $meta['name'] }} @endif
                @if(isset($meta['email'])) · {{ $meta['email'] }} @endif
            </td>
        </tr>
        @empty
        <tr><td colspan="4" style="text-align:center;padding:32px;color:var(--ink-soft);">Noch keine Aktivitäten.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<div style="margin-top:16px;">{{ $logs->links() }}</div>
@endsection
