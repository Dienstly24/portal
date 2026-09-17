<?php

namespace App\Console\Commands;

use App\Console\Concerns\ProcessesRecordsSafely;
use App\Models\Conversation;
use App\Models\ConversationChannel;
use App\Models\CustomerMessage;
use Illuminate\Console\Command;

/**
 * Traegt den Kanal an den BESTAND nach (Phase 2).
 *
 * Seit Phase 2 traegt jede Nachricht ihren Kanal und jede Unterhaltung
 * ihre Kanal-Zugehoerigkeit. Der Altbestand hat beides nicht - er
 * entstand, als eine Unterhaltung noch genau einen Kanal hatte. Genau
 * deshalb ist der Nachtrag auch RISIKOLOS: der Kanal der Unterhaltung
 * IST der Kanal jeder ihrer Nachrichten, solange sie nur einen hat. Es
 * wird nichts geraten, nur aufgeschrieben, was ohnehin gilt.
 *
 * DREI EIGENSCHAFTEN, ohne die er nicht laufen duerfte (wie beim
 * Unterhaltungs-Nachtrag):
 * 1. IDEMPOTENT - ein zweiter Lauf findet fast nichts mehr.
 * 2. Er LOESCHT nichts und ueberschreibt keine gesetzte Angabe.
 * 3. Ein kaputter Datensatz beendet nie den ganzen Lauf.
 */
class BackfillMessageChannels extends Command
{
    use ProcessesRecordsSafely;

    protected $signature = 'messaging:kanal-nachtragen
                            {--probelauf : Nur zeigen, was geschehen wuerde}';

    protected $description = 'Traegt Kanal je Nachricht und Kanal-Zugehoerigkeit je Unterhaltung nach (Phase 2)';

    public function handle(): int
    {
        $probelauf = (bool) $this->option('probelauf');

        $this->info($probelauf ? 'PROBELAUF - es wird nichts geschrieben.' : 'Nachtrag laeuft.');

        $ohneEintrag = Conversation::doesntHave('channels')->count();
        $ohneKanal = CustomerMessage::whereNull('channel_id')->whereNotNull('conversation_id')->count();

        $this->line("Unterhaltungen ohne Kanal-Eintrag: {$ohneEintrag}");
        $this->line("Nachrichten ohne Kanal: {$ohneKanal}");

        if ($probelauf) {
            return 0;
        }

        $this->zugehoerigkeitNachtragen();
        $this->nachrichtenNachtragen();

        // Exitcode 1, wenn etwas uebersprungen wurde - ein sichtbarer
        // Teilausfall ist besser als ein stiller.
        return $this->ergebnisMitUebersprungenen();
    }

    /** Je Unterhaltung den Eintrag fuer ihren (einzigen) Kanal anlegen. */
    private function zugehoerigkeitNachtragen(): void
    {
        $this->verarbeiteEinzeln(
            Conversation::doesntHave('channels')->cursor(),
            function (Conversation $u) {
                ConversationChannel::firstOrCreate(
                    ['link_key' => ConversationChannel::linkKey($u->id, $u->channel_id, $u->channel_account_id)],
                    [
                        'conversation_id' => $u->id,
                        'channel_id' => $u->channel_id,
                        'channel_account_id' => $u->channel_account_id,
                        'external_user_id' => $u->external_user_id,
                        'external_conversation_id' => $u->external_conversation_id,
                        'join_method' => ConversationChannel::JOIN_INITIAL,
                        'joined_at' => $u->created_at ?: now(),
                        'first_message_at' => $u->created_at,
                        'last_message_at' => $u->last_message_at,
                    ]
                );

                // Der zuletzt benutzte Kanal ist bei einer Unterhaltung
                // mit genau einem Kanal zwangslaeufig dieser.
                if (! $u->last_channel_id) {
                    $u->forceFill(['last_channel_id' => $u->channel_id])->saveQuietly();
                }
            },
            'Unterhaltung'
        );
    }

    /**
     * Den Kanal an die Nachrichten schreiben - in Bloecken je
     * Unterhaltung statt Zeile fuer Zeile.
     *
     * Ein Bestand mit hunderttausend Nachrichten einzeln zu aktualisieren
     * dauert Stunden; je Unterhaltung ist es EINE Anweisung. Die
     * Aussage bleibt dieselbe, weil alle Nachrichten einer
     * Alt-Unterhaltung denselben Kanal haben.
     */
    private function nachrichtenNachtragen(): void
    {
        $this->verarbeiteEinzeln(
            Conversation::whereHas('messages', fn ($q) => $q->whereNull('channel_id'))->cursor(),
            function (Conversation $u) {
                CustomerMessage::where('conversation_id', $u->id)
                    ->whereNull('channel_id')
                    ->update([
                        'channel_id' => $u->channel_id,
                        'channel_account_id' => $u->channel_account_id,
                    ]);
            },
            'Nachrichten der Unterhaltung'
        );
    }
}
