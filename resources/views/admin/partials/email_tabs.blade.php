{{-- Sub-Navigation des E-Mail-Moduls.

     "Verfassen" war bisher ein eigener Punkt in der Seitenleiste - eine
     AKTION an der Stelle, an der sonst Orte stehen. Sie gehoert dorthin,
     wo man ohnehin steht, wenn man schreiben will: in den Posteingang.
     $active: posteingang | verfassen | vorlagen | konten --}}
<div style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;">
    <a href="{{ route('admin.email_inbox') }}" class="rep-tab {{ ($active ?? '') === 'posteingang' ? 'rep-tab-active' : '' }}">Posteingang</a>
    <a href="{{ route('admin.email.compose') }}" class="rep-tab {{ ($active ?? '') === 'verfassen' ? 'rep-tab-active' : '' }}">✍️ Verfassen</a>
    <a href="{{ route('admin.templates') }}" class="rep-tab {{ ($active ?? '') === 'vorlagen' ? 'rep-tab-active' : '' }}">Vorlagen</a>
    @if(auth()->user()->role === 'admin')
    <a href="{{ route('admin.email_accounts.index') }}" class="rep-tab {{ ($active ?? '') === 'konten' ? 'rep-tab-active' : '' }}" style="margin-left:auto;">Postfach-Konten</a>
    @endif
</div>
@once
<style>
.rep-tab{padding:9px 18px;border-radius:999px;border:1px solid var(--line);background:#fff;font-size:13.5px;font-weight:600;color:var(--ink);text-decoration:none;}
.rep-tab:hover{background:#F4F7F5;}
.rep-tab-active{background:#131A17;color:#fff;border-color:#131A17;}
</style>
@endonce
