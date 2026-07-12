<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ticketsystem-Ausbau (professioneller Workflow):
 *  - Status als String statt MySQL-Enum, damit der neue Status "resolved"
 *    (Geloest) ohne weitere Schemaänderungen möglich ist.
 *  - Ticketnummer (T-JJ + 5-stellig) fuer Kommunikation & Suche.
 *  - Workflow-Zeitstempel: erste Antwort, geloest, geschlossen, Faelligkeit
 *    (SLA je Prioritaet) und Wiedereroeffnungs-Zaehler.
 *  - Kundenbewertung (1-5) nach Abschluss.
 *  - ticket_events: lueckenloser Verlauf (Status, Zuweisung, Antworten, ...).
 */
return new class extends Migration {
    public function up(): void {
        // Enum -> String (Werte bleiben erhalten; MySQL: MODIFY, SQLite: Rebuild)
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('status', 20)->default('open')->change();
        });

        Schema::table('tickets', function (Blueprint $table) {
            if (!Schema::hasColumn('tickets', 'ticket_number')) $table->string('ticket_number', 20)->nullable()->unique()->after('id');
            if (!Schema::hasColumn('tickets', 'first_response_at')) $table->timestamp('first_response_at')->nullable();
            if (!Schema::hasColumn('tickets', 'resolved_at')) $table->timestamp('resolved_at')->nullable();
            if (!Schema::hasColumn('tickets', 'closed_at')) $table->timestamp('closed_at')->nullable();
            if (!Schema::hasColumn('tickets', 'closed_by')) $table->unsignedBigInteger('closed_by')->nullable();
            if (!Schema::hasColumn('tickets', 'due_at')) $table->timestamp('due_at')->nullable();
            if (!Schema::hasColumn('tickets', 'reopened_count')) $table->unsignedSmallInteger('reopened_count')->default(0);
            if (!Schema::hasColumn('tickets', 'rating')) $table->unsignedTinyInteger('rating')->nullable();
            if (!Schema::hasColumn('tickets', 'rating_comment')) $table->text('rating_comment')->nullable();
        });

        if (!Schema::hasTable('ticket_events')) {
            Schema::create('ticket_events', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('ticket_id')->index();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('event', 40);
                $table->text('details')->nullable();
                $table->timestamps();
            });
        }

        // Bestandstickets: Nummern chronologisch nachziehen (T-JJ00001, ...)
        // und Faelligkeit fuer noch offene Tickets setzen. Bewusst ueber den
        // Query-Builder (keine Model-Events beim Backfill).
        $slaHours = ['dringend' => 4, 'hoch' => 24, 'mittel' => 72, 'niedrig' => 120];
        $counters = [];
        foreach (DB::table('tickets')->whereNull('ticket_number')->orderBy('created_at')->get(['id', 'created_at', 'status', 'priority']) as $t) {
            $yy = date('y', strtotime($t->created_at ?? 'now'));
            $counters[$yy] = ($counters[$yy] ?? 0) + 1;
            $update = ['ticket_number' => 'T-' . $yy . str_pad((string) $counters[$yy], 5, '0', STR_PAD_LEFT)];
            if (in_array($t->status, ['open', 'in_progress'], true)) {
                $hours = $slaHours[$t->priority] ?? 72;
                $update['due_at'] = date('Y-m-d H:i:s', strtotime(($t->created_at ?? 'now') . " +{$hours} hours"));
            }
            DB::table('tickets')->where('id', $t->id)->update($update);
        }
    }

    public function down(): void {
        Schema::dropIfExists('ticket_events');
        Schema::table('tickets', function (Blueprint $table) {
            foreach (['ticket_number', 'first_response_at', 'resolved_at', 'closed_at', 'closed_by', 'due_at', 'reopened_count', 'rating', 'rating_comment'] as $col) {
                if (Schema::hasColumn('tickets', $col)) $table->dropColumn($col);
            }
        });
    }
};
