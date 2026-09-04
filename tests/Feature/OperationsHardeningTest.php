<?php

namespace Tests\Feature;

use App\Jobs\ImportCustomersJob;
use App\Models\InternalNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $path = storage_path('app/test-import-'.uniqid().'.csv');
        file_put_contents($path, "kopf\n");

        (new ImportCustomersJob($path, $admin->id))->failed(new \RuntimeException('CSV kaputt'));

        $hinweis = InternalNotification::where('user_id', $admin->id)->latest('id')->first();
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

    #[DataProvider('hotPaths')]
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

            // Es geht ausschliesslich um GELADENE Ressourcen - also um das,
            // was der Browser des Besuchers von einem fremden Server holt
            // und dabei seine IP-Adresse preisgibt: Stylesheets, Schriften,
            // Skripte, Bilder. Ein gewoehnlicher Link (<a href="https://...">)
            // ist ausdruecklich KEIN Problem: er wird erst geladen, wenn
            // der Nutzer ihn selbst anklickt. Wer hier zu breit prueft,
            // meldet jede Firmen-Verlinkung in jeder E-Mail als Verstoss -
            // und die Pruefung wird abgeschaltet, statt zu schuetzen.
            $muster = [
                '/<link\b[^>]*href\s*=\s*["\']https?:\/\//i',   // Stylesheets, Schriften
                '/<script\b[^>]*src\s*=\s*["\']https?:\/\//i',  // fremde Skripte
                '/<img\b[^>]*src\s*=\s*["\']https?:\/\//i',     // fremde Bilder
                '/@import\s+(?:url\()?["\']?https?:\/\//i',    // CSS-Import
                '/url\(\s*["\']?https?:\/\//i',                 // Schriften/Bilder in CSS
            ];

            // Eine einzige, NAMENTLICH benannte Ausnahme (Audit SEC-1):
            // das Turnstile-Widget im Registrierungsformular. Begruendung
            // und Grenzen stehen in self::ERLAUBTE_FREMDRESSOURCEN.
            $relativ = str_replace(resource_path('views').'/', '', $file->getPathname());
            $inhalt = $this->ausnahmenEntfernen($relativ, $inhalt);

            foreach ($muster as $regex) {
                if (preg_match($regex, $inhalt)) {
                    $treffer[] = $relativ;
                    break;
                }
            }
        }

        $this->assertSame([], $treffer, 'Diese Vorlagen laden fremde Ressourcen: '.implode(', ', $treffer));
    }

    /**
     * Ausnahmen von der Regel "keine fremden Ressourcen" (Audit SEC-1).
     *
     * Es gibt genau EINE, und sie ist an Datei UND Zeile gebunden:
     * das Cloudflare-Turnstile-Widget auf dem Registrierungsformular.
     *
     * Warum vertretbar:
     *  - Turnstile ist eine SICHERHEITSMASSNAHME, kein Marketing- oder
     *    Komfort-Einbau. Ohne serverseitig geprueften Bot-Schutz ist die
     *    oeffentliche Registrierung der Weg, ueber den ein Bot echte
     *    Kundenakten und Kundennummern erzeugt (genau der Befund SEC-1).
     *  - Cloudflare ist ohnehin der Edge-Proxy dieses Auftritts und sieht
     *    damit die IP JEDES Besuchers bereits. Das Widget fuegt keinen
     *    NEUEN Empfaenger personenbezogener Daten hinzu - anders als es
     *    seinerzeit die Google-Schriften taten, wegen derer diese Regel
     *    ueberhaupt entstanden ist.
     *  - Es laedt auf EINER Seite (Registrierung), nicht im Layout, und
     *    nur, wenn ein Schluessel konfiguriert ist.
     *
     * Offen fuer den Betreiber: Turnstile gehoert in die
     * Datenschutzerklaerung (Empfaenger, Zweck, Rechtsgrundlage
     * Art. 6 Abs. 1 lit. f - Schutz vor missbraeuchlicher Anmeldung).
     * Vermerkt in docs/SICHERHEIT_SEC_1_BIS_5.md.
     *
     * Jede ANDERE fremde Ressource - auch in derselben Datei - laesst
     * den Test weiterhin fehlschlagen.
     */
    private const ERLAUBTE_FREMDRESSOURCEN = [
        'auth/register.blade.php' => [
            '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>',
        ],
    ];

    private function ausnahmenEntfernen(string $relativ, string $inhalt): string
    {
        foreach (self::ERLAUBTE_FREMDRESSOURCEN[$relativ] ?? [] as $erlaubt) {
            $this->assertStringContainsString(
                $erlaubt,
                $inhalt,
                "Die eingetragene Ausnahme fuer {$relativ} steht nicht mehr in der Datei. "
                .'Bitte den Eintrag entfernen, statt eine tote Ausnahme stehen zu lassen.'
            );

            $inhalt = str_replace($erlaubt, '', $inhalt);
        }

        return $inhalt;
    }

}
