{{-- Meldungen. Eigene Datei, damit jede Seite dieser Gruppe dieselbe
     Darstellung hat - sonst sieht ein Fehler auf der einen Seite wie ein
     Hinweis auf der anderen aus. --}}
@if(session('success'))
<div style="background:#D9F4E6;border:1px solid #9BD9BB;border-radius:10px;padding:14px 16px;margin-bottom:20px;max-width:1200px;font-size:13px;">{{ session('success') }}</div>
@endif
@if(session('error'))
<div style="background:#F9E3E3;border:1px solid #F0A0A0;border-radius:10px;padding:14px 16px;margin-bottom:20px;max-width:1200px;font-size:13px;color:#A32D2D;">{{ session('error') }}</div>
@endif
@if($errors->any())
<div style="background:#F9E3E3;border:1px solid #F0A0A0;border-radius:10px;padding:14px 16px;margin-bottom:20px;max-width:1200px;font-size:13px;color:#A32D2D;">
    @foreach($errors->all() as $error)<div>• {{ $error }}</div>@endforeach
</div>
@endif
