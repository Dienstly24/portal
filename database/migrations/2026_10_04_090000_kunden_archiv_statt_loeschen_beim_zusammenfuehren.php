<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Zusammenfuehren ARCHIVIERT das Duplikat, statt es zu loeschen
 * (KI-064, Betreiber-Auftrag 03.10.2026, PR-3a).
 *
 * - customers.merged_into_id / archived_at: die Akte bleibt als Huelle
 *   stehen; ihre Kundennummer ist damit weiter belegt und findet ueber
 *   die Suche den Hauptkunden (Alias). Bewusst KEIN SoftDeletes: ein
 *   geloeschter Kunde und ein zusammengefuehrter sind zwei verschiedene
 *   Aussagen, und "withTrashed" wuerde beide vermischen.
 * - customer_merges: EIN Datensatz je Zusammenfuehrung mit allem, was
 *   ein spaeteres Rueckgaengigmachen braucht (umgehaengte Zeilen je
 *   Tabelle, verworfene Kollisionszeilen, ergaenzte Felder, Konto-Tausch).
 *   Er entsteht ab JETZT, damit die Rueckgaengig-Funktion (PR-3b) auch fuer
 *   die Zusammenfuehrungen der Zwischenzeit funktioniert.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // cascadeOnDelete: wird der Hauptkunde geloescht (DSGVO), gehen
            // seine Huellen mit - sonst blieben ihre Stammdaten unsichtbar
            // und unloeschbar liegen.
            $table->foreignUuid('merged_into_id')->nullable()->after('user_id')
                ->constrained('customers')->cascadeOnDelete();
            $table->timestamp('archived_at')->nullable()->after('merged_into_id');
            $table->index('archived_at');
        });

        Schema::create('customer_merges', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('primary_customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignUuid('duplicate_customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('duplicate_number')->nullable();
            // Umgehaengte Zeilen je Tabelle (ids), verworfene Kollisionszeilen
            // (vollstaendige Zeile), vom Duplikat ergaenzte Felder, Konto-Tausch.
            $table->longText('protokoll');
            $table->text('begruendung')->nullable();
            $table->timestamp('undone_at')->nullable();
            $table->timestamps();
            $table->index(['primary_customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        // Ohne die Archiv-Spalten stuenden die Huellen wieder als normale
        // Kunden in jeder Liste - zusaetzlich zum Hauptkunden, also als die
        // Dubletten, die gerade beseitigt wurden. Lieber abbrechen.
        $huellen = DB::table('customers')->whereNotNull('archived_at')->count();
        if ($huellen > 0) {
            throw new RuntimeException("Rueckbau abgebrochen: {$huellen} archivierte Kundenakte(n) aus Zusammenfuehrungen vorhanden.");
        }

        Schema::dropIfExists('customer_merges');
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['archived_at']);
            $table->dropConstrainedForeignId('merged_into_id');
            $table->dropColumn('archived_at');
        });
    }
};
