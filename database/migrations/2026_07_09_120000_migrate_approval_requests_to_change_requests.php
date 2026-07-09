<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vereinheitlichung des Genehmigungssystems (Audit/Spec Teil 6):
 * Alle Datensätze des alten feldbasierten ApprovalRequest-Systems
 * werden VERLUSTFREI in customer_change_requests überführt, danach
 * wird die alte Tabelle entfernt.
 *
 * Mapping: field_name 'iban' -> type 'bank', alle übrigen Felder
 * -> type 'profile' mit {feld: wert} in old_data/new_data.
 * reviewer_note -> notes, Zeitstempel und Reviewer bleiben erhalten.
 * requested_by wird über den zugehörigen Kunden-User ermittelt.
 *
 * Hinweis: Die ursprüngliche CREATE-Migration der Tabelle bleibt im
 * Repo, da ihr Entfernen auf bereits deployten Systemen migrate:status
 * brechen würde.
 */
return new class extends Migration {
    public function up(): void {
        if (!Schema::hasTable('approval_requests')) {
            return;
        }

        foreach (DB::table('approval_requests')->orderBy('created_at')->cursor() as $old) {
            $type = $old->field_name === 'iban' ? 'bank' : 'profile';
            $userId = DB::table('customers')->where('id', $old->customer_id)->value('user_id');

            DB::table('customer_change_requests')->insert([
                'id' => $old->id, // uuid bleibt stabil (Referenzen/Logs)
                'customer_id' => $old->customer_id,
                'requested_by' => $userId,
                'type' => $type,
                'old_data' => json_encode(
                    $type === 'bank' ? ['iban' => $old->old_value] : [$old->field_name => $old->old_value],
                    JSON_UNESCAPED_UNICODE
                ),
                'new_data' => json_encode(
                    $type === 'bank' ? ['iban' => $old->new_value] : [$old->field_name => $old->new_value],
                    JSON_UNESCAPED_UNICODE
                ),
                'status' => in_array($old->status, ['pending', 'approved', 'rejected'], true) ? $old->status : 'pending',
                'requested_at' => $old->created_at ?? now(),
                'reviewed_by' => $old->reviewed_by ?? null,
                'reviewed_at' => $old->reviewed_at ?? null,
                'notes' => $old->reviewer_note ?? null,
                'created_at' => $old->created_at ?? now(),
                'updated_at' => $old->updated_at ?? now(),
            ]);
        }

        Schema::dropIfExists('approval_requests');
    }

    public function down(): void {
        // Die Daten leben in customer_change_requests weiter; ein
        // automatischer Rückbau ist nicht vorgesehen.
    }
};
