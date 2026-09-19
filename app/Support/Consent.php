<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Die EINE Quelle fuer die Einwilligung des Besuchers.
 *
 * WARUM SIE ENTSTANDEN IST (Betreiber-Auftrag 19.09.2026): es gab bereits
 * einen Banner - aber nur im PORTAL, mit zwei Knoepfen, und NIEMAND las
 * sein Ergebnis aus. Die Marketing-Website trug ihn gar nicht. Sobald
 * Matomo eingerichtet ist, waere damit eine Messung gelaufen, ueber die
 * der Besucher nie entschieden hat - auf genau den Seiten, die am
 * meisten besucht werden.
 *
 * DREI ENTSCHEIDUNGEN, die hier fest verdrahtet sind:
 *
 * 1. NUR KATEGORIEN, DIE ES WIRKLICH GIBT. Die Anwendung laedt keinen
 *    einzigen Fremddienst (geprueft: die Website-Vorlagen enthalten
 *    ausser AUSGEHENDEN LINKS keine fremde Adresse). Es gibt deshalb
 *    "notwendig" und "statistik" - keine Kategorie "Marketing", keine
 *    "Externe Medien". Eine Auswahl ueber Dienste, die nicht existieren,
 *    ist eine Behauptung ueber den Betrieb.
 *
 * 2. DIE WAHL GILT AUF BEIDEN HOSTS. www.dienstly24.de und
 *    portal.dienstly24.de sind verschiedene Hosts, aber dieselbe
 *    Domain - das Cookie wird deshalb auf der gemeinsamen Domain
 *    gesetzt (".dienstly24.de"), und der Besucher wird nicht zweimal
 *    gefragt. Das ist der saubere Weg; ein Weiterreichen ueber
 *    URL-Parameter oder ein fremder Dienst waere beides schlechter.
 *    Auf jedem anderen Host (lokal, Staging) bleibt es host-eigen -
 *    ein Cookie auf ".localhost" waere ungueltig.
 *
 * 3. DER ALTBESTAND WIRD GEERBT, NICHT VERWORFEN. Der alte Banner
 *    schrieb `cookie_consent=all|essential`, und sein Text nannte
 *    "Statistik" ausdruecklich als das, was "Alle akzeptieren"
 *    einschliesst. Diese Wahl gilt deshalb weiter (all -> Statistik
 *    erlaubt, essential -> abgelehnt). Alle erneut zu fragen waere
 *    nicht sicherer, nur laestiger.
 */
class Consent
{
    /** Immer gesetzt, nie abwaehlbar - Sitzung, CSRF, Sprache, Einwilligung selbst. */
    public const NOTWENDIG = 'notwendig';

    /** Reichweitenmessung mit dem eigenen Matomo. */
    public const STATISTIK = 'statistik';

    /** Die WAEHLBAREN Kategorien. "notwendig" steht bewusst nicht darin. */
    public const OPTIONAL = [self::STATISTIK];

    /** Name des Einwilligungs-Cookies (neue Fassung mit Kategorien). */
    public const COOKIE = 'dienstly24_consent';

    /** Name des ALTEN Cookies aus der Zwei-Knopf-Fassung. */
    public const COOKIE_ALT = 'cookie_consent';

    /** Ein Jahr - danach wird erneut gefragt (uebliche Praxis). */
    public const TAGE = 365;

    /**
     * Fassung des Wertes. Steigt sie, gilt eine alte Wahl nicht mehr -
     * das ist der Schalter fuer den Fall, dass eine NEUE Kategorie
     * dazukommt: ueber sie hat niemand entschieden.
     */
    public const VERSION = 'v1';

    /**
     * Die erteilten Kategorien dieses Besuchers - oder null, wenn er
     * noch nicht entschieden hat (dann zeigt die Seite den Banner).
     *
     * @return array<int, string>|null
     */
    public static function erteilt(Request $request): ?array
    {
        $roh = trim((string) $request->cookie(self::COOKIE));

        if ($roh !== '') {
            [$fassung, $liste] = array_pad(explode(':', $roh, 2), 2, '');
            if ($fassung !== self::VERSION) {
                // Andere Fassung: die Wahl bezog sich auf einen anderen
                // Satz Kategorien und wird nicht geraten.
                return null;
            }

            return array_values(array_intersect(
                array_filter(explode(',', $liste)),
                self::OPTIONAL
            ));
        }

        // Altbestand der Zwei-Knopf-Fassung.
        $alt = trim((string) $request->cookie(self::COOKIE_ALT));
        if ($alt === 'all') {
            return [self::STATISTIK];
        }
        if ($alt === 'essential') {
            return [];
        }

        return null;
    }

    /** Hat der Besucher ueberhaupt schon entschieden? */
    public static function entschieden(Request $request): bool
    {
        return self::erteilt($request) !== null;
    }

    /**
     * Ist diese Kategorie erlaubt?
     *
     * "notwendig" ist IMMER erlaubt - sie beschreibt, was die Seite zum
     * Funktionieren braucht, und darueber gibt es nichts abzustimmen.
     */
    public static function erlaubt(Request $request, string $kategorie): bool
    {
        if ($kategorie === self::NOTWENDIG) {
            return true;
        }

        return in_array($kategorie, self::erteilt($request) ?? [], true);
    }

    /**
     * Die Domain, auf der das Cookie gilt - oder null fuer host-eigen.
     *
     * Gemeinsame Domain nur, wenn der aufgerufene Host wirklich darunter
     * liegt. Ein Cookie fuer eine fremde Domain wird vom Browser
     * ohnehin verworfen; hier wuerde es dazu fuehren, dass die Wahl gar
     * nicht gespeichert wird und der Banner bei JEDEM Aufruf erscheint.
     */
    public static function domain(Request $request): ?string
    {
        $basis = self::basisDomain();
        if ($basis === '') {
            return null;
        }

        $host = strtolower($request->getHost());

        if ($host === $basis || str_ends_with($host, '.'.$basis)) {
            return '.'.$basis;
        }

        return null;
    }

    /**
     * Die gemeinsame Domain von Website und Portal, aus dem kanonischen
     * Host abgeleitet ("www.dienstly24.de" -> "dienstly24.de").
     *
     * BEWUSST ABGELEITET und nicht als eigener Wert gepflegt: zwei
     * Angaben fuer dieselbe Sache laufen auseinander, und dann gilt die
     * Einwilligung auf einem der beiden Hosts nicht mehr - ohne dass
     * irgendwo ein Fehler erscheint.
     */
    public static function basisDomain(): string
    {
        $host = strtolower(trim((string) config('website.canonical_host')));
        $host = preg_replace('/^www\./', '', $host) ?? '';

        // Eine Basis ohne Punkt (z. B. "localhost") ist als
        // Cookie-Domain unzulaessig.
        return str_contains($host, '.') ? $host : '';
    }

    /**
     * Braucht die Statistik eine Einwilligung?
     *
     * STANDARD JA - die strengere Auslegung. Matomo laeuft hier ohne
     * Kennungen (`disableCookies`) und mit gekuerzter IP; ob damit
     * Paragraph 25 TTDSG ueberhaupt greift, ist eine RECHTSFRAGE und
     * wird hier nicht entschieden. Der Schalter
     * `ANALYTICS_REQUIRES_CONSENT=false` gehoert dem Betreiber bzw.
     * seinem Datenschutzbeauftragten, nicht dem Code.
     */
    public static function statistikBrauchtEinwilligung(): bool
    {
        return (bool) config('analytics.requires_consent', true);
    }

    /**
     * Gibt es ueberhaupt etwas, worueber der Besucher entscheiden kann?
     *
     * DER TEST HAT ES GEZEIGT (19.09.2026): der Banner beschrieb die
     * Kategorie "Analyse / Statistik" mit "Matomo auf unserem eigenen
     * Server" - auch auf einer Installation, auf der Matomo gar nicht
     * eingerichtet ist. Das ist derselbe Fehler wie eine Kategorie
     * "Marketing", die es nicht gibt: eine Aussage ueber den Betrieb,
     * die nicht stimmt. Und der Besucher klickt eine Abfrage weg, hinter
     * der nichts steht.
     *
     * Solange keine einwilligungspflichtige Technik eingebunden ist,
     * erscheint deshalb KEIN Banner - genau das sagte die
     * Cookie-Richtlinie schon vorher, und es war richtig. Sobald
     * `MATOMO_URL` auf dem Server gesetzt ist, erscheint er von selbst.
     * Es gibt keinen zweiten Schalter, der dabei vergessen werden kann.
     */
    public static function optionaleDiensteVorhanden(): bool
    {
        return Matomo::aktiv() && self::statistikBrauchtEinwilligung();
    }

    /** Darf die Messung auf DIESER Anfrage laufen? */
    public static function statistikErlaubt(Request $request): bool
    {
        if (! self::statistikBrauchtEinwilligung()) {
            return true;
        }

        return self::erlaubt($request, self::STATISTIK);
    }
}
