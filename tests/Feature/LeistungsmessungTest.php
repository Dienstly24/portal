<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Models\User;
use App\Services\Reporting\AnalyticsFilters;
use App\Services\Reporting\DashboardAnalyticsService;
use App\Support\ChatFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Leistungsmessung als TEST, nicht als Behauptung (16.09.2026).
 *
 * Die Zahlen des Audits (620,5 ms -> 18,7 ms; 473,0 kB -> 0,1 kB) waren
 * Einzelmessungen auf einer Maschine. Ein Text haelt eine Verbesserung
 * nicht - er beschreibt sie nur. Hier stehen deshalb OBERGRENZEN, die
 * die Eigenschaft absichern, auf der die Verbesserung beruht:
 *
 *  - Der Verlauf darf nicht mit der ZEILENZAHL wachsen (er rechnete
 *    frueher je Datenzeile alle Zeitraumgrenzen neu).
 *  - Eine Chat-Abfrage mit Stand darf nicht mit der LAENGE des Verlaufs
 *    wachsen (sie holte frueher jedes Mal alles).
 *
 * Absolute Millisekunden stehen bewusst NICHT im Test: sie haengen an
 * der Maschine und wuerden auf einem langsamen Runner grundlos rot.
 * Geprueft wird das VERHAELTNIS - und das ist maschinenunabhaengig.
 */
class LeistungsmessungTest extends TestCase
{
    use RefreshDatabase;

    private function kunde(): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'email' => uniqid().'@example.de']);

        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => '26'.substr((string) uniqid(), -5),
            'preferred_lang' => 'de',
        ]);
    }

    /**
     * Der Verlauf skaliert mit der Zahl der Vertraege, nicht quadratisch.
     *
     * Vor der Behebung entstanden die Zeitraumgrenzen INNERHALB der
     * Zeilenschleife - der Aufwand war Zeilen x Zeitpunkte. Jetzt
     * einmal davor. Verzehnfacht man die Zeilen, darf die Zeit nicht
     * um ein Vielfaches davon steigen.
     */
    public function test_der_verlauf_waechst_nicht_ueberproportional(): void
    {
        $kunde = $this->kunde();

        $messen = function (int $anzahl) use ($kunde): float {
            Contract::query()->delete();
            $zeilen = [];
            for ($i = 0; $i < $anzahl; $i++) {
                $zeilen[] = [
                    'id' => (string) Str::uuid(),
                    'customer_id' => $kunde->id,
                    'type' => 'kfz',
                    'insurer' => 'Messung',
                    'status' => 'active',
                    'start_date' => now()->subDays($i % 300)->toDateString(),
                    'premium_amount' => 100,
                    'premium_interval' => 'monthly',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            foreach (array_chunk($zeilen, 200) as $teil) {
                Contract::insert($teil);
            }

            $dienst = app(DashboardAnalyticsService::class);
            $filter = AnalyticsFilters::ausRequest(Request::create('/admin/reports', 'GET', [
                'von' => now()->subYear()->toDateString(),
                'bis' => now()->toDateString(),
            ]));

            $start = microtime(true);
            $dienst->auswerten($filter);

            return (microtime(true) - $start) * 1000;
        };

        $klein = $messen(50);
        $gross = $messen(500);

        // Zehnfache Datenmenge darf nicht mehr als das 10-fache kosten
        // (mit Luft nach oben fuer Rauschen auf geteilten Maschinen).
        $faktor = $gross / max($klein, 0.001);

        $this->assertLessThan(15.0, $faktor, sprintf(
            'Der Verlauf waechst ueberproportional: 50 Zeilen %.1f ms, 500 Zeilen %.1f ms (Faktor %.1f). '
            .'Das ist das Muster von vor der Behebung - die Zeitraumgrenzen entstehen wieder je Zeile.',
            $klein, $gross, $faktor
        ));
    }

    /**
     * Eine Chat-Abfrage MIT Stand liefert nur das Neue - unabhaengig
     * davon, wie lang der Verlauf ist.
     */
    public function test_chat_abfrage_waechst_nicht_mit_dem_verlauf(): void
    {
        $kunde = $this->kunde();

        // Zeitstempel BEWUSST gestreut: ein Verlauf waechst ueber Wochen.
        // Werden alle Nachrichten in derselben Sekunde angelegt, liegen
        // sie alle im Vergleichsfenster - dann misst man die Testdaten,
        // nicht das Verhalten.
        $schreiben = function (int $anzahl, int $minutenZurueck) use ($kunde) {
            for ($i = 0; $i < $anzahl; $i++) {
                $zeit = now()->subMinutes($minutenZurueck - $i);
                $nachricht = CustomerMessage::create([
                    'customer_id' => $kunde->id,
                    'sender_id' => $kunde->user_id,
                    'body' => str_repeat('Nachrichtentext ', 20).$i,
                    'from_staff' => false,
                ]);
                // Zeitstempel sind nicht fillable - sonst stuenden alle
                // Nachrichten auf "jetzt" und der Test maesse sich selbst.
                CustomerMessage::withoutTimestamps(fn () => $nachricht
                    ->forceFill(['created_at' => $zeit, 'updated_at' => $zeit])->save());
            }
        };

        $schreiben(60, 600);
        $stand = Carbon::parse(ChatFeed::neuerStand());
        $schreiben(3, 3);

        $neu = ChatFeed::nachrichten($kunde->id, $stand);
        $alles = ChatFeed::nachrichten($kunde->id, null, allesLaden: true);

        // Der Stand liefert die drei neuen (plus die kleine Ueberlappung,
        // die den Lesehaken nachtraegt) - nie den ganzen Verlauf.
        $this->assertLessThanOrEqual(
            3 + 5, // 3 neue + Luft fuer die Ueberlappung, die den Lesehaken nachtraegt
            $neu->count(),
            'Die Abfrage mit Stand holt mehr als das Neue - dann waechst jede Abfrage wieder mit dem Verlauf.'
        );
        $this->assertSame(63, $alles->count(), 'Mit alles=1 muss der ganze Verlauf kommen.');

        // Und der ERSTE Aufruf ohne Stand ist seitenweise begrenzt.
        $erste = ChatFeed::nachrichten($kunde->id, null);
        $this->assertLessThanOrEqual(ChatFeed::SEITENGROESSE, $erste->count());
    }

    /**
     * Die Vertragsliste laedt nie den ganzen Bestand in den Speicher.
     * Gemessen an der Zahl der GELADENEN Modelle, nicht an der Zeit.
     */
    public function test_vertragsliste_laedt_nur_eine_seite(): void
    {
        $kunde = $this->kunde();
        $zeilen = [];
        for ($i = 0; $i < 120; $i++) {
            $zeilen[] = [
                'id' => (string) Str::uuid(),
                'customer_id' => $kunde->id,
                'type' => 'kfz',
                'insurer' => 'Messung '.$i,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        Contract::insert($zeilen);

        $admin = User::factory()->create(['role' => 'admin']);
        $antwort = $this->actingAs($admin)->get('/admin/contracts');

        $antwort->assertOk();
        $liste = $antwort->viewData('contracts');

        $this->assertLessThanOrEqual(50, $liste->count(),
            'Die Vertragsliste liefert mehr als eine Seite - dann waechst der Seitenaufbau mit dem Bestand.');
    }
}
