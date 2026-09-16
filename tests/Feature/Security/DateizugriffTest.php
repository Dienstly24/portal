<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\Document;
use App\Models\User;
use App\Support\UploadRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Dateien und Uploads (Sicherheits-Nachpruefung 16.09.2026).
 *
 * Die Regel dahinter: eine Datei mit Kundendaten darf NIE dadurch
 * erreichbar werden, dass jemand ihre Adresse kennt. Zugriff entscheidet
 * ein Controller, nie der Webserver und nie das Dateisystem.
 */
class DateizugriffTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Die private Platte darf ihre Dateien NICHT selbst ausliefern.
     *
     * Laravel legt bei `serve => true` eine eigene Route an, die jede
     * Datei der Platte herausgibt - an der Berechtigungspruefung der
     * Anwendung vorbei. Auf der Platte liegen Nachweise, Ausweiskopien
     * und Signatur-Dokumente.
     */
    public function test_private_platte_liefert_nichts_selbst_aus(): void
    {
        $this->assertFalse(
            (bool) config('filesystems.disks.local.serve'),
            "Die private Platte liefert wieder selbst aus ('serve' => true)."
        );
    }

    /** Die oeffentliche Platte ist die einzige mit oeffentlicher Sichtbarkeit. */
    public function test_nur_die_oeffentliche_platte_ist_oeffentlich(): void
    {
        $this->assertSame('public', config('filesystems.disks.public.visibility'));
        $this->assertNotSame('public', config('filesystems.disks.local.visibility'));
    }

    /**
     * Ein Pfad aus dem Browser darf nie aus dem Kundenordner herausfuehren.
     *
     * Geprueft wird nicht die Absicht, sondern das Ergebnis: die Datei
     * ausserhalb liegt wirklich da, und sie kommt trotzdem nicht heraus.
     */
    public function test_kein_ausbruch_aus_dem_kundenordner(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('geheim/passwoerter.txt', 'NICHT-HERAUSGEBEN');

        $user = User::factory()->create(['role' => 'customer', 'email' => uniqid().'@example.de']);
        $kunde = Customer::create([
            'user_id' => $user->id,
            'customer_number' => '2600123',
            'preferred_lang' => 'de',
        ]);

        // Ein Dokument, dessen Pfad aus dem Kundenordner herausfuehrt -
        // so etwas darf gar nicht erst ausgeliefert werden.
        $dokument = Document::create([
            'id' => (string) Str::uuid(),
            'customer_id' => $kunde->id,
            'category' => 'other',
            'file_name' => 'harmlos.pdf',
            'file_path' => 'customers/'.$kunde->id.'/../../geheim/passwoerter.txt',
            'created_by' => $user->id,
            'disk' => 'local',
        ]);

        $antwort = $this->actingAs($user)->get('/portal/documents/'.$dokument->id.'/download');

        $this->assertStringNotContainsString('NICHT-HERAUSGEBEN', $antwort->getContent() ?: '',
            'Ein Pfad mit ".." hat eine Datei ausserhalb des Kundenordners herausgegeben.');
    }

    /**
     * Die Groessen- und Typgrenzen stehen an EINER Stelle und gelten.
     * Ein ausfuehrbares Skript darf nie als Kundenunterlage durchgehen.
     */
    public function test_ausfuehrbare_dateien_werden_abgelehnt(): void
    {
        Storage::fake('local');

        $mitarbeiter = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'customer', 'email' => uniqid().'@example.de']);
        $kunde = Customer::create([
            'user_id' => $user->id,
            'customer_number' => '2600124',
            'preferred_lang' => 'de',
        ]);

        foreach (['schadcode.php', 'schadcode.phtml', 'schadcode.sh', 'schadcode.html'] as $name) {
            $antwort = $this->actingAs($mitarbeiter)->post('/admin/customers/'.$kunde->id.'/documents', [
                'file' => UploadedFile::fake()->createWithContent($name, '<?php echo "x";'),
                'category' => 'other',
            ]);

            $this->assertNotSame(200, $antwort->getStatusCode(), $name.' wurde angenommen.');
            $this->assertSame(0, Document::where('file_name', $name)->count(),
                $name.' ist in der Kundenakte gelandet.');
        }

        // Die Whitelist selbst darf nie ausfuehrbare Endungen enthalten.
        foreach (['php', 'phtml', 'sh', 'exe', 'html', 'svg'] as $verboten) {
            foreach ([UploadRules::DOCUMENT_MIMES, UploadRules::ATTACHMENT_MIMES, UploadRules::PROOF_MIMES] as $liste) {
                $this->assertNotContains($verboten, explode(',', $liste),
                    'Die Datei-Whitelist erlaubt "'.$verboten.'".');
            }
        }
    }
}
