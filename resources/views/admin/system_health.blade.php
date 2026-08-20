@extends('layouts.admin')
@section('content')
@php
    // Farben aus dem Markenschema "Smaragd & Gold" - Gold ist Akzent, nie Aktion.
    $ampel = [
        'ok'   => ['#17A65B', 'rgba(23,166,91,.10)',  '✓', 'In Ordnung'],
        'warn' => ['#B8A16B', 'rgba(184,161,107,.14)', '!', 'Beobachten'],
        'fail' => ['#C0392B', 'rgba(192,57,43,.10)',  '✕', 'Handeln'],
        'info' => ['#5F6B62', 'rgba(95,107,98,.10)',  'i', 'Hinweis'],
    ];
    $gesamt = $ampel[$health['status']] ?? $ampel['info'];
@endphp

<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><span>Systemzustand</span></div>
    <div class="page-title">Systemzustand</div>
    <div class="page-sub">Laeuft im Hintergrund noch alles? Diese Seite fuehrt keine Aktion aus.</div>
</div>

<div class="card sh-gesamt" style="border-left:5px solid {{ $gesamt[0] }};">
    <div class="sh-gesamt-icon" style="background:{{ $gesamt[1] }};color:{{ $gesamt[0] }};">{{ $gesamt[2] }}</div>
    <div style="flex:1;min-width:0;">
        <div class="sh-gesamt-titel" style="color:{{ $gesamt[0] }};">{{ $gesamt[3] }}</div>
        <div class="sh-meta">
            Stand {{ $health['generated_at']->format('d.m.Y H:i:s') }} ·
            Umgebung {{ $health['environment'] }} ·
            Planer-Zeitzone {{ $health['schedule_timezone'] }} (Anwendung {{ $health['app_timezone'] }})
        </div>
    </div>
    <a href="{{ route('admin.system_health') }}" class="sh-btn">Neu laden</a>
</div>

<div class="sh-grid">
    @foreach($health['sections'] as $key => $section)
        @php $sa = $ampel[$section['status']] ?? $ampel['info']; @endphp
        <div class="card sh-karte">
            <div class="sh-kopf">
                <span class="sh-punkt" style="background:{{ $sa[0] }};"></span>
                <span class="sh-kopf-titel">{{ $section['title'] }}</span>
                <span class="sh-kopf-badge" style="background:{{ $sa[1] }};color:{{ $sa[0] }};">{{ $sa[3] }}</span>
            </div>
            <div class="sh-summary">{{ $section['summary'] }}</div>

            @foreach($section['items'] as $item)
                @php $ia = $ampel[$item['status']] ?? $ampel['info']; @endphp
                <div class="sh-zeile">
                    <div class="sh-zeile-kopf">
                        <span class="sh-label">{{ $item['label'] }}</span>
                        <span class="sh-wert" style="color:{{ $ia[0] }};">{{ $item['value'] }}</span>
                    </div>
                    @if(!empty($item['hint']))
                        <div class="sh-hinweis">{{ $item['hint'] }}</div>
                    @endif
                </div>
            @endforeach

            @if($key === 'errors')
            <div style="margin-top:12px;">
                <a href="{{ route('admin.errors') }}" class="sh-btn">Fehler einzeln ansehen →</a>
            </div>
            @endif

            @if($key === 'schedule')
                @if($section['last_any_run'])
                    <div class="sh-zeile">
                        <div class="sh-zeile-kopf">
                            <span class="sh-label">Letzter Planer-Lauf (irgendeine Aufgabe)</span>
                            <span class="sh-wert">{{ $section['last_any_run']->format('d.m.Y H:i') }}</span>
                        </div>
                    </div>
                @else
                    <div class="sh-zeile">
                        <div class="sh-zeile-kopf">
                            <span class="sh-label">Letzter Planer-Lauf</span>
                            <span class="sh-wert" style="color:{{ $ampel['warn'][0] }};">noch keiner protokolliert</span>
                        </div>
                        <div class="sh-hinweis">
                            Entweder wurde die Protokollierung gerade erst eingebaut, oder der Cron-Eintrag
                            fehlt: * * * * * cd /var/www/dienstly24/portal &amp;&amp; php artisan schedule:run
                        </div>
                    </div>
                @endif

                <table class="sh-tabelle">
                    <thead><tr><th>Aufgabe</th><th>Plan</th><th>Letzter Lauf</th><th>Laeufe</th></tr></thead>
                    <tbody>
                    @forelse($section['tasks'] as $task)
                        @php $ta = $ampel[$task['status']] ?? $ampel['info']; @endphp
                        <tr>
                            <td>
                                <span class="sh-punkt sh-punkt-klein" style="background:{{ $ta[0] }};"></span>
                                <span class="sh-tabelle-name">{{ $task['label'] }}</span>
                                @if($task['note'])<div class="sh-hinweis">{{ $task['note'] }}</div>@endif
                            </td>
                            <td><code class="sh-cron">{{ $task['expression'] }}</code></td>
                            <td>
                                @if($task['last_run'])
                                    {{ $task['last_run']->format('d.m. H:i') }}
                                    @if($task['runtime_ms'] !== null)
                                        <span class="sh-dezent">({{ $task['runtime_ms'] }} ms)</span>
                                    @endif
                                @else
                                    <span class="sh-dezent">—</span>
                                @endif
                            </td>
                            <td>
                                {{ $task['run_count'] }}
                                @if($task['fail_count'] > 0)
                                    <span style="color:{{ $ampel['fail'][0] }};">/ {{ $task['fail_count'] }} Fehler</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="sh-dezent">Keine geplanten Aufgaben gefunden.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            @endif
        </div>
    @endforeach
</div>

<div class="card sh-fuss">
    <div class="card-title" style="margin-bottom:8px;">Befehle auf dem Server</div>
    <div class="sh-summary">
        Diese Seite liest nur. Die tiefer gehenden Pruefungen laufen bewusst auf der Kommandozeile,
        weil sie Zeit brauchen oder echte Aufrufe ausloesen.
    </div>
    <ul class="sh-liste">
        <li><code>php artisan queue:health</code> — Warteschlange und Analyse-Rueckstau</li>
        <li><code>php artisan ocr:check</code> — OCR-Programme und Sprachdateien</li>
        <li><code>php artisan ki:pruefen --live</code> — KI-Assistent inkl. echtem Testaufruf</li>
        <li><code>php artisan schedule:list</code> — geplante Aufgaben mit naechster Faelligkeit</li>
        <li><code>tail -n 200 storage/logs/laravel.log</code> — vollstaendiger Stacktrace zu einem Fehler</li>
        <li><code>timedatectl status</code> — Server-Uhr (2FA erlaubt nur ±30 Sekunden Abweichung)</li>
    </ul>
    <div class="sh-summary" style="margin-top:10px;">
        Fuer eine externe Ueberwachung: <code>{{ route('admin.system_health.json') }}</code>
        liefert dieselbe Ampel als JSON (HTTP 503, wenn etwas handlungsbeduerftig ist).
    </div>
</div>

<style>
.sh-gesamt{display:flex;align-items:center;gap:16px;margin-bottom:18px;}
.sh-gesamt-icon{width:44px;height:44px;flex:none;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:700;}
.sh-gesamt-titel{font-size:17px;font-weight:700;margin-bottom:2px;}
.sh-meta{font-size:12px;color:var(--ink-soft);}
.sh-btn{flex:none;padding:8px 14px;border:1px solid var(--line);border-radius:8px;background:var(--surface);color:var(--ink);text-decoration:none;font-size:12.5px;font-weight:600;}
.sh-btn:hover{border-color:var(--gold);}
.sh-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(380px,1fr));gap:18px;align-items:start;}
.sh-karte{padding:18px;}
.sh-kopf{display:flex;align-items:center;gap:9px;margin-bottom:4px;}
.sh-kopf-titel{font-size:15px;font-weight:700;flex:1;min-width:0;}
.sh-kopf-badge{font-size:11px;font-weight:700;padding:3px 9px;border-radius:99px;}
.sh-punkt{width:9px;height:9px;border-radius:50%;flex:none;display:inline-block;}
.sh-punkt-klein{width:7px;height:7px;margin-right:6px;}
.sh-summary{font-size:12.5px;color:var(--ink-soft);margin-bottom:12px;line-height:1.5;}
.sh-zeile{padding:9px 0;border-top:1px solid var(--line);}
.sh-zeile-kopf{display:flex;gap:12px;align-items:baseline;justify-content:space-between;}
.sh-label{font-size:13px;color:var(--ink);min-width:0;}
.sh-wert{font-size:13px;font-weight:700;text-align:right;white-space:nowrap;}
.sh-hinweis{font-size:11.5px;color:var(--ink-soft);margin-top:4px;line-height:1.5;}
.sh-tabelle{width:100%;border-collapse:collapse;margin-top:12px;font-size:12px;}
.sh-tabelle th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--ink-soft);border-bottom:1px solid var(--line);padding:6px 8px 6px 0;font-weight:700;}
.sh-tabelle td{padding:8px 8px 8px 0;border-bottom:1px solid var(--line);vertical-align:top;}
.sh-tabelle-name{word-break:break-word;}
.sh-cron{font-size:11px;white-space:nowrap;color:var(--ink-soft);}
.sh-dezent{color:var(--ink-soft);}
.sh-fuss{margin-top:18px;}
.sh-liste{margin:0;padding-left:18px;font-size:12.5px;line-height:1.9;}
.sh-liste code{font-size:12px;}
@media (max-width:900px){.sh-grid{grid-template-columns:1fr;}}
</style>
@endsection
