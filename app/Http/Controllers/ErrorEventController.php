<?php

namespace App\Http\Controllers;

use App\Models\ErrorEvent;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Fehlerliste (/admin/fehler) - nur admin/manager.
 *
 * Zeigt, was im Betrieb wirklich kaputtgeht, zusammengefasst je
 * Fingerabdruck. Die Seite ersetzt keinen Stacktrace: der steht weiterhin
 * in storage/logs/laravel.log. Sie beantwortet die Frage davor - "ist
 * ueberhaupt etwas kaputt, und wie oft?".
 */
class ErrorEventController extends Controller
{
    public function index(Request $request): View
    {
        $zeigeErledigte = $request->query('erledigt') === '1';

        $fehler = ErrorEvent::query()
            ->when(! $zeigeErledigte, fn ($q) => $q->open())
            ->when($zeigeErledigte, fn ($q) => $q->whereNotNull('resolved_at'))
            ->with(['lastUser:id,name', 'resolver:id,name'])
            ->orderByDesc('last_seen_at')
            ->paginate(30)
            ->withQueryString();

        $zaehler = [
            'offen' => ErrorEvent::open()->count(),
            'erledigt' => ErrorEvent::whereNotNull('resolved_at')->count(),
        ];

        return view('admin.errors', compact('fehler', 'zaehler', 'zeigeErledigte'));
    }

    /**
     * Als erledigt markieren. Tritt derselbe Fehler erneut auf, oeffnet ihn
     * der Recorder wieder - "behoben" ist er erst, wenn er ausbleibt.
     */
    public function resolve(Request $request, int $id)
    {
        $fehler = ErrorEvent::findOrFail($id);
        $fehler->forceFill([
            'resolved_at' => now(),
            'resolved_by' => $request->user()->id,
        ])->save();

        return back()->with('success', 'Fehler als erledigt markiert. Tritt er erneut auf, erscheint er wieder.');
    }

    /** Erledigt-Markierung zuruecknehmen. */
    public function reopen(int $id)
    {
        ErrorEvent::findOrFail($id)
            ->forceFill(['resolved_at' => null, 'resolved_by' => null])->save();

        return back()->with('success', 'Fehler wieder geöffnet.');
    }
}
