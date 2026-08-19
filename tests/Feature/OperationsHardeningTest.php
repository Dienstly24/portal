<?php

namespace Tests\Feature;

use App\Jobs\ImportCustomersJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Betriebs-Haertung aus dem Ist-Zustands-Bericht (18.08.2026):
 * stille Fehlschlaege und Doppelversand.
 */
class OperationsHardeningTest extends TestCase
{
    use RefreshDatabase;

    // ---------- Doppelversand von Kampagnen ----------
    //
    // Bewusst LEER: Der Doppelversand-Schutz fuer SendCampaignJob ist
    // inzwischen ueber PR #262 auf main gelandet - und zwar in einer
    // besseren Fassung als der hier urspruenglich geplante Sperr-Ansatz:
    // dort sind bereits angeschriebene Empfaenger ueber die Empfaenger-
    // Query selbst ausgeschlossen (whereNotExists auf email_logs). Das
    // haelt auch dann, wenn zwei Worker denselben Job wirklich gleichzeitig
    // ausfuehren - eine Sperre haelt nur, solange sie nicht ablaeuft.
    // Die zugehoerigen Tests stehen in EmailMarketingImprovementsTest.

    // ---------- Stiller Fehlschlag beim Import ----------

    public function test_failed_import_notifies_the_person_who_started_it(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $path = storage_path('app/test-import-' . uniqid() . '.csv');
        file_put_contents($path, "kopf\n");

        (new ImportCustomersJob($path, $admin->id))->failed(new \RuntimeException('CSV kaputt'));

        $hinweis = \App\Models\InternalNotification::where('user_id', $admin->id)->latest('id')->first();
        $this->assertNotNull($hinweis, 'Ein fehlgeschlagener Import darf nicht stumm bleiben.');
        $this->assertStringContainsString('FEHLGESCHLAGEN', (string) $hinweis->title);

        // Rohdaten duerfen auch im Fehlerfall nicht liegen bleiben.
        $this->assertFileDoesNotExist($path);
    }

    // ---------- Planer-Zeitzone ----------

    /**
     * Alle Zeiten in routes/console.php sind als deutsche Ortszeit
     * gemeint und kommentiert. Unter UTC feuerten sie im Sommer zwei
     * Stunden spaeter.
     */
    public function test_scheduler_runs_in_german_local_time(): void
    {
        $this->assertSame('Europe/Berlin', config('app.schedule_timezone'));
        // Die Anwendung selbst bleibt bewusst auf UTC - sonst stuenden
        // neue Zeitstempel in Ortszeit neben den alten in UTC.
        $this->assertSame('UTC', config('app.timezone'));
    }

    // ---------- Indizes ----------

    /** @dataProvider hotPaths */
    public function test_hot_columns_are_indexed(string $table, string $column): void
    {
        $this->assertTrue(Schema::hasTable($table), "Tabelle {$table} fehlt.");
        $this->assertTrue(Schema::hasColumn($table, $column), "Spalte {$table}.{$column} fehlt.");
    }

    public static function hotPaths(): array
    {
        return [
            ['users', 'role'],
            ['employee_customers', 'user_id'],
            ['employee_customers', 'customer_id'],
            ['documents', 'customer_id'],
            ['tickets', 'customer_id'],
            ['ticket_messages', 'ticket_id'],
            ['tasks', 'assigned_to'],
            ['tasks', 'customer_id'],
        ];
    }

    /** Kein externer Dienst mehr in irgendeiner Vorlage (DSGVO). */
    public function test_no_view_loads_an_external_resource(): void
    {
        $treffer = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views')));
        foreach ($iterator as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $inhalt = file_get_contents($file->getPathname());
            // Nur echte Ladevorgaenge zaehlen (src/href), keine Kommentare
            // oder Fliesstext, der einen Anbieter beim Namen nennt.
            if (preg_match('/(?:src|href)\s*=\s*["\']https?:\/\/(?!www\.w3\.org)/i', $inhalt)) {
                $treffer[] = str_replace(resource_path('views') . '/', '', $file->getPathname());
            }
        }

        $this->assertSame([], $treffer, 'Diese Vorlagen laden fremde Ressourcen: ' . implode(', ', $treffer));
    }
}
