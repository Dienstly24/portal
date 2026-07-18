<?php

namespace Tests\Feature;

use App\Jobs\ImportCustomersJob;
use App\Models\InternalNotification;
use App\Models\User;
use App\Services\Import\CustomerCsvImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regressionsschutz: ein Kunden-Import mit sehr vielen Warnungen darf die
 * Abschluss-Notification nicht ueberlaufen lassen (internal_notifications.body
 * ist string(500)); sonst schlaegt der Job trotz erfolgreichem Import fehl
 * (Produktionsfehler: SQLSTATE[22001] Data too long for column 'body').
 */
class ImportNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_completion_notification_body_stays_within_column_limit(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);

        $path = tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($path, "dummy\n");

        $errors = [];
        for ($i = 0; $i < 300; $i++) {
            $errors[] = "Zeile $i: SQLSTATE[23000] Duplicate entry 'benutzer$i@example.com' for key 'users.users_email_unique'";
        }

        // Importer, der viele Warnungen zurueckliefert (Duplikate).
        $this->app->bind(CustomerCsvImporter::class, fn () => new class($errors) extends CustomerCsvImporter {
            public function __construct(private array $err) {}

            public function commit(string $path, ?int $actorId = null): array
            {
                return ['imported' => 978, 'skipped' => 0, 'errors' => $this->err];
            }
        });

        (new ImportCustomersJob($path, $actor->id))->handle(app(CustomerCsvImporter::class));

        $note = InternalNotification::where('user_id', $actor->id)->latest()->first();
        $this->assertNotNull($note, 'Abschluss-Notification muss erstellt werden');
        $this->assertLessThanOrEqual(500, mb_strlen((string) $note->body));
        $this->assertStringContainsString('978 Kunden importiert', $note->body);
        $this->assertStringContainsString('weitere', $note->body);
    }
}
