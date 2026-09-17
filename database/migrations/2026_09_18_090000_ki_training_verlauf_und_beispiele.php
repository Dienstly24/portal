<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 der Unified Conversation Platform: Verlauf HOCHLADEN, pruefen,
 * schwaerzen, freigeben (Auftrag Abschnitte 18-21).
 *
 * Betreiber-Wunsch: "ich lade vom System aus lebende Gespraeche hoch,
 * die KI lernt daraus, ich pruefe die Kompetenz und gebe erst dann
 * frei". Diese Migration legt die beiden Haelften davon an.
 *
 * ZWEISTUFIG, wie beim Provisions-Import (26.08.2026): ein einstufiger
 * Import zeigt sein Ergebnis erst, NACHDEM er geschrieben hat - wer dann
 * "die Haelfte ist Systemtext" liest, hat keine Wahl mehr. Deshalb
 * landet die Datei zuerst als ENTWURF in `training_import_messages`,
 * und erst eine bewusste Bestaetigung macht daraus Nachrichten im
 * Verlauf.
 */
return new class extends Migration {
    public function up(): void
    {
        /*
         * Ein hochgeladener Verlauf - der ENTWURF.
         */
        Schema::create('training_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            /*
             * Der Kunde, zu dem dieser Verlauf gehoert.
             *
             * NULLBAR, und das ist Absicht: der Export nennt nur einen
             * ANZEIGENAMEN, und ein Name zaehlt in diesem Projekt nie als
             * Zuordnung (Regel des `CustomerResolver`). Wer die Akte
             * bestimmt, ist ein Mensch - vor der Bestaetigung.
             */
            $table->foreignUuid('customer_id')->nullable()->constrained()->nullOnDelete();

            $table->string('file_name');
            // whatsapp_export | ...  - weitere Quellen ohne Migration.
            $table->string('source_type', 30)->default('whatsapp_export');

            /*
             * WELCHER Absender im Export sind WIR?
             *
             * Steht nicht in der Datei und wird NIE geraten: der Parser
             * zaehlt die Absender, die Vorschau legt sie vor, ein Mensch
             * waehlt. Ohne diese Angabe laesst sich nicht bestimmen, was
             * Kundenfrage und was unsere Antwort ist - und ein
             * vertauschtes Paar wuerde die Wissensbasis mit der Frage als
             * Antwort fuellen.
             */
            $table->string('business_sender')->nullable();

            // entwurf | importiert | verworfen
            $table->string('status', 20)->default('entwurf');

            // Zahlen der Vorschau (Absender, Systemzeilen, Medienhinweise,
            // Zeitraum) - genau das, was VOR der Entscheidung zu sehen ist.
            $table->json('stats')->nullable();

            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('customer_id');
        });

        /*
         * Die Zeilen des Entwurfs - eine je erkannter Nachricht.
         *
         * Sie sind NOCH KEINE Nachrichten des Kundenverlaufs. Erst die
         * Bestaetigung erzeugt `customer_messages` mit
         * `source = historical` (also stumm: keine KI, kein Ungelesen,
         * kein Versand).
         */
        Schema::create('training_import_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_import_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            // NULLBAR: ein unlesbares Datum wird NICHT geraten - die
            // Nachricht behaelt ihren Text und die Reihenfolge der Datei.
            $table->timestamp('sent_at')->nullable();
            $table->string('sender_label');
            $table->text('body');
            // "<Medien ausgeschlossen>": der Text ist da, die DATEI fehlt.
            // Das muss sichtbar bleiben, sonst sucht spaeter jemand ein
            // Bild, das es im Export nie gab.
            $table->boolean('has_media_note')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['training_import_id', 'row_number'], 'tim_import_row_idx');
        });

        /*
         * GESCHWAERZTE Frage-Antwort-Paare - der Weg in die Wissensbasis.
         *
         * HIER STEHT NIE EIN ROHTEXT. Gespeichert wird ausschliesslich
         * die geschwaerzte Fassung. Ein Datensatz, der beides enthaelt,
         * waere ein ZWEITER Kundendatenbestand mit eigener Loeschpflicht -
         * und die Schwaerzung waere nur noch eine Anzeige.
         *
         * Die Quelle bleibt als Verweis erhalten (`nullOnDelete`), damit
         * ein freigegebener Eintrag seine Herkunft nennt; verschwindet
         * der Import, bleibt das Beispiel bestehen - es ist dann nur
         * nicht mehr zurueckverfolgbar, und das ist richtig so.
         */
        Schema::create('ai_training_examples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_import_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('conversation_id')->nullable();
            $table->foreign('conversation_id')->references('id')->on('conversations')->nullOnDelete();

            $table->text('question');
            $table->text('answer');
            $table->string('language', 5)->default('de');

            // offen | freigegeben | abgelehnt
            // Ein Beispiel wird NIE automatisch freigegeben (Auftrag
            // Abschnitt 19: nicht jede historische Antwort ist richtig).
            $table->string('status', 20)->default('offen');

            /*
             * PRUEF-DATENSATZ oder TRAININGS-Datensatz?
             *
             * Die Trennung ist der Kern der spaeteren Kompetenzmessung
             * (Abschnitt 21): wer die KI an genau den Antworten prueft,
             * die er ihr vorher gegeben hat, bekommt immer ein gutes und
             * immer wertloses Ergebnis. Die Spalte entsteht JETZT, weil
             * sie sich nachtraeglich nicht sauber auf den Bestand
             * anwenden liesse.
             */
            $table->boolean('is_holdout')->default(false);

            // Was die Schwaerzung gefunden hat - und was sie NICHT
            // zuordnen konnte. Der Mensch sieht es bei der Freigabe.
            $table->json('redaction_report')->nullable();

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            // Der daraus entstandene Wissenseintrag, falls freigegeben.
            $table->uuid('knowledge_entry_id')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['is_holdout', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_training_examples');
        Schema::dropIfExists('training_import_messages');
        Schema::dropIfExists('training_imports');
    }
};
