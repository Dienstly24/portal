<?php

use App\Support\ErrorRecorder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * KI-136: bereits gespeicherte Datenbank-Fehlermeldungen enthielten die
 * eingesetzten Werte (Vertragsnummern, Kunden-IDs). Neue Eintraege kommen
 * bereinigt an (ErrorRecorder::meldung); hier wird der Bestand nachgezogen.
 * Abgeschnitten wird ab "(Connection:" - dort beginnt Laravels Anhang mit
 * der ausgefuellten SQL-Anweisung. Nicht rueckgaengig zu machen (gewollt).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('error_events')
            ->where(function ($q) {
                $q->where('exception_class', 'like', '%QueryException')
                    ->orWhere('exception_class', 'like', '%ConstraintViolationException');
            })
            ->orderBy('id')
            ->each(function ($zeile) {
                $meldung = (string) $zeile->message;
                $bereinigt = preg_replace('/\s*\(Connection:.*$/su', '', $meldung) ?? $meldung;
                $bereinigt = ErrorRecorder::werteAusblenden($bereinigt);
                if ($bereinigt !== $meldung) {
                    DB::table('error_events')->where('id', $zeile->id)->update(['message' => $bereinigt]);
                }
            });
    }

    public function down(): void
    {
        // Bewusst leer: die entfernten Werte sollen nicht zurueckkommen.
    }
};
