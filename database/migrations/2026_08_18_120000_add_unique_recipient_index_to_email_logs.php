<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Doppelversand-Schutz fuer Kampagnen (Audit-Befund 18.08.2026).
 *
 * `SendCampaignJob` hatte ein Timeout von 600s, die Datenbank-Queue ein
 * `retry_after` von 360s: nach 360s durfte ein ZWEITER Worker denselben
 * Job holen, waehrend der erste noch sendete - Kunden bekamen dieselbe
 * Werbe-Mail mehrfach. Der Job schliesst bereits protokollierte
 * Empfaenger jetzt aus; dieser Index macht die Regel in der DATENBANK
 * verbindlich, auch wenn zwei Laeufe exakt gleichzeitig denselben
 * Empfaenger greifen.
 *
 * Nur Kampagnen-Mails sind betroffen: bei allen anderen Protokoll-
 * eintraegen ist `campaign_id` NULL, und NULL-Werte schliesst ein
 * Unique-Index (MySQL wie SQLite) nicht gegeneinander aus.
 */
return new class extends Migration {
    public function up(): void {
        if (!Schema::hasTable('email_logs')) return;

        // Altbestand aus der Zeit des Doppelversands bereinigen: je
        // Kampagne und Empfaenger bleibt der AELTESTE Eintrag stehen
        // (der zur tatsaechlich zuerst versendeten Mail gehoert).
        $duplicates = DB::table('email_logs')
            ->select('campaign_id', 'user_id', DB::raw('MIN(created_at) as first_at'))
            ->whereNotNull('campaign_id')
            ->groupBy('campaign_id', 'user_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $row) {
            $keep = DB::table('email_logs')
                ->where('campaign_id', $row->campaign_id)
                ->where('user_id', $row->user_id)
                ->orderBy('created_at')->orderBy('id')
                ->value('id');
            DB::table('email_logs')
                ->where('campaign_id', $row->campaign_id)
                ->where('user_id', $row->user_id)
                ->where('id', '!=', $keep)
                ->delete();
        }

        Schema::table('email_logs', function (Blueprint $table) {
            $table->unique(['campaign_id', 'user_id'], 'email_logs_campaign_recipient_unique');
        });
    }

    public function down(): void {
        if (!Schema::hasTable('email_logs')) return;
        Schema::table('email_logs', function (Blueprint $table) {
            $table->dropUnique('email_logs_campaign_recipient_unique');
        });
    }
};
