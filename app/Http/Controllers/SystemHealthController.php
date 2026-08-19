<?php

namespace App\Http\Controllers;

use App\Services\SystemHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

/**
 * Systemzustand (/admin/systemzustand).
 *
 * Eine reine Anzeige: Warteschlange, geplante Aufgaben, externe Dienste,
 * Anmeldung/Sicherheit. Die Seite fuehrt KEINE Aktion aus - sie beantwortet
 * nur die Frage, ob im Hintergrund noch alles laeuft, und nennt zu jedem
 * Fund den naechsten Schritt auf dem Server.
 *
 * Zugriff nur admin/manager: die Seite nennt Betriebsdetails (welche
 * Dienste eingerichtet sind, wie viele Anmeldungen fehlschlagen). Sie gibt
 * aber NIE einen Schluessel oder ein Passwort aus - auch nicht teilweise.
 */
class SystemHealthController extends Controller
{
    public function index(SystemHealthService $health): View
    {
        return view('admin.system_health', ['health' => $health->overview()]);
    }

    /**
     * Dieselben Daten als JSON - fuer eine externe Ueberwachung, die nur
     * wissen will, ob die Ampel rot ist.
     */
    public function json(SystemHealthService $health): JsonResponse
    {
        $data = $health->overview();

        return response()->json([
            'status' => $data['status'],
            'generated_at' => $data['generated_at']->toIso8601String(),
            'sections' => collect($data['sections'])->map(fn (array $s) => [
                'title' => $s['title'],
                'status' => $s['status'],
                'summary' => $s['summary'],
            ])->values(),
        ], $data['status'] === SystemHealthService::FAIL ? 503 : 200);
    }
}
