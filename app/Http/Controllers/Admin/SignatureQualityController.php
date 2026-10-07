<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SignatureRequest;
use App\Services\Signature\SignatureQualityGate;
use App\Services\Signature\SignedPdfRegenerator;
use App\Support\SignatureStatus;
use Illuminate\Routing\Controllers\HasMiddleware;

/**
 * Signatur-Qualitaet (Betreiber-Auftrag 04.10.2026, A1): welche Vorgaenge
 * haben die Qualitaetspruefung NICHT bestanden - und der Knopf, der das
 * behebt.
 *
 * NUR ADMIN, geprueft an der Route UND hier. Die Seite zeigt Vorgaenge
 * aller Mitarbeiter; die Portfolio-Grenze der Signaturliste gilt hier
 * bewusst nicht, weil die Behebung eine Betriebsaufgabe ist.
 */
class SignatureQualityController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return ['role:admin'];
    }

    public function index()
    {
        $betroffene = SignatureQualityGate::betroffene()
            ->with('creator')
            ->orderByDesc('quality_checked_at')
            ->limit(200)
            ->get();
        $gesamt = SignatureQualityGate::betroffene()->count();
        $letzterLauf = SignatureRequest::query()->max('quality_checked_at');
        $geprueft = SignatureRequest::query()->whereNotNull('quality_checked_at')->count();

        return view('admin.signatures.quality', compact('betroffene', 'gesamt', 'letzterLauf', 'geprueft'));
    }

    /** Prueft EINEN Vorgang sofort - z. B. nach einem Neu-Erzeugen. */
    public function check(string $id, SignatureQualityGate $gate)
    {
        $request = SignatureRequest::findOrFail($id);
        $start = hrtime(true);
        $ergebnis = $gate->pruefeBestand($request);
        $gate->vermerke($request, $ergebnis['befunde'], 'Pruefung von Hand', (int) round((hrtime(true) - $start) / 1_000_000));

        return back()->with($ergebnis['befunde'] === [] ? 'success' : 'error', $ergebnis['befunde'] === []
            ? 'Prüfung bestanden: "'.$request->title.'".'
            : 'Prüfung nicht bestanden: '.implode(' ', $ergebnis['befunde']));
    }

    /**
     * Neu erzeugen - fuer gescheiterte Abschluesse UND fuer abgeschlossene
     * Vorgaenge, deren Dokument die Nachtpruefung nicht bestanden hat. Das
     * alte PDF bleibt erhalten (SignedPdfRegenerator).
     */
    public function regenerate(string $id, SignedPdfRegenerator $regenerator)
    {
        $request = SignatureRequest::findOrFail($id);
        if ($request->status !== SignatureStatus::COMPLETION_FAILED && ! $request->isCompleted()) {
            return back()->with('error', 'Der Vorgang ist noch nicht abgeschlossen - das Dokument entsteht beim Abschluss. Bitte die Felder im Vorgang prüfen.');
        }
        $r = $regenerator->neuErzeugen($request);

        return back()->with(in_array($r['ergebnis'], ['neu_erzeugt', 'abgeschlossen'], true) ? 'success' : 'error',
            in_array($r['ergebnis'], ['neu_erzeugt', 'abgeschlossen'], true)
                ? 'Neu erzeugt und geprüft: "'.$request->title.'". Das alte PDF bleibt erhalten.'
                : 'Neu erzeugen nicht bestanden: '.implode(' ', $r['befunde']));
    }
}
