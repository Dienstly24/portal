@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><span>Mitarbeiter</span></div>
    <div style="display:flex;align-items:center;justify-content:space-between;">
        <div>
            <div class="page-title">Mitarbeiter</div>
            <div class="page-sub">Verwalten Sie Ihr Team und deren Zugriffsrechte.</div>
        </div>
        <a href="{{ route('admin.employees.create') }}" class="btn btn-gold">+ Neuer Mitarbeiter</a>
    </div>
</div>

<div class="card" style="padding:0;overflow:hidden;">
    <table>
        <thead><tr style="background:#F8F9FA;">
            <th style="padding:12px 20px;">Mitarbeiter</th>
            <th>Rolle</th>
            <th>Zugriff</th>
            <th>Berechtigungen</th>
            <th>Zugewiesene Kunden</th>
            <th></th>
        </tr></thead>
        <tbody>
        @forelse($employees as $e)
        <tr>
            <td style="padding:14px 20px;">
                <div style="display:flex;align-items:center;gap:10px;">
                    <div style="width:36px;height:36px;border-radius:50%;background:var(--petrol);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;">{{ strtoupper(substr($e->name,0,2)) }}</div>
                    <div>
                        <div style="font-weight:600;font-size:14px;"><a href="{{ route('admin.employees.show', $e->id) }}" style="color:var(--ink);text-decoration:none;">{{ $e->name }}</a> @if(!$e->is_active)<span style="background:#EEE;color:#888;border-radius:10px;padding:1px 8px;font-size:11px;margin-left:6px;">Deaktiviert</span>@endif</div>
                        <div style="font-size:12px;color:var(--ink-soft);">{{ $e->email }}</div>
                    </div>
                </div>
            </td>
            <td>
                <span class="badge {{ $e->isAdmin() ? 'badge-active' : 'badge-open' }}">
                    {{ $e->isAdmin() ? 'Admin' : 'Mitarbeiter' }}
                </span>
            </td>
            <td>
                <span class="badge {{ $e->can_see_all_customers ? 'badge-active' : 'badge-pending' }}">
                    {{ $e->can_see_all_customers ? 'Alle Kunden' : 'Begrenzt' }}
                </span>
            </td>
            <td style="font-size:12px;">
                <div style="display:flex;flex-wrap:wrap;gap:4px;">
                    @if($e->can_manage_contracts)<span style="background:#D9F4E6;color:#17A65B;padding:2px 7px;border-radius:4px;">Verträge</span>@endif
                    @if($e->can_manage_tickets)<span style="background:#E6F1FB;color:#185FA5;padding:2px 7px;border-radius:4px;">Tickets</span>@endif
                    @if($e->can_approve_changes)<span style="background:#FEF3C7;color:#92400E;padding:2px 7px;border-radius:4px;">Genehmigungen</span>@endif
                    @if($e->can_send_emails)<span style="background:#F0E6FB;color:#6D28D9;padding:2px 7px;border-radius:4px;">E-Mails</span>@endif
                    @if($e->can_import_export)<span style="background:#EEF0F3;color:#5F5E5A;padding:2px 7px;border-radius:4px;">Import/Export</span>@endif
                </div>
            </td>
            <td style="font-size:13px;">
                @if($e->can_see_all_customers)
                    <span style="color:var(--ink-soft);">Alle</span>
                @else
                    <a href="{{ route('admin.employees.show', $e->id) }}" style="color:#17A65B;font-weight:600;text-decoration:none;">{{ $e->assignedCustomers()->count() }} Kunden ansehen →</a>
                @endif
            </td>
            <td style="padding-right:20px;white-space:nowrap;">
                @if(!$e->isAdmin() || auth()->user()->isAdmin())
                <a href="{{ route('admin.employees.show', $e->id) }}" class="btn btn-ghost btn-sm">Öffnen</a>
                <a href="{{ route('admin.employees.edit', $e->id) }}" class="btn btn-ghost btn-sm">Bearbeiten</a>
                @endif
            </td>
        </tr>
        @empty
        <tr><td colspan="6" style="text-align:center;padding:32px;color:var(--ink-soft);">Noch keine Mitarbeiter.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
