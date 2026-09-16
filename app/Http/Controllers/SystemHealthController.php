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

    /**
     * Ampel fuer eine EXTERNE Ueberwachung (Audit 15.09.2026).
     *
     * `/gesundheit`, geschuetzt nur durch das Token aus der Server-.env
     * (App\Http\Middleware\HealthToken) - bewusst ausserhalb von
     * Anmeldung, Rolle, Passwortzwang und Zweitem Faktor. Die bisherige
     * JSON-Ansicht lag hinter alldem und antwortete einem
     * Ueberwachungsdienst mit einer 302 auf die Anmeldeseite; sie war
     * damit fuer ihren eigentlichen Zweck unbrauchbar.
     *
     * BEWUSST NOCH KNAPPER als /admin/systemzustand.json: nur die
     * Gesamt-Ampel und je Abschnitt Schluessel + Zustand. KEINE
     * Zusammenfassungen, keine Zahlen, keine Namen von Diensten, keine
     * Umgebungsangaben. Wer das Token hat, soll erkennen KOENNEN, DASS
     * etwas klemmt - nicht, WAS im Betrieb wie eingerichtet ist.
     *
     * HTTP 503 heisst "handlungsbeduerftig". Jeder Ueberwachungsdienst
     * kann darauf alarmieren, ohne den Inhalt zu verstehen.
     */
    public function pulse(SystemHealthService $health): JsonResponse
    {
        try {
            $data = $health->overview();
        } catch (\Throwable $e) {
            // Eine Pruefung, die selbst abstuerzt, ist die schlechteste
            // aller Meldungen: sie sieht von aussen aus wie ein Ausfall
            // der ganzen Anwendung. Deshalb ehrlich, aber ohne Details -
            // die Ursache steht in der Logdatei und in /admin/fehler.
            report($e);

            return response()->json([
                'status' => SystemHealthService::FAIL,
                'checked_at' => now()->toIso8601String(),
                'checks' => [],
            ], 503);
        }

        return response()->json([
            'status' => $data['status'],
            'checked_at' => $data['generated_at']->toIso8601String(),
            'checks' => collect($data['sections'])
                ->map(fn (array $s) => $s['status'])
                ->all(),
        ], $data['status'] === SystemHealthService::FAIL ? 503 : 200);
    }
}
