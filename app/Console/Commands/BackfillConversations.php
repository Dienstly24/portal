<?php

namespace App\Console\Commands;

use App\Console\Concerns\ProcessesRecordsSafely;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\CustomerMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Traegt den BESTAND in die neue Unterhaltungs-Struktur nach.
 *
 * Bis zur Omnichannel-Umstellung war "die Unterhaltung mit einem
 * Kunden" eine Abfrage, kein Datensatz. Dieser Befehl erzeugt fuer
 * jeden Kunden mit Nachrichten genau eine Unterhaltung im Kanal
 * `portal` und haengt die vorhandenen Nachrichten daran.
 *
 * DREI EIGENSCHAFTEN, ohne die er nicht laufen duerfte:
 * 1. IDEMPOTENT - ein zweiter Lauf legt nichts doppelt an und aendert
 *    nichts, was schon zugeordnet ist. Ein Nachtrag, den man nicht
 *    wiederholen darf, ist bei einem Abbruch wertlos.
 * 2. ER LOESCHT NICHTS und ueberschreibt keine bestehende Angabe.
 * 3. Ein kaputter Datensatz beendet nie den ganzen Lauf (Lehre
 *    19.08.2026) - er wird gemeldet, und der Lauf geht weiter.
 */
class BackfillConversations extends Command
{
    use ProcessesRecordsSafely;

    protected $signature = 'messaging:unterhaltungen-nachtragen
                            {--probelauf : Nur zeigen, was geschehen wuerde}';

    protected $description = 'Legt fuer bestehende Kundennachrichten die Unterhaltungen an (Omnichannel-Nachtrag)';

    public function handle(): int
    {
        $probelauf = (bool) $this->option('probelauf');
        $kanal = Channel::where('key', Channel::PORTAL)->first();

        if (! $kanal) {
            $this->error('Kanal "portal" fehlt. Bitte zuerst die Migrationen ausfuehren.');

            return 1;
        }

        // Nur Kunden, deren Nachrichten noch KEINE Unterhaltung haben.
        // Damit ist ein zweiter Lauf von selbst fast leer.
        $kundenIds = CustomerMessage::whereNull('conversation_id')
            ->whereNotNull('customer_id')
            ->distinct()->pluck('customer_id');

        if ($kundenIds->isEmpty()) {
            $this->info('Nichts nachzutragen - alle Nachrichten haengen bereits an einer Unterhaltung.');

            return 0;
        }

        $this->info($kundenIds->count().' Kunde(n) mit nachzutragenden Nachrichten.');
        if ($probelauf) {
            $this->warn('Probelauf - es wird nichts geschrieben.');
        }

        $angelegt = 0;
        $zugeordnet = 0;

        $this->verarbeiteEinzeln(
            $kundenIds,
            function ($kundeId) use ($kanal, $probelauf, &$angelegt, &$zugeordnet) {
                if ($probelauf) {
                    $zugeordnet += CustomerMessage::where('customer_id', $kundeId)
                        ->whereNull('conversation_id')->count();
                    $angelegt++;

                    return;
                }

                DB::transaction(function () use ($kundeId, $kanal, &$angelegt, &$zugeordnet) {
                    $unterhaltung = Conversation::where('customer_id', $kundeId)
                        ->where('channel_id', $kanal->id)
                        ->first();

                    if (! $unterhaltung) {
                        $unterhaltung = Conversation::create([
                            'customer_id' => $kundeId,
                            'channel_id' => $kanal->id,
                            'status' => Conversation::STATUS_OPEN,
                        ]);
                        $angelegt++;
                    }

                    // Massenweise und ohne Modell-Ereignisse: der Nachtrag
                    // darf weder die KI-Ruhefrist verschieben noch eine
                    // Glocke ausloesen - er bildet Vergangenheit ab.
                    $zugeordnet += CustomerMessage::where('customer_id', $kundeId)
                        ->whereNull('conversation_id')
                        ->update(['conversation_id' => $unterhaltung->id]);

                    $letzte = CustomerMessage::where('conversation_id', $unterhaltung->id)
                        ->max('created_at');
                    $unterhaltung->forceFill(['last_message_at' => $letzte])->save();
                });
            },
            'Kunde'
        );

        // `direction` und `sender_type` fuer den Altbestand ableiten. Das
        // sind reine Lesarten von `from_staff` - es entsteht keine neue
        // Aussage, nur dieselbe in der neuen Schreibweise.
        if (! $probelauf) {
            CustomerMessage::whereNull('direction')->where('from_staff', true)
                ->update(['direction' => CustomerMessage::DIRECTION_OUTGOING]);
            CustomerMessage::whereNull('direction')->where('from_staff', false)
                ->update(['direction' => CustomerMessage::DIRECTION_INCOMING]);
            CustomerMessage::whereNull('message_type')
                ->update(['message_type' => CustomerMessage::TYPE_TEXT]);
            CustomerMessage::whereNull('sender_type')->where('from_staff', false)
                ->update(['sender_type' => CustomerMessage::SENDER_CUSTOMER]);
            CustomerMessage::whereNull('sender_type')->where('ai_generated', true)
                ->update(['sender_type' => CustomerMessage::SENDER_BOT]);
            CustomerMessage::whereNull('sender_type')
                ->update(['sender_type' => CustomerMessage::SENDER_EMPLOYEE]);
        }

        $this->info("Unterhaltungen angelegt: {$angelegt}");
        $this->info("Nachrichten zugeordnet:  {$zugeordnet}");

        return $this->ergebnisMitUebersprungenen();
    }
}
