<?php

namespace App\Http\Controllers;

use App\Http\Requests\Admin\UpdateSettingsRequest;
use App\Models\SystemSetting;
use App\Support\WebsiteHosts;

/**
 * Öffentliche Rechts-/Infoseiten: Impressum, AGB, Datenschutzerklärung,
 * Cookie-Richtlinie, Kontakt.
 *
 * Eine Inhaltsquelle, keine zwei Versionen: Standardmäßig leiten die
 * Portal-Routen auf die entsprechenden Seiten der offiziellen Website
 * weiter (Einstellungen -> Rechtliches -> "Rechtsseiten-Quelle"). Wird
 * die Quelle dort geleert, rendert das Portal eigene Seiten aus den
 * Systemeinstellungen – als Fallback, falls die Website-Seiten einmal
 * nicht gepflegt sind. Interne Links (Login, E-Mails) zeigen immer auf
 * die Portal-Routen und funktionieren daher in beiden Modi.
 */
class LegalPageController extends Controller
{
    public const PAGES = [
        'impressum' => 'Impressum',
        'agb' => 'Allgemeine Geschäftsbedingungen (AGB)',
        'datenschutz' => 'Datenschutzerklärung',
        'cookie-richtlinie' => 'Cookie-Richtlinie',
        'kontakt' => 'Kontakt',
        'widerruf' => 'Widerrufsbelehrung',
        'erstinformation' => 'Erstinformation',
        'bildnachweise' => 'Bildnachweise',
    ];

    public const DEFAULT_EXTERNAL_BASE = 'https://dienstly24.de';

    /**
     * Die offizielle Website liefert die Rechtsseiten als statische Dateien
     * mit .html-Endung aus (z. B. /impressum.html). Ohne Endung antwortet
     * der Webserver mit 404. Deshalb haengen wir standardmaessig ".html" an.
     * Sobald die Website "schoene" URLs (ohne Endung) unterstuetzt, kann das
     * Suffix unter Einstellungen -> Rechtliches geleert werden.
     */
    public const DEFAULT_EXTERNAL_SUFFIX = '.html';

    public function show(string $page)
    {
        abort_unless(array_key_exists($page, self::PAGES), 404);

        // Website-Hosts (www.dienstly24.de) rendern die Website-Rechtsseiten
        // IMMER lokal - nie extern umleiten, sonst entsteht mit einer auf
        // die eigene Domain zeigenden "Rechtsseiten-Quelle" eine
        // Redirect-Schleife (seit dem Merge IST diese App die Website).
        if (WebsiteHosts::isWebsiteRequest(request())) {
            if ($page === 'kontakt') {
                return redirect('/#kontakt');
            }
            return app(WebsiteController::class)->legal($page);
        }

        // Die neuen Website-Seiten ohne Portal-Fallback (Widerruf,
        // Erstinformation, Bildnachweise) liegen nur als Website-Views vor.
        if (in_array($page, ['widerruf', 'erstinformation', 'bildnachweise'], true)) {
            return app(WebsiteController::class)->legal($page);
        }

        $base = rtrim((string) SystemSetting::get('legal_external_base', self::DEFAULT_EXTERNAL_BASE), '/');
        // Zweite Pruefung beim LESEN (Audit SEC-5): der Wert fliesst in
        // einen Location-Header und schickt damit jeden Besucher weiter.
        // Die Validierung im Formular allein genuegt dafuer nicht - in
        // system_settings kann ein Wert auch aus der Zeit VOR der
        // Validierung stehen (oder per CLI/Seeder gesetzt worden sein).
        // Ist er nicht sauber, wird NICHT weitergeleitet, sondern die
        // eigene Portalseite gerendert: der Betrieb laeuft weiter, nur
        // eben ohne unkontrolliertes Ziel.
        if ($base !== '' && UpdateSettingsRequest::legalBaseError($base) === null) {
            $suffix = (string) SystemSetting::get('legal_external_suffix', self::DEFAULT_EXTERNAL_SUFFIX);
            // Suffix ebenfalls eng gefasst: nur eine Dateiendung, sonst
            // liesse sich am Host-Check vorbei ein fremdes Ziel anhaengen.
            if (! preg_match('/^(\.[A-Za-z0-9]{1,10})?$/', $suffix)) {
                $suffix = '';
            }

            return redirect()->away($base.'/'.$page.$suffix);
        }

        return view('legal.page', [
            'page' => $page,
            'title' => self::PAGES[$page],
            'company' => [
                'name' => SystemSetting::get('company_name', 'Dienstly24'),
                'email' => SystemSetting::get('company_email', 'info@dienstly24.de'),
                'phone' => SystemSetting::get('company_phone', ''),
                'address' => SystemSetting::get('company_address', ''),
            ],
            // Vom Admin gepflegte Texte (Einstellungen -> Rechtliches).
            'custom' => [
                'impressum' => SystemSetting::get('legal_impressum', ''),
                'agb' => SystemSetting::get('legal_agb', ''),
                'datenschutz' => SystemSetting::get('legal_datenschutz', ''),
                'cookie-richtlinie' => SystemSetting::get('legal_cookies', ''),
            ][$page] ?? '',
            'pages' => self::PAGES,
        ]);
    }
}
