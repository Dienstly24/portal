{{-- Gemeinsame Chat-Bausteine (d24c-*): Blasen, Tagestrenner, Lesehaken,
     Anhaenge, Chips, Composer. Genutzt vom Kundenportal (Nachrichten-Seite
     + schwebendes Widget) und vom Kunden-Chat der Beraterwelt, damit der
     Chat ueberall identisch aussieht. --}}
<style>
.d24c-av{width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,#19b463,#128a4b);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:12.5px;flex:none;letter-spacing:.02em;}
.d24c-scroll{flex:1;min-height:0;overflow-y:auto;background:#EFEBDF;-webkit-overflow-scrolling:touch;}
.d24c-list{display:flex;flex-direction:column;gap:8px;padding:16px 14px;min-height:100%;}
.d24c-day{align-self:center;background:rgba(19,26,23,.08);color:var(--ink-soft);font-size:11px;font-weight:600;border-radius:999px;padding:2px 11px;}
.d24c-empty{align-self:center;margin:auto;text-align:center;color:var(--ink-soft);font-size:13.5px;padding:26px 18px;line-height:1.7;max-width:340px;}
.d24c-bub{max-width:76%;padding:9px 12px;border-radius:13px;font-size:14px;line-height:1.55;box-shadow:0 1px 1px rgba(0,0,0,.06);display:flex;flex-direction:column;gap:2px;}
.d24c-bub.them{background:#fff;align-self:flex-start;border-start-start-radius:4px;}
.d24c-bub.me{background:var(--gold-soft,#d9f4e6);align-self:flex-end;border-start-end-radius:4px;}
.d24c-sender{font-size:11.5px;font-weight:700;color:#128a4b;}
.d24c-body{white-space:pre-line;word-break:break-word;}
.d24c-att{display:flex;align-items:center;flex-wrap:wrap;gap:6px;background:rgba(19,26,23,.06);border-radius:9px;padding:6px 9px;margin-top:4px;font-size:12.5px;}
.d24c-att-n{font-weight:600;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:100%;}
.d24c-attbtns{display:flex;gap:6px;}
.d24c-attbtns a{display:inline-flex;align-items:center;gap:4px;background:#fff;border:1px solid var(--line);border-radius:999px;padding:3px 10px;text-decoration:none;color:var(--ink);font-size:11.5px;}
.d24c-tm{font-size:10.5px;color:var(--ink-soft);align-self:flex-end;display:inline-flex;gap:3px;align-items:center;}
.d24c-ticks{color:#9AA79E;font-size:10.5px;letter-spacing:-1px;}
.d24c-ticks.read{color:#128a4b;}
.d24c-chips{display:flex;gap:8px;padding:9px 12px;background:var(--surface);border-top:1px solid var(--line);overflow-x:auto;scrollbar-width:none;}
.d24c-chip{flex:none;font-size:12.5px;font-weight:600;border:1px solid var(--line);background:#fff;color:var(--ink);border-radius:999px;padding:6px 13px;text-decoration:none;white-space:nowrap;transition:.15s;}
.d24c-chip:hover{border-color:#17A65B;color:#128a4b;}
.d24c-files{display:flex;align-items:center;gap:8px;padding:7px 14px;background:var(--surface);border-top:1px solid var(--line);font-size:12.5px;color:var(--ink-soft);}
.d24c-files .d24c-clear{margin-inline-start:auto;border:none;background:none;cursor:pointer;font-size:13px;color:var(--ink-soft);}
.d24c-comp{display:flex;align-items:flex-end;gap:8px;padding:10px 12px;background:var(--surface);border-top:1px solid var(--line);}
.d24c-clip{display:inline-flex;align-items:center;justify-content:center;width:40px;height:40px;border-radius:50%;font-size:19px;cursor:pointer;flex:none;color:var(--ink-soft);}
.d24c-clip:hover{background:var(--canvas);}
.d24c-inp{flex:1;background:var(--canvas);border:1px solid var(--line);border-radius:20px;padding:9px 15px;font-size:14px;resize:none;max-height:110px;min-height:40px;font-family:inherit;color:var(--ink);}
.d24c-inp:focus{outline:2px solid #17A65B;outline-offset:1px;background:#fff;}
.d24c-send{width:42px;height:42px;border-radius:50%;border:none;background:linear-gradient(135deg,#19b463,#128a4b);color:#fff;font-size:16px;cursor:pointer;flex:none;box-shadow:0 4px 12px rgba(18,138,75,.35);display:flex;align-items:center;justify-content:center;}
.d24c-send:disabled{opacity:.6;cursor:default;}
[dir=rtl] .d24c-send .snd-ico{display:inline-block;transform:scaleX(-1);}
</style>
