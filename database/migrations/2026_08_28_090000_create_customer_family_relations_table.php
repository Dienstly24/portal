<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Echte Familienstruktur zwischen BESTEHENDEN Kundenakten (Betreiber-Vorgabe
 * 28.08.2026).
 *
 * Warum eine eigene Tabelle neben `customer_relationships`:
 *  - `customer_relationships` beantwortet genau EINE Frage - "dieses Paar ist
 *    KEINE Dublette". Das Paar wird dort sortiert gespeichert (a < b), es hat
 *    also bewusst KEINE Richtung. "Zania ist Tochter von Jehad" laesst sich
 *    darin nicht ausdruecken, ohne die Bedeutung der Tabelle zu verbiegen.
 *  - Eine Familienrolle ist dagegen IMMER gerichtet und wird als PAAR
 *    gespeichert (Hin- und Rueckrichtung), damit man von den Eltern zum Kind
 *    UND vom Kind zu den Eltern navigieren kann.
 *
 * Lesart einer Zeile: "`related_customer_id` ist `relationship_type` von
 * `customer_id`" - z. B. (Jehad, Zania, 'tochter') = "Zania ist Tochter von
 * Jehad". Die Gegenzeile (Zania, Jehad, 'vater') legt der Service automatisch
 * an; beide Zeilen existieren immer gemeinsam.
 *
 * `is_dependent` steht NUR an der Zeile der Bezugsperson (Elternteil ->
 * Kind) und bedeutet: dieses Kind ist ein abhaengiges Familienmitglied.
 * Es ist bewusst KEIN Kundenstatus am Kunden selbst - Kundenstatus und
 * Familienrolle sind getrennte Wahrheiten (eine 16-jaehrige Tochter ist
 * eigenstaendige Kundin UND bleibt Tochter).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_family_relations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignUuid('related_customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('relationship_type');
            $table->boolean('is_dependent')->default(false);
            // Gueltigkeit der BEZIEHUNG (z. B. Ehe geschieden zum ...). Eine
            // beendete Beziehung wird nie geloescht, sondern datiert - die
            // Historie bleibt nachvollziehbar.
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            // Zeitpunkt des automatischen Uebergangs "abhaengig -> eigenstaendig"
            // (15. Geburtstag). Die Beziehung selbst bleibt bestehen.
            $table->timestamp('independent_since')->nullable();
            // "Uebergang vorbereiten" wurde von einem Mitarbeiter angestossen.
            $table->timestamp('transition_prepared_at')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['customer_id', 'related_customer_id']);
            $table->index(['related_customer_id', 'is_dependent']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_family_relations');
    }
};
