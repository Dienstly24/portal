<?php

namespace Tests\Feature;

use App\Policies\CustomerChangeRequestPolicy;
use App\Policies\InternalConversationPolicy;
use App\Policies\InternalMessagePolicy;
use App\Policies\SignatureRequestPolicy;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Waechter fuer die Zugriffspruefung je Datensatz (Audit 15.09.2026, P3-19).
 *
 * DER BEFUND WAR "4 Policies bei 98 Modellen". Die Zahl fuer sich sagt
 * nichts: eine Policy ist EINE von mehreren Bauformen, und dieses Projekt
 * prueft ueberwiegend anders - Rolle an der Route, Recht an der Route
 * (`can:provisionen-verwalten`), Portfolio-Scope im Controller
 * (`ScopesCustomerAccess`, `canAccessCustomer`) und je Bereich ein eigener
 * Helfer (`authorizeTicketAccess`, `authorizedCustomer`, `findAccessible`).
 * Die vier Policies decken genau die Objekte ab, deren Eigentuemer sich
 * NICHT aus dem Kunden ergibt: Signaturanfrage ohne Kundenakte, interne
 * Unterhaltung, interne Nachricht, Aenderungsantrag.
 *
 * 98 Policies anzulegen waere deshalb kein Sicherheitsgewinn, sondern eine
 * ZWEITE Wahrheit neben den vorhandenen Pruefungen - und zwei Quellen fuer
 * dieselbe Frage laufen auseinander. Was wirklich fehlte, war der Nachweis,
 * dass keine Route durchs Raster faellt. Der steht jetzt hier.
 *
 * Geprueft wird jede Route, die (a) fuer die BREITE Personalrolle offen ist
 * (also auch fuer 'employee', der nur sein Portfolio sehen darf) und (b)
 * einen Datensatz ueber einen Pfadparameter annimmt. Sie muss eine
 * erkennbare Pruefung haben - sonst waere die ID aus der Adresszeile der
 * einzige Schutz, und genau das endet als IDOR.
 */
class ZugriffspruefungTest extends TestCase
{
    /**
     * Parameter, hinter denen KEIN Datensatz mit Eigentuemer steht
     * (Sprache, Seitenzahl, Plattformname, Einmal-Token ...).
     */
    private const HARMLOSE_PARAMETER = ['token', 'slug', 'locale', 'which', 'platform', 'code', 'hash', 'index', 'page'];

    /**
     * Erkennbare Pruefungen. Bewusst BREIT gefasst: der Test soll das
     * Fehlen JEDER Pruefung finden, nicht eine bestimmte Bauform
     * erzwingen. Die Namen stammen aus dem Bestand - wer eine neue
     * Bauform einfuehrt, traegt sie hier nach UND begruendet sie.
     */
    private const PRUEFUNGEN = 'authorize[A-Z]\w*\(|canAccessCustomer|authorizedCustomer|findAccessible'
        .'|scopeCustomers|visibleTo\(|->authorize\(|Gate::|abort_unless|abort_if|abort\(40'
        .'|policy\(|where\(\s*[\'"]user_id[\'"],\s*auth\(\)->id\(\)'
        .'|inbox->scope\(|ownCampaign\(|visibleCustomerIds\(';

    /**
     * Routen, die BEWUSST keinen Eigentuemer-Vergleich machen, weil der
     * Datensatz KEINEN Eigentuemer hat. Sie stehen namentlich hier, damit
     * die Ausnahme eine Entscheidung bleibt und nicht ein Loch im Muster:
     *
     *  - Medienverwaltung: die Bild-Slots gehoeren der WEBSITE, nicht einem
     *    Kunden. Hochladen und Zuweisen duerfen laut Betreiber-Vorgabe alle
     *    Personalrollen; das LOESCHEN ist ohnehin auf admin/manager begrenzt
     *    (eigene role:-Schranke an der Route).
     *  - Ankuendigung des Tarifrechners: ein globaler Hinweistext.
     */
    private const OHNE_EIGENTUEMER = [
        'MediaLibraryController@update',
        'MediaLibraryController@replace',
        'TarifrechnerController@destroyAnnouncement',
    ];

    private int $geprueft = 0;

    public function test_keine_personalroute_nimmt_eine_fremde_id_ungeprueft_an(): void
    {
        $verdaechtig = [];

        foreach (Route::getRoutes() as $route) {
            $middleware = array_values(array_filter(
                $route->gatherMiddleware(),
                fn ($m) => is_string($m)
            ));

            // Nur die BREITE Personalrolle ist interessant: wo ohnehin nur
            // admin/manager hinkommen, IST die Rolle die Pruefung.
            $breit = false;
            $eng = false;
            foreach ($middleware as $m) {
                if (str_starts_with($m, 'role:')) {
                    str_contains($m, 'employee') ? $breit = true : $eng = true;
                }
                // Ein Recht an der Route (can:) ist ebenfalls eine Schranke.
                if (str_starts_with($m, 'can:')) {
                    $eng = true;
                }
            }
            if (! $breit || $eng) {
                continue;
            }

            if (! $this->nimmtDatensatzAn($route->uri())) {
                continue;
            }

            $action = $route->getActionName();
            if (! str_contains($action, '@')) {
                continue;
            }
            [$klasse, $methode] = explode('@', $action);
            $quelle = class_exists($klasse) ? $this->methodenQuelle($klasse, $methode) : null;
            if ($quelle === null) {
                continue;
            }

            $this->geprueft++;

            if (in_array(class_basename($klasse).'@'.$methode, self::OHNE_EIGENTUEMER, true)) {
                continue;
            }

            if (! preg_match('/'.self::PRUEFUNGEN.'/', $quelle)) {
                $verdaechtig[] = $route->methods()[0].' /'.$route->uri().'  ->  '
                    .class_basename($klasse).'@'.$methode;
            }
        }

        // Ein Waechter, der nichts erreicht, bestaetigt nur sich selbst.
        $this->assertGreaterThan(
            50,
            $this->geprueft,
            'Der Waechter hat kaum eine Route erreicht - dann prueft er nichts.'
        );

        $this->assertSame([], $verdaechtig, "Routen ohne erkennbare Zugriffspruefung:\n"
            .implode("\n", $verdaechtig));
    }

    /**
     * Die vier Policies sind kein Zufallsbestand: sie gehoeren zu den
     * Objekten, deren Eigentuemer sich nicht aus dem Kunden ergibt.
     * Verschwindet eine, faellt genau dort die Pruefung weg.
     */
    public function test_die_vier_policies_sind_vorhanden(): void
    {
        foreach ([
            CustomerChangeRequestPolicy::class,
            InternalConversationPolicy::class,
            InternalMessagePolicy::class,
            SignatureRequestPolicy::class,
        ] as $policy) {
            $this->assertTrue(class_exists($policy), $policy.' fehlt.');
        }
    }

    private function nimmtDatensatzAn(string $uri): bool
    {
        preg_match_all('/\{(\w+)/', $uri, $treffer);

        foreach ($treffer[1] as $parameter) {
            if (! in_array($parameter, self::HARMLOSE_PARAMETER, true)) {
                return true;
            }
        }

        return false;
    }

    /** Quelltext einer Controller-Methode. */
    private function methodenQuelle(string $klasse, string $methode): ?string
    {
        try {
            $r = new \ReflectionMethod($klasse, $methode);
        } catch (\Throwable $e) {
            return null;
        }

        $datei = $r->getFileName();
        if (! $datei || ! is_readable($datei)) {
            return null;
        }

        $zeilen = file($datei);
        $von = max(0, $r->getStartLine() - 1);

        return implode('', array_slice($zeilen, $von, $r->getEndLine() - $von));
    }
}
