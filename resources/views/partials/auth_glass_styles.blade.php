{{-- Gemeinsame Glas-Karten-Optik der Gastseiten (Login-Look), damit die
     Passwort-Reset-Strecke on-brand ist statt Breeze-Grau. (Audit UX-8)
     Erwartet eine Variable $rtl im umgebenden Scope. --}}
<style>
:root{--green:#17A65B;--mint:#3ddc8e;--line:rgba(255,255,255,.14);}
*{box-sizing:border-box;margin:0;padding:0;}
html,body{height:100%;}
body{font-family:'Inter',Arial,sans-serif;min-height:100vh;color:#fff;display:flex;flex-direction:column;background:#0B1310;}
.bg{position:fixed;inset:0;z-index:-1;background:radial-gradient(1200px 800px at 70% 15%, #1A2C24 0%, #0F1512 48%, #0B1310 100%);}
.bg::after{content:'';position:absolute;inset:0;background-image:radial-gradient(rgba(255,255,255,.05) 1px,transparent 1px);background-size:26px 26px;}
.topbar{flex:none;display:flex;align-items:center;justify-content:space-between;max-width:1200px;width:100%;margin:0 auto;padding:18px 28px 0;}
.topbar img{height:36px;width:auto;display:block;}
.lang-switch a{display:inline-flex;align-items:center;gap:7px;background:rgba(255,255,255,.08);border:1px solid var(--line);color:#dde0e5;text-decoration:none;font-size:13px;padding:7px 13px;border-radius:9px;}
.main{flex:1;min-height:0;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:24px 16px;text-align:center;gap:14px;}
h1{font-size:clamp(20px,3vh,28px);letter-spacing:-.4px;line-height:1.2;}
.sub{color:#b7bcc4;font-size:14px;line-height:1.5;max-width:520px;}
.card{background:rgba(255,255,255,.06);border:1px solid var(--line);border-radius:18px;padding:28px 30px;max-width:430px;width:100%;backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);box-shadow:0 24px 60px rgba(0,0,0,.35);text-align:{{ $rtl ? 'right' : 'left' }};}
.card h2{font-size:20px;color:var(--mint);margin-bottom:16px;}
label{display:block;font-size:13px;margin-bottom:6px;color:#dde0e5;}
.field{position:relative;margin-bottom:16px;}
.field input{width:100%;background:rgba(0,0,0,.25);border:1px solid var(--line);border-radius:10px;color:#fff;font-size:14.5px;padding:12px 13px;outline:none;transition:border-color .2s;}
.field input:focus{border-color:var(--green);}
.btn{width:100%;background:linear-gradient(180deg,#19b463,#128a4b);border:1px solid #1fc06e;color:#fff;font-size:15.5px;font-weight:700;padding:13px;border-radius:11px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:10px;transition:transform .15s, box-shadow .2s, filter .2s;}
.btn:hover{filter:brightness(1.08);transform:translateY(-1px);box-shadow:0 10px 26px rgba(23,166,91,.35);}
.error{background:rgba(226,75,74,.15);border:1px solid rgba(226,75,74,.4);color:#ffb9b8;border-radius:9px;padding:9px 12px;font-size:13px;margin-bottom:12px;}
.status{background:rgba(23,166,91,.15);border:1px solid rgba(23,166,91,.45);color:#5fe3a1;border-radius:9px;padding:9px 12px;font-size:13px;margin-bottom:12px;}
.back-line{text-align:center;font-size:13px;color:#b7bcc4;margin-top:14px;}
.back-line a{color:var(--mint);font-weight:700;text-decoration:none;}
.foot{flex:none;display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:6px 18px;padding:14px 16px;font-size:12.5px;color:#9aa1ab;}
.foot a{color:#c2c7cf;text-decoration:none;}
.foot a:hover{color:#fff;text-decoration:underline;}
</style>
