<?php

namespace App\Console\Commands;

use App\Models\PendingRegistration;
use Illuminate\Console\Command;

/**
 * Raeumt abgelaufene Registrierungs-Vormerkungen weg (Audit SEC-1).
 *
 * Zwei Gruende, warum das kein Schoenheitsputz ist:
 *  1. DSGVO-Datenminimierung: eine nie bestaetigte Vormerkung enthaelt
 *     Name, Geburtsdatum und E-Mail-Adresse einer Person, die nie Kunde
 *     geworden ist. Sie darf nicht dauerhaft liegen bleiben.
 *  2. Die Adresse ist bis zum Aufraeumen blockiert (unique). Ohne diesen
 *     Lauf koennte ein Angreifer fremde Adressen "reservieren" und damit
 *     die spaetere echte Registrierung verhindern.
 */
class PurgePendingRegistrations extends Command
{
    protected $signature = 'registrierungen:aufraeumen {--tage=0 : Zusaetzliche Schonfrist nach Ablauf}';

    protected $description = 'Loescht abgelaufene, nie bestaetigte Registrierungs-Vormerkungen.';

    public function handle(): int
    {
        $grace = max(0, (int) $this->option('tage'));
        $cutoff = now()->subDays($grace);

        $count = PendingRegistration::where('expires_at', '<', $cutoff)->count();

        if ($count === 0) {
            $this->info('Keine abgelaufenen Vormerkungen.');

            return self::SUCCESS;
        }

        PendingRegistration::where('expires_at', '<', $cutoff)->delete();

        $this->info($count.' abgelaufene Vormerkung(en) geloescht.');

        return self::SUCCESS;
    }
}
