{{--
    Aenderungsverlauf (Version History / Audit Log) eines Vertrags. Zeigt je
    Feld den alten und den neuen Wert, wann, aus welcher Quelle und durch wen
    die Aenderung erfolgte. Wird gefuellt, sobald ein neu importiertes Dokument
    einen bestehenden Vertrag aktualisiert (statt ein Duplikat anzulegen).
    Erwartet $contract mit geladenen revisions (inkl. changedBy).
--}}
@php $revisions = $contract->revisions; @endphp
@if($revisions->isNotEmpty())
<div class="card" style="max-width:980px;margin-top:20px;">
    <div class="card-title" style="margin-bottom:6px;">🕓 Änderungsverlauf</div>
    <p style="font-size:12.5px;color:var(--ink-soft);margin-bottom:14px;">
        Jede Änderung an diesem Vertrag - z.B. aus einem neu importierten Dokument -
        wird hier mit altem und neuem Wert festgehalten. So bleibt genau ein Vertrag
        je Fahrzeug bestehen, mit vollständiger Historie.
    </p>
    <table style="width:100%;border-collapse:collapse;font-size:13px;">
        <thead>
            <tr style="background:var(--canvas);">
                <th style="text-align:left;padding:8px 10px;font-size:11.5px;color:var(--ink-soft);border-bottom:1px solid var(--line);white-space:nowrap;">Datum</th>
                <th style="text-align:left;padding:8px 10px;font-size:11.5px;color:var(--ink-soft);border-bottom:1px solid var(--line);">Feld</th>
                <th style="text-align:left;padding:8px 10px;font-size:11.5px;color:var(--ink-soft);border-bottom:1px solid var(--line);">Alter Wert</th>
                <th style="text-align:left;padding:8px 10px;font-size:11.5px;color:var(--ink-soft);border-bottom:1px solid var(--line);">Neuer Wert</th>
                <th style="text-align:left;padding:8px 10px;font-size:11.5px;color:var(--ink-soft);border-bottom:1px solid var(--line);white-space:nowrap;">Quelle</th>
                <th style="text-align:left;padding:8px 10px;font-size:11.5px;color:var(--ink-soft);border-bottom:1px solid var(--line);white-space:nowrap;">Bearbeiter</th>
            </tr>
        </thead>
        <tbody>
        @foreach($revisions as $rev)
            <tr style="border-bottom:1px solid var(--line);">
                <td style="padding:8px 10px;color:var(--ink-soft);white-space:nowrap;">{{ $rev->created_at->format('d.m.Y H:i') }}</td>
                <td style="padding:8px 10px;font-weight:600;">{{ $rev->label ?? $rev->field }}</td>
                <td style="padding:8px 10px;color:#A32D2D;">{{ $rev->old_value ?? '—' }}</td>
                <td style="padding:8px 10px;color:#17A65B;font-weight:600;">{{ $rev->new_value ?? '—' }}</td>
                <td style="padding:8px 10px;color:var(--ink-soft);white-space:nowrap;">{{ $rev->sourceLabel() }}</td>
                <td style="padding:8px 10px;color:var(--ink-soft);white-space:nowrap;">{{ $rev->actorName() }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endif
