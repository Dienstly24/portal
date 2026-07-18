<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kern-Schema der AI-Workflow-Engine (P2, Blueprint
 * docs/KONZEPT_AI_WORKFLOW_ENGINE.md). Fuenf neue Tabellen tragen die
 * generische Branch -> Service (versioniert) -> Steps Mechanik.
 *
 * WICHTIG (Lehre aus dem Produktionsfehler documents.ai_extracted):
 * Spalten mit `encrypted`/`encrypted:array`-Cast MUESSEN `text` sein, NIE
 * `json` - MySQL weist den Chiffretext in einer JSON-Spalte ab
 * (SQLSTATE[22032]). Reine, unverschluesselte Definitions-/Config-Arrays
 * (Schritt-Liste, Feld-Listen, Step-Config) duerfen `json` sein.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) Wissensdatenbank: pro (branch, service_key, version) die
        //    Schritt-Liste + benoetigte Dokumente + Extraktionsfelder +
        //    Intent-Beispiele. `active` markiert die geltende Version.
        Schema::create('workflow_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('branch');                 // Sparte, z.B. krankenversicherung, kfz
            $table->string('service_key');            // Dienstleistung, z.B. bankverbindung_aendern
            $table->unsignedInteger('version')->default(1);
            $table->boolean('active')->default(false);
            $table->string('title');
            $table->text('description')->nullable();
            $table->json('steps')->nullable();               // [{key,type,config}] - Definition, keine PII
            $table->json('required_documents')->nullable();
            $table->json('extraction_fields')->nullable();
            $table->json('intent_examples')->nullable();     // Beispielsaetze fuer Intent-Erkennung
            $table->unsignedTinyInteger('confidence_threshold')->default(90);
            $table->timestamps();

            $table->unique(['service_key', 'version']);
            $table->index(['branch', 'active']);
        });

        // 2) Editierbare Prompt-Vorlagen je Definition + Typ (system, intent,
        //    extraction, reply, validation). Pflege im Admin, kein Deploy.
        Schema::create('workflow_prompts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workflow_definition_id');
            $table->string('type');                   // system|intent|extraction|reply|validation
            $table->text('template');                 // Prompt-Vorlage (kein PII, aber ggf. lang)
            $table->timestamps();

            $table->foreign('workflow_definition_id')->references('id')->on('workflow_definitions')->cascadeOnDelete();
            $table->unique(['workflow_definition_id', 'type']);
        });

        // 3) Laufende Instanz je Ticket/Kunde. `memory` = KI-Gedaechtnis
        //    (encrypted:array -> text!). `version` festgehalten -> reproduzierbar.
        Schema::create('workflow_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workflow_definition_id');
            $table->string('definition_key');         // Snapshot des service_key
            $table->unsignedInteger('version');
            $table->uuid('ticket_id')->nullable();
            $table->uuid('customer_id')->nullable();
            $table->string('status')->default('running'); // running|waiting_customer|needs_review|completed|failed|cancelled
            $table->string('current_step_key')->nullable();
            $table->unsignedTinyInteger('confidence')->nullable();
            $table->text('memory')->nullable();       // encrypted:array (PII) -> text
            $table->unsignedBigInteger('started_by')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('workflow_definition_id')->references('id')->on('workflow_definitions')->cascadeOnDelete();
            $table->foreign('ticket_id')->references('id')->on('tickets')->nullOnDelete();
            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
            $table->index('status');
            $table->index('ticket_id');
            $table->index('customer_id');
        });

        // 4) Ein Schritt einer Instanz. `output` = Ergebnis (encrypted:array
        //    -> text!). `config` = Snapshot der Definition-Config (kein PII).
        Schema::create('workflow_step_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workflow_run_id');
            $table->string('step_key');
            $table->string('type');                   // detect_intent|request_document|ask_customer|extract_data|...
            $table->string('status')->default('pending'); // pending|running|completed|needs_review|waiting_customer|skipped|failed
            $table->unsignedTinyInteger('confidence')->nullable();
            $table->json('config')->nullable();       // Definition-Config des Schritts (kein PII)
            $table->text('output')->nullable();       // encrypted:array (PII) -> text
            $table->text('error')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable(); // Human Override
            $table->timestamp('decided_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('workflow_run_id')->references('id')->on('workflow_runs')->cascadeOnDelete();
            $table->index(['workflow_run_id', 'sort_order']);
            $table->index('status');
        });

        // 5) Chronik JEDER KI-/System-/Mitarbeiter-Entscheidung. `detail`
        //    kann PII-Fragmente enthalten -> encrypted:array (text!).
        Schema::create('ai_action_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workflow_run_id')->nullable();
            $table->uuid('workflow_step_run_id')->nullable();
            $table->uuid('ticket_id')->nullable();
            $table->string('actor');                  // ai|staff|system
            $table->unsignedBigInteger('actor_id')->nullable(); // User-ID bei staff
            $table->string('action');
            $table->text('detail')->nullable();       // encrypted:array (PII moeglich) -> text
            $table->unsignedTinyInteger('confidence')->nullable();
            $table->timestamps();

            $table->foreign('workflow_run_id')->references('id')->on('workflow_runs')->cascadeOnDelete();
            $table->foreign('workflow_step_run_id')->references('id')->on('workflow_step_runs')->cascadeOnDelete();
            $table->index('workflow_run_id');
            $table->index('ticket_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_action_logs');
        Schema::dropIfExists('workflow_step_runs');
        Schema::dropIfExists('workflow_runs');
        Schema::dropIfExists('workflow_prompts');
        Schema::dropIfExists('workflow_definitions');
    }
};
