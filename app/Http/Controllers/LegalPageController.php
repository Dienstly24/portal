<?php

namespace App\Http\Controllers;

use App\Models\SystemSetting;

/**
 * Öffentliche Rechts-/Infoseiten des Portals: Impressum, AGB,
 * Datenschutzerklärung, Cookie-Richtlinie, Kontakt.
 *
 * Warum im Portal statt auf der Website: Die Footer-Links (Login,
 * Registrierung, E-Mails) müssen IMMER funktionieren – unabhängig davon,
 * ob die WordPress-Seite die Unterseiten pflegt. Inhalte kommen aus den
 * Systemeinstellungen (Admin -> Einstellungen -> Rechtliches) und fallen
 * auf sinnvolle, faktenbasierte Standardtexte zurück.
 */
class LegalPageController extends Controller
{
    public const PAGES = [
        'impressum' => 'Impressum',
        'agb' => 'Allgemeine Geschäftsbedingungen (AGB)',
        'datenschutz' => 'Datenschutzerklärung',
        'cookie-richtlinie' => 'Cookie-Richtlinie',
        'kontakt' => 'Kontakt',
    ];

    public function show(string $page)
    {
        abort_unless(array_key_exists($page, self::PAGES), 404);

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
