{{-- Gemeinsame Styles der Provisions-Seiten (Tabs, Badges, Filter, Balken). --}}
<style>
.rep-tab { padding:9px 18px; border-radius:999px; border:1px solid var(--line); background:#fff; font-size:13.5px; font-weight:600; color:var(--ink); text-decoration:none; }
.rep-tab:hover { background:#F4F7F5; }
.rep-tab-active { background:#131A17; color:#fff; border-color:#131A17; }
.flt-group { display:flex; flex-direction:column; gap:4px; }
.flt-lbl { font-size:11.5px; color:var(--ink-soft); font-weight:600; }
.flt-sel { padding:8px 12px; border:1px solid var(--line); border-radius:8px; font-size:13.5px; background:#fff; min-width:130px; }
.wb-badge { display:inline-flex; align-items:center; gap:5px; padding:4px 10px; border-radius:999px; font-size:12px; font-weight:600; white-space:nowrap; }
.wb-mit { background:#D9F4E6; color:#0E7A41; }
.wb-par { background:#F3EDDC; color:#8A7440; }
.wb-gold { background:#F3EDDC; color:#8A7440; border:1px solid #D1C18F; }
.wb-none { background:#EEF0F3; color:var(--ink-soft); }
.wb-offen { background:#F7E7D6; color:#B5651D; }
.wb-frei { background:#E6F1FB; color:#185FA5; }
.wb-storno { background:#F9E3E3; color:#A32D2D; }
.pv-bar { height:6px; background:#E9E6DC; border-radius:999px; overflow:hidden; margin-top:6px; }
.pv-bar > span { display:block; height:100%; background:linear-gradient(90deg,#19b463,#128a4b); border-radius:999px; }
.pv-bar-gold > span { background:linear-gradient(90deg,#D1C18F,#B8A16B); }
</style>
