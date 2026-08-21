<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Toten Workflow-Engine-Bestand entfernen.
 *
 * Die Engine (Definitionen, Laeufe, Schritte, Prompts, Chronik) wurde 2026
 * angelegt, aber nie in Betrieb genommen: KEINE Route, KEIN Controller und
 * KEINE Oberflaeche hat je einen Lauf gestartet. Geschrieben wurde
 * ausschliesslich aus der Engine selbst und aus ihren Tests - die Tabellen
 * sind im Produktivbetrieb also leer.
 *
 * Toter Code ist nicht neutral: er sieht wie ein Feature aus. Wer die
 * Kundenakte erweitert, muss sonst jedes Mal pruefen, ob diese Engine
 * mitredet - und die Antwort ist immer "nein".
 *
 * NICHT betroffen und weiterhin in Betrieb (nur aehnlich benannt):
 * EmailWorkflowService (E-Mail-Eingang), CommissionWorkflowService,
 * EmailClassificationService und SystemUserResolver.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Reihenfolge: erst die abhaengigen Tabellen, dann die Elterntabellen.
        Schema::dropIfExists('ai_action_logs');
        Schema::dropIfExists('workflow_step_runs');
        Schema::dropIfExists('workflow_runs');
        Schema::dropIfExists('workflow_prompts');
        Schema::dropIfExists('workflow_definitions');
    }

    public function down(): void
    {
        // Bewusst leer. Die urspruengliche Erstellungs-Migration bleibt im
        // Repository - ein vollstaendiger Neuaufbau der Datenbank legt die
        // Tabellen also an und dieser Schritt entfernt sie wieder. Sie hier
        // ein zweites Mal zu definieren waere eine Kopie, die mit der Zeit
        // auseinanderlaeuft.
    }
};
