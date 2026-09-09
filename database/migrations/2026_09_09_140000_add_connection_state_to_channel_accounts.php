<?php

use App\Support\ChannelConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anbindungsart und Verbindungszustand am Kanal-Konto (Auftrag 22/23/35).
 *
 * Bisher liess sich einem Konto nur ansehen, ob Zugangsdaten hinterlegt
 * sind. Das beantwortet die eigentliche Frage nicht: laeuft die Nummer
 * ueber die Cloud API allein, oder ZUSAETZLICH weiter in der WhatsApp
 * Business App? Beides ist eine eigene Freigabe von Meta, und aus einem
 * vorhandenen Token folgt keine von beiden.
 *
 * Bewusst zwei Spalten statt einer: "verbunden" und "wie verbunden" sind
 * verschiedene Aussagen. Eine einzige Spalte haette frueher oder spaeter
 * einen Wert "connected_coexistence" bekommen - und damit die Frage
 * "steht die Verbindung?" nur noch ueber eine Liste von Sonderwerten
 * beantwortbar gemacht.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('channel_accounts', function (Blueprint $table) {
            $table->string('connection_type')->default(ChannelConnection::TYPE_CLOUD_API)
                ->after('external_account_id');
            $table->string('connection_status')->default(ChannelConnection::NOT_CONNECTED)
                ->after('connection_type');
            // Was zuletzt schiefging - im Klartext fuer den Admin, nie eine
            // fremde Fehlermeldung und nie ein Geheimnis.
            $table->string('connection_error', 500)->nullable()->after('connection_status');
            $table->timestamp('connection_checked_at')->nullable()->after('connection_error');
            // Das WhatsApp Business Account (WABA) - die Klammer ueber der
            // Nummer. Sie steht NICHT in `credentials`: sie ist eine
            // Kennung, kein Geheimnis, und wird zum Anzeigen gebraucht.
            $table->string('waba_id')->nullable()->after('connection_checked_at');

            $table->index(['connection_status'], 'channel_accounts_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('channel_accounts', function (Blueprint $table) {
            $table->dropIndex('channel_accounts_status_idx');
            $table->dropColumn([
                'connection_type', 'connection_status', 'connection_error',
                'connection_checked_at', 'waba_id',
            ]);
        });
    }
};
