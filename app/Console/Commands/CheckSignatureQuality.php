<?php

namespace App\Console\Commands;

use App\Console\Concerns\ProcessesRecordsSafely;
use App\Mail\SignaturQualitaetMail;
use App\Models\SignatureEvent;
use App\Models\SignatureRequest;
use App\Models\User;
use App\Services\Signature\SignatureQualityGate;
use App\Support\SignatureStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Nachtlauf des Signatur-Qualitaetsgates (Betreiber-Auftrag 04.10.2026,
 * A1 + A5).
 *
 * Prueft JEDEN Vorgang, in dem etwas gestempelt ist oder werden soll:
 * abgeschlossene am fertigen PDF, offene an einer Vorschau mit den bereits
 * gesetzten Feldern (Unternehmenssignatur). Abgebrochene, abgelehnte und
 * abgelaufene Vorgaenge erzeugen nie ein Dokument und werden uebersprungen.
 *
 * Das Ergebnis steht an der Anfrage; ein NEUER Befund laeutet bei den
 * Administratoren. Ist irgendetwas betroffen, geht danach EINE
 * Zusammenfassung per Mail an jeden aktiven Administrator - nur dann.
 *
 * Aendert NIE ein Dokument und nie den Status - behoben wird ueber
 * "Neu erzeugen" (Beraterwelt oder `signaturen:neu-erzeugen`).
 */
class CheckSignatureQuality extends Command
{
    use ProcessesRecordsSafely;

    protected $signature = 'signaturen:qualitaet-pruefen
        {--ohne-mail : Keine Zusammenfassung verschicken (nur pruefen und vermerken)}';

    protected $description = 'Signaturen: Qualitaetsgate ueber den Bestand (am gerenderten Bild), Zusammenfassung an die Administratoren';

    private const UEBERSPRINGEN = [SignatureStatus::CANCELLED, SignatureStatus::DECLINED, SignatureStatus::EXPIRED];

    public function handle(SignatureQualityGate $gate): int
    {
        $start = hrtime(true);
        $geprueft = 0;
        $fehler = 0;

        $kandidaten = SignatureRequest::query()
            ->whereNotIn('status', self::UEBERSPRINGEN)
            ->where(fn ($q) => $q->whereNotNull('signed_path')
                ->orWhere('status', SignatureStatus::COMPLETION_FAILED)
                ->orWhereHas('fields', fn ($f) => $f->whereNotNull('filled_at')->orWhereNotNull('company_asset_id')))
            // KEIN orderBy daneben: lazyById blaettert nach der Kennung, eine
            // zweite Sortierung liess Zeilen still aus dem Lauf fallen
            // (Lehre aus dem Provisions-Import 26.08.2026).
            ->lazyById(50, 'id');

        $this->verarbeiteEinzeln($kandidaten, function (SignatureRequest $request) use ($gate, &$geprueft, &$fehler) {
            $t = hrtime(true);
            $ergebnis = $gate->pruefeBestand($request);
            if ($ergebnis['geprueft'] === 'nichts' && $ergebnis['befunde'] === []) {
                return;
            }
            $gate->vermerke($request, $ergebnis['befunde'], 'Nachtlauf', (int) round((hrtime(true) - $t) / 1_000_000));
            $geprueft++;
            if ($ergebnis['befunde'] !== []) {
                $fehler++;
                $this->line('  Befund: '.$request->id.' - '.mb_substr(implode(' ', $ergebnis['befunde']), 0, 200));
            }
        }, 'Signaturanfrage');

        $dauer = (int) round((hrtime(true) - $start) / 1_000_000);
        $this->info('Geprueft: '.$geprueft.'  mit Befund: '.$fehler.'  Dauer: '.$dauer.' ms');
        Log::info('Signatur-Qualitaet: Nachtlauf', ['geprueft' => $geprueft, 'befund' => $fehler, 'dauer_ms' => $dauer]);

        if (! $this->option('ohne-mail')) {
            $this->zusammenfassung();
        }

        return $this->ergebnisMitUebersprungenen();
    }

    /** EINE Mail je Administrator - nur wenn etwas betroffen ist. */
    private function zusammenfassung(): void
    {
        $betroffen = SignatureQualityGate::betroffene()->orderBy('quality_checked_at', 'desc')->limit(30)->get();
        $gesamt = SignatureQualityGate::betroffene()->count();
        if ($gesamt === 0) {
            return;
        }
        $neu = SignatureEvent::query()
            ->whereIn('event', ['quality_failed', 'signing_failed'])
            ->where('created_at', '>=', now()->subDay())
            ->count();
        $zeilen = [];
        foreach ($betroffen as $r) {
            $zeilen[] = [
                'titel' => mb_substr((string) $r->title, 0, 120),
                'status' => SignatureStatus::label($r->status),
                'befund' => mb_substr(implode(' ', $r->quality_findings ?? ['Fertigstellung gescheitert - Details im Protokoll.']), 0, 240),
            ];
        }

        foreach (User::query()->where('role', 'admin')->where('is_active', true)->whereNotNull('email')->get() as $admin) {
            try {
                Mail::to($admin->email)->send(new SignaturQualitaetMail($admin, $zeilen, $gesamt, $neu));
            } catch (\Throwable $e) {
                Log::warning('Signatur-Qualitaet: Zusammenfassung nicht zustellbar: '.$e->getMessage());
            }
        }
        $this->info('Zusammenfassung verschickt ('.$gesamt.' betroffen).');
    }
}
