{{-- Signaturen zu EINEM Bezugsobjekt (Kunde oder Vertrag).

     Bewusst als eigener Baustein: die Kundenakte und die Vertragsakte
     zeigen dieselbe Information, und zwei Kopien laufen erfahrungsgemaess
     auseinander (dieselbe Lehre wie bei den Bausteinen aus UX-2).

     Erwartet: $signatures (Sammlung) und $neueSignaturUrl (Link "Zur
     Unterschrift senden"). --}}
<div class="card-title" style="font-size:14px;margin:22px 0 10px;display:flex;justify-content:space-between;align-items:center;gap:10px;">
    <span>✍️ Signaturen</span>
    <a href="{{ $neueSignaturUrl }}" class="btn btn-ghost btn-sm">+ Zur Unterschrift senden</a>
</div>

@forelse($signatures as $signature)
<a href="{{ route('admin.signatures.show', $signature->id) }}" class="row-link"
   style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 0;border-bottom:1px solid var(--line);font-size:13px;color:inherit;text-decoration:none;">
    <div>
        <span style="font-weight:600;">{{ $signature->title }}</span>
        <span class="muted">
            · {{ $signature->signedCount() }}/{{ $signature->signers->count() }} unterschrieben
            @if($signature->expires_at) · gültig bis {{ $signature->expires_at->lokal()->format('d.m.Y') }}@endif
        </span>
    </div>
    <span style="display:flex;gap:6px;align-items:center;">
        <span class="badge {{ $signature->statusTone() }}">{{ $signature->statusLabel() }}</span>
        <span style="color:var(--ink-soft);font-size:12px;">→</span>
    </span>
</a>
@empty
<p class="muted-sm">Noch keine Signaturanfrage.</p>
@endforelse
