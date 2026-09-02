{{-- Gemeinsamer Kopf aller Seiten des Provisionsmanagements: Titel,
     Vertraulichkeits-Hinweis und die eine Navigation. Er steht an EINER
     Stelle, damit der Hinweis "intern" nie auf einer Seite fehlt. --}}
@include('admin.partials.provision_styles')
<div class="page-header">
    <div class="page-title">💶 Provisionsmanagement<span style="font-weight:400;color:var(--ink-soft);"> · {{ $titel }}</span></div>
    <div class="page-sub">
        {{ $untertitel ?? 'Alle Provisionen aus allen Pools an einer Stelle.' }}
        <b>Intern und vertraulich</b> – diese Angaben erreichen weder Kunden noch Mitarbeiter ohne Provisionsrecht.
    </div>
</div>
@include('admin.commissions_internal._tabs', ['active' => $active])
@include('admin.commissions_internal._flash')
