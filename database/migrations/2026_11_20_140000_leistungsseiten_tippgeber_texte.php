<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Tippgeber-Rolle (Betreiber-Vorgabe 20.11.2026): die Texte der
 * Leistungsseiten in der Datenbank neutral umstellen - automatisch beim
 * Deploy, damit die Live-Website nicht von einem vergessenen Befehl
 * abhaengt. Die Logik steht in `website:tippgeber-texte` (dort auch
 * Sicherung der alten Texte und Rest-Funde); ein erneuter Lauf ist
 * jederzeit gefahrlos.
 *
 * KEIN Rueckbau: die alten Texte liegen als Sicherung unter
 * storage/app/private/backups/ - sie automatisch zurueckzuspielen hiesse,
 * die rechtlich problematischen Aussagen wieder live zu stellen.
 */
return new class extends Migration {
    public function up(): void
    {
        Artisan::call('website:tippgeber-texte', ['--schreiben' => true]);
    }

    public function down(): void
    {
        // bewusst leer, siehe oben
    }
};
