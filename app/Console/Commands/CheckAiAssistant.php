<?php

namespace App\Console\Commands;

use App\Models\AiAssistantLog;
use App\Models\AiKnowledgeEntry;
use App\Models\CustomerMessage;
use App\Services\Ai\Assistant\AssistantSettings;
use App\Services\Ai\Assistant\Contracts\AssistantProviderInterface;
use App\Services\Ai\Assistant\NullAssistantProvider;
use App\Services\Ai\Assistant\Tools\AssistantToolRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Selbstdiagnose des KI-Kundenassistenten (Betreiber-Auftrag 18.08.2026).
 *
 * Warum es diesen Befehl gibt: der Assistent ist eine KETTE - Schalter,
 * Anbieter, Schluessel, Endpunkt, Warteschlange, Wissensbasis. Reisst ein
 * Glied, sieht der Betreiber immer dasselbe: es kommt keine KI-Antwort.
 * Genau das ist die schlechteste Fehlermeldung, die es gibt, denn jede der
 * Ursachen hat eine ANDERE Loesung. Der Befehl prueft die Kette in der
 * Reihenfolge, in der sie im Betrieb durchlaufen wird, und nennt zu jedem
 * Fund den naechsten Schritt.
 *
 * Der Befehl ist LESEND. Nur mit `--live` geht ein echter (winziger)
 * Testaufruf an den KI-Dienst raus - das ist die einzige Pruefung, die
 * Schluessel, Endpunkt UND Modellfreigabe wirklich beweist.
 *
 * Der Schluessel wird NIE ausgegeben, auch nicht teilweise.
 */
class CheckAiAssistant extends Command
{
    protected $signature = 'ki:pruefen {--live : Echten Testaufruf an den KI-Dienst senden (kostet wenige Cent-Bruchteile)}';
    protected $description = 'KI-Kundenassistent pruefen: Schalter, Anbieter, Zugang, Warteschlange, Wissensbasis, letzte Ergebnisse';

    /** Sammelt die Punkte, die den Betrieb blockieren. */
    private array $blocker = [];

    /** Sammelt Punkte, die den Betrieb nicht verhindern, aber schwaechen. */
    private array $hinweise = [];

    public function handle(AssistantSettings $settings, AssistantToolRegistry $registry): int
    {
        $this->line('');
        $this->line('=== KI-Kundenassistent: Selbstdiagnose ===');
        $this->line('Zeitpunkt: '.now()->format('d.m.Y H:i').'   Umgebung: '.app()->environment());

        $this->pruefeSchalter($settings);
        $provider = $this->pruefeAnbieter();
        $this->pruefeDatenbank();
        $this->pruefeWarteschlange();
        $this->pruefeWissensbasis($registry);
        $this->pruefeVerlauf();

        if ($this->option('live')) {
            $this->pruefeLive($provider);
        } else {
            $this->line('');
            $this->line('Hinweis: echter Testaufruf nicht ausgefuehrt. Dafuer:');
            $this->line('  php artisan ki:pruefen --live');
        }

        return $this->ergebnis();
    }

    // ---------------------------------------------------------------- 1
    private function pruefeSchalter(AssistantSettings $settings): void
    {
        $this->abschnitt('1) Schalter (Beraterwelt -> Einstellungen)');

        if ($settings->enabled()) {
            $this->ok('Hauptschalter ist AN.');
        } else {
            $this->fehler('Hauptschalter ist AUS - es wird gar kein KI-Auftrag erzeugt.');
            $this->blocker[] = 'Hauptschalter einschalten: /admin/settings -> "KI-Kundenassistent" -> Assistent aktiv.';
        }

        if ($settings->autoReply()) {
            $this->ok('Automatische Antworten sind AN.');
        } else {
            $this->fehler('Automatische Antworten sind AUS - die KI schweigt im Portal-Chat.');
            $this->blocker[] = 'Automatische Antworten einschalten: /admin/settings -> "KI-Kundenassistent".';
        }

        $max = $settings->maxRepliesPerCase();
        $this->line('   Weitere Schalter: Dokumentenanforderung '
            .$this->anAus($settings->autoDocumentRequest())
            .', Vorgang anlegen '.$this->anAus($settings->autoTicket())
            .', Uebergabe '.$this->anAus($settings->autoHandover())
            .', max. Antworten je Vorgang '.($max === 0 ? 'unbegrenzt' : $max).'.');
    }

    // ---------------------------------------------------------------- 2
    private function pruefeAnbieter(): AssistantProviderInterface
    {
        $this->abschnitt('2) Anbieter und Zugang');

        $gewaehlt = trim((string) config('services.ai_assistant_provider'));
        $provider = app(AssistantProviderInterface::class);

        $this->line('   AI_ASSISTANT_PROVIDER: '.($gewaehlt === '' ? '(leer -> Standard claude)' : $gewaehlt));
        $this->line('   Aktive Klasse:         '.$provider::class);

        if ($provider instanceof NullAssistantProvider) {
            $this->fehler('Anbieter ist bewusst abgeschaltet (AI_ASSISTANT_PROVIDER=none).');
            $this->blocker[] = 'In der Server-.env AI_ASSISTANT_PROVIDER=claude setzen (oder Zeile entfernen).';

            return $provider;
        }

        if ($provider->isEnabled()) {
            $this->ok('Zugangsschluessel ist gesetzt.');
        } else {
            $this->fehler('Kein Zugangsschluessel gesetzt - jeder Aufruf endet im Fallback.');
            $this->blocker[] = $provider->name() === 'openai'
                ? 'OPENAI_API_KEY in die Server-.env eintragen.'
                : 'ANTHROPIC_API_KEY in die Server-.env eintragen (derselbe Schluessel wie fuer die Dokumentanalyse).';
        }

        $this->line('   Modell:   '.$provider->model());
        if ($provider->name() === 'claude') {
            // Endpunkt zeigen: die Basis-URL war schon einmal die Ursache
            // dafuer, dass jeder Aufruf ins Leere lief.
            $basis = rtrim((string) config('services.anthropic.base_url', 'https://api.anthropic.com'), '/');
            if (str_ends_with($basis, '/v1')) {
                $basis = substr($basis, 0, -3);
            }
            $this->line('   Endpunkt: '.rtrim($basis, '/').'/v1/messages');
        }

        return $provider;
    }

    // ---------------------------------------------------------------- 3
    private function pruefeDatenbank(): void
    {
        $this->abschnitt('3) Datenbank (Migrationen)');

        $fehlend = [];
        foreach (['ai_conversations', 'ai_assistant_logs', 'ai_knowledge_entries'] as $tabelle) {
            if (! Schema::hasTable($tabelle)) {
                $fehlend[] = $tabelle;
            }
        }
        if (! Schema::hasColumn('customer_messages', 'ai_generated')) {
            $fehlend[] = 'customer_messages.ai_generated';
        }

        if ($fehlend === []) {
            $this->ok('Alle Tabellen und Spalten sind vorhanden.');
        } else {
            $this->fehler('Fehlt: '.implode(', ', $fehlend));
            $this->blocker[] = 'Migrationen nachziehen: php artisan migrate --force';
        }
    }

    // ---------------------------------------------------------------- 4
    private function pruefeWarteschlange(): void
    {
        $this->abschnitt('4) Warteschlange (die Antwort ist ein Job)');

        $verbindung = (string) config('queue.default');
        $this->line('   QUEUE_CONNECTION: '.$verbindung);

        if ($verbindung === 'sync') {
            $this->ok('Antworten laufen direkt im Request - kein Worker noetig.');

            return;
        }

        if ($verbindung !== 'database' || ! Schema::hasTable('jobs')) {
            $this->line('   Rueckstau kann bei dieser Verbindung nicht gelesen werden.');
            $this->hinweise[] = 'Sicherstellen, dass ein Queue-Worker laeuft (sonst antwortet die KI nie).';

            return;
        }

        $offen = (int) DB::table('jobs')->count();
        $alt = DB::table('jobs')->where('available_at', '<', now()->subMinutes(5)->timestamp)->count();
        $this->line('   Offene Jobs: '.$offen.' (davon aelter als 5 Minuten: '.$alt.')');

        if ($alt > 0) {
            $this->fehler('Jobs bleiben liegen - der Queue-Worker laeuft vermutlich nicht.');
            $this->blocker[] = 'Queue-Worker starten/ueberwachen (Supervisor): php artisan queue:work --queue=default';
        } else {
            $this->ok('Kein Rueckstau in der Warteschlange.');
        }

        if (Schema::hasTable('failed_jobs')) {
            $gescheitert = (int) DB::table('failed_jobs')
                ->where('payload', 'like', '%AnswerCustomerMessageJob%')
                ->count();
            if ($gescheitert > 0) {
                $this->fehler($gescheitert.' fehlgeschlagene KI-Antwort-Job(s) in failed_jobs.');
                $this->hinweise[] = 'Fehlertext ansehen: php artisan queue:failed';
            }
        }

        // Das Sicherheitsnetz greift nur, wenn der Scheduler-Cron laeuft.
        $this->line('   Sicherheitsnetz: ai:answer-pending laeuft alle 10 Minuten ueber den Scheduler.');
        $this->line('   Cron muss dafuer gesetzt sein: * * * * * php artisan schedule:run');
    }

    // ---------------------------------------------------------------- 5
    private function pruefeWissensbasis(AssistantToolRegistry $registry): void
    {
        $this->abschnitt('5) Werkzeuge und Wissensbasis');

        $this->ok(count($registry->names()).' freigegebene Werkzeuge: '.implode(', ', $registry->names()));

        $eintraege = Schema::hasTable('ai_knowledge_entries')
            ? AiKnowledgeEntry::where('active', true)->count()
            : 0;

        $entwuerfe = Schema::hasTable('ai_knowledge_entries')
            ? AiKnowledgeEntry::where('active', false)->count()
            : 0;

        if ($eintraege > 0) {
            $this->ok($eintraege.' aktive Wissenseintraege.');
            if ($entwuerfe > 0) {
                $this->line('   Zusaetzlich '.$entwuerfe.' Entwuerfe (inaktiv) - der Assistent nutzt sie nicht.');
                $this->line('   Durchsehen und freigeben: /admin/ki-wissensbasis (Filter "Nur Entwürfe").');
            }
        } else {
            $this->warnung('Wissensbasis ist LEER.');
            $this->line('   Das ist kein Fehler, aber der Assistent wirkt dadurch nutzlos: er darf');
            $this->line('   nichts behaupten, was nicht belegt ist, und uebergibt fast jede allgemeine');
            $this->line('   Frage an das Team. Fragen zur eigenen Akte beantwortet er trotzdem.');
            if ($entwuerfe > 0) {
                $this->line('   '.$entwuerfe.' Entwuerfe liegen bereit, aber KEINER ist freigegeben.');
                $this->line('   Durchsehen und freigeben: /admin/ki-wissensbasis (Filter "Nur Entwürfe").');
            }
            $this->line('   Schnellster Start: php artisan ki:wissensbasis-vorschlag --schreiben');
            $this->line('   (uebertraegt die Texte der Leistungsseiten woertlich als INAKTIVE Entwuerfe;');
            $this->line('   freigegeben wird von Hand unter /admin/ki-wissensbasis).');
            $this->hinweise[] = 'Wissensbasis fuellen: php artisan ki:wissensbasis-vorschlag --schreiben, dann freigeben unter /admin/ki-wissensbasis';
        }
    }

    // ---------------------------------------------------------------- 6
    private function pruefeVerlauf(): void
    {
        $this->abschnitt('6) Was ist zuletzt passiert (letzte 7 Tage)');

        if (! Schema::hasTable('ai_assistant_logs')) {
            $this->line('   Kein Protokoll vorhanden (Tabelle fehlt).');

            return;
        }

        $seit = now()->subDays(7);
        $gesamt = AiAssistantLog::where('created_at', '>=', $seit)->count();

        if ($gesamt === 0) {
            $this->warnung('Keine einzige KI-Runde protokolliert.');
            $this->line('   Das heisst: der Assistent wurde nie angestossen. Typische Ursachen sind');
            $this->line('   der Hauptschalter (Abschnitt 1) oder ein nicht laufender Worker (Abschnitt 4).');
        } else {
            foreach (AiAssistantLog::where('created_at', '>=', $seit)
                ->select('outcome', DB::raw('count(*) as anzahl'))
                ->groupBy('outcome')
                ->pluck('anzahl', 'outcome') as $ergebnis => $anzahl) {
                $this->line('   '.str_pad((string) $anzahl, 5, ' ', STR_PAD_LEFT).'x  '
                    .(AiAssistantLog::OUTCOME_LABELS[$ergebnis] ?? $ergebnis));
            }

            $fallback = AiAssistantLog::where('created_at', '>=', $seit)
                ->where('outcome', AiAssistantLog::OUTCOME_FALLBACK)->count();
            if ($fallback > 0) {
                $this->fehler($fallback.' Runde(n) endeten im Fallback - der KI-Dienst war nicht erreichbar.');
                $this->hinweise[] = 'Genaue Ursache zeigt: php artisan ki:pruefen --live';
            }
        }

        // Kundennachrichten ohne jede KI-Runde sind das Symptom, das der
        // Betreiber sieht ("es passiert nichts").
        $ohne = CustomerMessage::fromCustomer()
            ->where('created_at', '>=', $seit)
            ->whereNotIn('id', AiAssistantLog::whereNotNull('customer_message_id')->pluck('customer_message_id'))
            ->count();
        $this->line('   Kundennachrichten ohne KI-Runde: '.$ohne);
    }

    // ---------------------------------------------------------------- 7
    private function pruefeLive(AssistantProviderInterface $provider): void
    {
        $this->abschnitt('7) Echter Testaufruf');

        if ($provider instanceof NullAssistantProvider || ! $provider->isEnabled()) {
            $this->fehler('Uebersprungen - kein einsatzbereiter Anbieter (siehe Abschnitt 2).');

            return;
        }

        $start = microtime(true);
        try {
            $turn = $provider->turn(
                'Du bist ein Verbindungstest. Antworte mit genau einem Wort: OK',
                [['role' => 'user', 'text' => 'Verbindungstest']],
                [],
                50,
            );
        } catch (\Throwable $e) {
            $this->fehler('Aufruf fehlgeschlagen: '.$e->getMessage());
            $this->blocker[] = 'Fehlertext oben pruefen: 401 = falscher Schluessel, 404 = Modell nicht'
                .' freigegeben oder falscher Endpunkt, Zeitueberschreitung = Netzwerk/Firewall.';

            return;
        }

        $dauer = (int) round((microtime(true) - $start) * 1000);
        $this->ok('Der KI-Dienst hat geantwortet ('.$dauer.' ms).');
        $this->line('   Modell: '.$turn->model);
        // Ein leerer Text ist hier KEIN Fehler: entscheidend ist, dass der
        // Dienst ueberhaupt erreichbar war und das Modell freigegeben ist.
        $this->line('   Antworttext: '.($turn->text === ''
            ? '(leer - fuer den Verbindungstest ohne Bedeutung)'
            : '"'.mb_substr($turn->text, 0, 60).'"'));
        $this->line('   Tokens: '.($turn->inputTokens ?? '?').' ein / '.($turn->outputTokens ?? '?').' aus');
    }

    // ---------------------------------------------------------------- Ende
    private function ergebnis(): int
    {
        $this->line('');
        $this->line('=== Ergebnis ===');

        if ($this->blocker === []) {
            $this->info('Kette vollstaendig - der Assistent kann arbeiten.');
            foreach ($this->hinweise as $hinweis) {
                $this->line('  Hinweis: '.$hinweis);
            }

            return self::SUCCESS;
        }

        $this->error('Der Assistent kann so NICHT antworten. Naechste Schritte in dieser Reihenfolge:');
        foreach ($this->blocker as $i => $schritt) {
            $this->line('  '.($i + 1).'. '.$schritt);
        }
        foreach ($this->hinweise as $hinweis) {
            $this->line('  Hinweis: '.$hinweis);
        }

        return self::FAILURE;
    }

    private function abschnitt(string $titel): void
    {
        $this->line('');
        $this->line($titel);
    }

    private function ok(string $text): void
    {
        $this->info('   OK   '.$text);
    }

    private function warnung(string $text): void
    {
        $this->warn('   !    '.$text);
    }

    private function fehler(string $text): void
    {
        $this->error('   FEHL '.$text);
    }

    private function anAus(bool $wert): string
    {
        return $wert ? 'AN' : 'AUS';
    }
}
