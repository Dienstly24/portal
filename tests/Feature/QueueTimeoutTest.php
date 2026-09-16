<?php

namespace Tests\Feature;

use App\Jobs\ImportCustomersJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * DIE REGEL STEHT NICHT MEHR NUR IM KOMMENTAR (Audit 15.09.2026).
 *
 * In config/queue.php stand seit langem: "retry_after MUSS groesser
 * sein als das laengste Job-Timeout, sonst nimmt ein zweiter Worker den
 * Job an, waehrend der erste noch laeuft." `ImportCustomersJob` hielt
 * sich trotzdem nicht daran ($timeout = 1800 gegen retry_after = 360) -
 * ein Kommentar kann eben nichts erzwingen.
 *
 * Die Folge war kein Absturz, sondern etwas Schlimmeres: ein Import,
 * der laenger als sechs Minuten lief, wurde erneut aus der Schlange
 * geholt und als FEHLGESCHLAGEN eingetragen, obwohl er in Wahrheit
 * sauber durchlief. Der Betreiber sah Rot fuer einen gelungenen Lauf.
 *
 * Dieser Test prueft JEDEN Job im Projekt - auch jeden kuenftigen.
 */
class QueueTimeoutTest extends TestCase
{
    /** @return array<int, class-string> */
    private function alleJobs(): array
    {
        $klassen = [];

        foreach (File::allFiles(app_path('Jobs')) as $datei) {
            if (! str_ends_with($datei->getFilename(), '.php')) {
                continue;
            }
            $relativ = str_replace(['/', '.php'], ['\\', ''], $datei->getRelativePathname());
            $klasse = 'App\\Jobs\\'.$relativ;

            if (class_exists($klasse) && is_subclass_of($klasse, ShouldQueue::class)) {
                $klassen[] = $klasse;
            }
        }

        return $klassen;
    }

    /**
     * Verbindung eines Jobs.
     *
     * Die Eigenschaft $connection kommt aus dem Trait Queueable und
     * traegt dort keinen Vorgabewert - ein Job setzt sie im Konstruktor
     * (eine eigene Deklaration mit Vorgabewert waere ein fataler Fehler
     * beim Laden der Klasse). Deshalb wird hier eine Instanz gebaut,
     * wenn das ohne Nebenwirkungen moeglich ist.
     */
    private function verbindungVon(string $klasse, ?object $instanz = null): string
    {
        if ($instanz && $instanz->connection) {
            return (string) $instanz->connection;
        }

        $eigenschaften = (new \ReflectionClass($klasse))->getDefaultProperties();

        return (string) ($eigenschaften['connection'] ?? config('queue.default'));
    }

    public function test_es_gibt_ueberhaupt_jobs_zu_pruefen(): void
    {
        $this->assertGreaterThanOrEqual(5, count($this->alleJobs()),
            'Verdaechtig wenige Jobs gefunden - sucht der Test noch am richtigen Ort?');
    }

    /** DIE KERNREGEL. */
    public function test_kein_job_laeuft_laenger_als_das_retry_after_seiner_verbindung(): void
    {
        // In der Testsuite steht queue.default auf `sync`; geprueft wird
        // aber der BETRIEBSFALL, und dort ist es die Datenbank.
        config(['queue.default' => 'database']);
        $verstoesse = [];

        foreach ($this->alleJobs() as $klasse) {
            $eigenschaften = (new \ReflectionClass($klasse))->getDefaultProperties();
            $timeout = (int) ($eigenschaften['timeout'] ?? 0);

            if ($timeout <= 0) {
                continue; // Kein eigenes Zeitlimit - der Worker entscheidet.
            }

            $verbindung = $this->verbindungVon($klasse, $this->instanzOderNull($klasse));
            $retryAfter = (int) config('queue.connections.'.$verbindung.'.retry_after', 0);

            if ($retryAfter <= 0) {
                continue; // Treiber ohne retry_after (z. B. sync, sqs).
            }

            if ($timeout >= $retryAfter) {
                $verstoesse[] = sprintf(
                    '%s: timeout=%ds >= retry_after=%ds (Verbindung "%s")',
                    class_basename($klasse), $timeout, $retryAfter, $verbindung
                );
            }
        }

        $this->assertSame([], $verstoesse,
            'Ein Job darf nie laenger laufen duerfen als das retry_after seiner Warteschlange - '
            .'sonst wird er erneut angenommen, waehrend er noch laeuft, und als fehlgeschlagen '
            ."eingetragen:\n".implode("\n", $verstoesse));
    }

    /** Jeder Job sagt ausdruecklich, wie oft er versucht werden darf. */
    public function test_jeder_job_legt_tries_und_timeout_fest(): void
    {
        $ohne = [];

        foreach ($this->alleJobs() as $klasse) {
            $eigenschaften = (new \ReflectionClass($klasse))->getDefaultProperties();

            if (! isset($eigenschaften['timeout'])) {
                $ohne[] = class_basename($klasse).': kein $timeout';
            }
            if (! isset($eigenschaften['tries'])) {
                $ohne[] = class_basename($klasse).': kein $tries';
            }
        }

        $this->assertSame([], $ohne,
            'Ein Job ohne ausdrueckliches Zeitlimit oder Versuchslimit erbt stille '
            ."Voreinstellungen vom Worker - und die stehen auf einem anderen Rechner:\n"
            .implode("\n", $ohne));
    }

    /** Der lange Import laeuft auf der dafuer gebauten Verbindung. */
    /**
     * Eine Instanz bauen, wenn der Konstruktor nur einfache Werte
     * braucht - nur so ist eine im Konstruktor gesetzte Verbindung
     * sichtbar. Geht es nicht, entscheidet die Vorgabe.
     */
    private function instanzOderNull(string $klasse): ?object
    {
        $konstruktor = (new \ReflectionClass($klasse))->getConstructor();

        if (! $konstruktor) {
            return null;
        }

        $argumente = [];
        foreach ($konstruktor->getParameters() as $parameter) {
            if ($parameter->isDefaultValueAvailable()) {
                $argumente[] = $parameter->getDefaultValue();

                continue;
            }
            $typ = $parameter->getType();
            if (! $typ instanceof \ReflectionNamedType || ! $typ->isBuiltin()) {
                return null; // Braucht ein Modell o.ae. - nicht gefahrlos baubar.
            }
            $argumente[] = match ($typ->getName()) {
                'string' => 'x',
                'int' => 0,
                'bool' => false,
                'array' => [],
                'float' => 0.0,
                default => null,
            };
        }

        try {
            return new $klasse(...$argumente);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Der Import geht auf die lange Verbindung - aber NUR, wenn die
     * Anwendung ueberhaupt eine Datenbank-Warteschlange benutzt.
     *
     * Auf `sync` (Testsuite, Installation ohne Worker) muss er weiterhin
     * SOFORT laufen. Wuerde er dort in die Schlange gelegt, arbeitete
     * ihn niemand ab und der Import faende einfach nicht mehr statt -
     * genau das hat die Testsuite beim ersten Anlauf gemeldet.
     */
    public function test_import_job_nutzt_die_lange_verbindung_nur_bei_datenbank_queue(): void
    {
        config(['queue.default' => 'sync']);
        $syncJob = new ImportCustomersJob('/tmp/egal.csv');
        $this->assertNull($syncJob->connection,
            'Auf sync darf der Import nicht in eine Warteschlange umgelenkt werden.');

        config(['queue.default' => 'database']);
        $job = new ImportCustomersJob('/tmp/egal.csv');
        $this->assertSame('database-lang', (string) $job->connection);
        $this->assertSame('lang', (string) $job->queue);

        // Und dasselbe nach einem Umzug auf Redis - sonst waere der in
        // docs/ANLEITUNG_REDIS_AR.md beschriebene Wechsel genau der
        // Fehler, den diese Trennung gerade behoben hat (redis hat
        // retry_after = 90 s bei einem Zeitlimit von 1800 s).
        config(['queue.default' => 'redis']);
        $redisJob = new ImportCustomersJob('/tmp/egal.csv');
        $this->assertSame('redis-lang', (string) $redisJob->connection);
        $this->assertGreaterThan(
            (int) (new \ReflectionClass(ImportCustomersJob::class))
                ->getDefaultProperties()['timeout'],
            (int) config('queue.connections.database-lang.retry_after')
        );
    }

    /** Die Regel gilt auch nach einem Umzug auf Redis. */
    public function test_regel_haelt_auch_auf_redis(): void
    {
        config(['queue.default' => 'redis']);
        $verstoesse = [];

        foreach ($this->alleJobs() as $klasse) {
            $eigenschaften = (new \ReflectionClass($klasse))->getDefaultProperties();
            $timeout = (int) ($eigenschaften['timeout'] ?? 0);
            if ($timeout <= 0) {
                continue;
            }
            $verbindung = $this->verbindungVon($klasse, $this->instanzOderNull($klasse));
            $retryAfter = (int) config('queue.connections.'.$verbindung.'.retry_after', 0);
            if ($retryAfter > 0 && $timeout >= $retryAfter) {
                $verstoesse[] = class_basename($klasse).": timeout={$timeout}s >= retry_after={$retryAfter}s ({$verbindung})";
            }
        }

        $this->assertSame([], $verstoesse,
            "Auf Redis gilt dieselbe Regel:\n".implode("\n", $verstoesse));
    }

    /** Die lange Verbindung benutzt dieselbe Tabelle - nur eine andere Schlange. */
    public function test_lange_verbindung_teilt_tabelle_und_treiber(): void
    {
        $this->assertSame(
            config('queue.connections.database.driver'),
            config('queue.connections.database-lang.driver')
        );
        $this->assertSame(
            config('queue.connections.database.table'),
            config('queue.connections.database-lang.table')
        );
        $this->assertNotSame(
            config('queue.connections.database.queue'),
            config('queue.connections.database-lang.queue'),
            'Eine eigene Schlange ist noetig, sonst zieht der bestehende Worker die langen Jobs.'
        );
    }
}
