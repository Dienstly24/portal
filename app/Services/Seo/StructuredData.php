<?php

namespace App\Services\Seo;

use App\Models\ServicePage;
use App\Support\CspNonce;
use App\Support\WebsiteHosts;
use Illuminate\Support\HtmlString;

/**
 * Strukturierte Daten (JSON-LD) fuer die oeffentlichen Website-Seiten.
 *
 * WARUM DIESE KLASSE UEBERHAUPT EXISTIERT (Audit 15.09.2026):
 * Die Bloecke standen frueher als `json_encode([...])` MITTEN IN DER
 * BLADE-VORLAGE. Blade uebersetzt `@context` aber als eigene DIREKTIVE -
 * der Array-Schluessel `'@context'` wurde deshalb beim Kompilieren durch
 * PHP-QUELLTEXT ersetzt, und im ausgelieferten HTML stand statt
 *
 *     {"@context":"https://schema.org","@type":"InsuranceAgency", ...}
 *
 * woertlich
 *
 *     {"<?php $__contextArgs = []; if (context()->has(...)": "https://schema.org", ...}
 *
 * Folge: Google konnte auf 22 Seiten (44 Bloecke) WEDER LocalBusiness NOCH
 * FAQPage lesen - die Rich Results fielen komplett aus. Und es gab keinen
 * Fehler: kein 500er, keine Konsolenmeldung, die Seite sah normal aus.
 * Genau dieselbe Klasse stiller Blade-Fehler wie `@push` nach `@stack`
 * (Betreiber-Meldung 06.09.2026).
 *
 * Die Loesung ist deshalb NICHT ein Escape-Trick in der Vorlage, sondern
 * der Umzug: In einer .php-Datei laeuft kein Blade-Compiler, `'@context'`
 * ist dort wieder ein gewoehnlicher String. Vorlagen rufen nur noch
 * `script()` auf und enthalten kein `@`-Literal mehr.
 *
 * Zwei Waechter-Tests halten das fest (StructuredDataTest): der eine
 * prueft im AUSGELIEFERTEN HTML, dass kein `<?php` vorkommt, der andere,
 * dass jeder JSON-LD-Block gueltiges JSON mit echtem `@context` ist.
 */
class StructuredData
{
    /** Anschrift des Betriebs - eine Quelle fuer alle Schemata. */
    private const ADRESSE = [
        '@type' => 'PostalAddress',
        'streetAddress' => 'Furtweg 51a',
        'postalCode' => '22523',
        'addressLocality' => 'Hamburg',
        'addressCountry' => 'DE',
    ];

    private const TELEFON = '+49-179-9673909';

    /** Nur Deutschland - dieselbe Aussage in allen Schemata. */
    private const GEBIET = ['@type' => 'Country', 'name' => 'Deutschland'];

    /**
     * Der Betrieb selbst (Startseite).
     *
     * @return array<string, mixed>
     */
    public static function insuranceAgency(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'InsuranceAgency',
            'name' => 'Dienstly24',
            'url' => WebsiteHosts::url('/'),
            'image' => WebsiteHosts::url('/images/og-image.jpg'),
            'description' => 'Anbieterunabhängige Beratung zu Versicherungen, '
                .'Kfz-Zulassung und Energie – auf Deutsch und Arabisch.',
            'telephone' => self::TELEFON,
            'email' => config('website.email'),
            'priceRange' => '€€',
            'address' => self::ADRESSE,
            'areaServed' => self::GEBIET,
            'availableLanguage' => ['de', 'ar'],
            'openingHoursSpecification' => [[
                '@type' => 'OpeningHoursSpecification',
                'dayOfWeek' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
                'opens' => '09:00',
                'closes' => '18:00',
            ]],
            'sameAs' => array_filter([config('website.facebook')]),
            'hasOfferCatalog' => [
                '@type' => 'OfferCatalog',
                'name' => 'Leistungen',
                'itemListElement' => array_map(
                    fn (string $s) => ['@type' => 'Offer', 'itemOffered' => ['@type' => 'Service', 'name' => $s]],
                    [
                        'Kfz-Versicherung', 'Krankenversicherung', 'Zahnzusatzversicherung',
                        'Kfz-Zulassungsservice', 'Kennzeichen per Post', 'Strom- und Gasberatung',
                    ]
                ),
            ],
        ];
    }

    /**
     * Eine Leistungsseite als Service-Schema.
     *
     * @return array<string, mixed>
     */
    public static function service(ServicePage $page): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'Service',
            'name' => $page->t('title'),
            'serviceType' => $page->t('title'),
            'description' => $page->t('meta_description') ?: $page->t('subtitle'),
            'areaServed' => self::GEBIET,
            'provider' => [
                '@type' => 'InsuranceAgency',
                'name' => 'Dienstly24',
                'url' => WebsiteHosts::url('/'),
                'telephone' => self::TELEFON,
                'address' => self::ADRESSE,
            ],
        ];
    }

    /**
     * Haeufige Fragen. `$paare` ist eine Liste aus [Frage, Antwort].
     * Leere Eintraege fallen weg - ein FAQPage ohne Frage ist ungueltig.
     *
     * @param  array<int, array{0: ?string, 1: ?string}>  $paare
     * @return array<string, mixed>|null
     */
    public static function faqPage(array $paare): ?array
    {
        $fragen = [];
        foreach ($paare as $paar) {
            $frage = trim((string) ($paar[0] ?? ''));
            $antwort = trim((string) ($paar[1] ?? ''));
            if ($frage === '' || $antwort === '') {
                continue;
            }
            $fragen[] = [
                '@type' => 'Question',
                'name' => $frage,
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $antwort],
            ];
        }

        if ($fragen === []) {
            return null;
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => $fragen,
        ];
    }

    /**
     * Fertiges `<script type="application/ld+json">` samt CSP-Nonce.
     *
     * Null ergibt eine LEERE Ausgabe - so kann die Vorlage ein optionales
     * Schema ohne `@if` einsetzen.
     *
     * Die JSON-Flags sind dieselben wie zuvor: HEX_TAG/HEX_AMP/HEX_APOS/
     * HEX_QUOT schliessen aus, dass ein Inhalt aus der Datenbank das
     * `<script>`-Element verlassen kann.
     *
     * @param  array<string, mixed>|null  $daten
     */
    public static function script(?array $daten): HtmlString
    {
        if ($daten === null || $daten === []) {
            return new HtmlString('');
        }

        $json = json_encode(
            $daten,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        if ($json === false) {
            return new HtmlString('');
        }

        $nonce = CspNonce::get();

        return new HtmlString(
            '<script type="application/ld+json" nonce="'.e($nonce).'">'.$json.'</script>'
        );
    }
}
