<?php

namespace App\Http\Requests\Admin;

use App\Http\Controllers\LegalPageController;
use App\Services\ChangeRequest\ChangeProofPolicy;
use App\Services\Ai\Assistant\AssistantSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validierung der Systemeinstellungen (Audit SEC-5).
 *
 * Vorher schrieb SettingsController::update() jeden Wert einer festen
 * Feldliste UNGEPRUEFT in system_settings. Eine Einstellung ist aber kein
 * Freitext-Zettel: sie steuert Verhalten. Der gefaehrlichste Fall war
 * `legal_external_base` - der Wert landet in LegalPageController::show()
 * in `redirect()->away()`, also in einem Location-Header, der jeden
 * Besucher der oeffentlichen Rechtsseiten weiterschickt. Ohne Pruefung
 * genuegte dort ein `javascript:`-, `data:`- oder fremder http-Wert, um
 * aus /impressum eine Weiterleitung auf beliebige Ziele zu machen (Open
 * Redirect, im Fall von javascript: sogar Skriptausfuehrung in aelteren
 * Browsern).
 *
 * Grundsatz: JEDE Einstellung hat einen Typ, eine Laengenobergrenze und -
 * wo es eine gibt - eine Wertemenge. Was hier nicht steht, wird auch nicht
 * gespeichert (der Controller laeuft ueber die validierten Daten, nicht
 * mehr ueber $request->input()).
 */
class UpdateSettingsRequest extends FormRequest
{
    /**
     * Erlaubte Hosts fuer die Rechtsseiten-Quelle.
     *
     * Die Rechtsseiten gehoeren zum eigenen Auftritt - ein anderer Host
     * als eine eigene Domain ist hier fachlich nie richtig. Die Allowlist
     * ist damit keine Einschraenkung des Betriebs, sondern die Absicherung
     * genau des einen Feldes, das in einen Redirect fliesst.
     *
     * Die Liste wird NICHT zweitgepflegt, sondern aus der bestehenden
     * Host-Konfiguration abgeleitet (config/website.php: kanonischer Host,
     * Redirect-Hosts, Extra-Hosts). Eine zweite Liste waere die Sorte
     * Konfiguration, die beim naechsten Domainwechsel vergessen wird -
     * und dann zeigen die Rechtsseiten ins Leere.
     *
     * @return array<int,string>
     */
    public static function allowedLegalHosts(): array
    {
        $hosts = array_merge(
            [(string) config('website.canonical_host', 'www.dienstly24.de')],
            (array) config('website.redirect_hosts', []),
            (array) config('website.extra_hosts', []),
            // Der Portal-Host selbst: die Quelle darf auch auf das
            // eigene Portal zeigen.
            ['portal.dienstly24.de'],
        );

        $hosts = array_map(
            fn ($h) => strtolower(trim((string) $h)),
            $hosts
        );

        return array_values(array_unique(array_filter(
            $hosts,
            fn (string $h): bool => $h !== ''
        )));
    }

    /** Obergrenze fuer Freitextfelder (Rechtstexte duerfen lang sein). */
    public const MAX_LEGAL_TEXT = 200000;

    public function authorize(): bool
    {
        // Die Route traegt bereits role:admin. Die zweite Pruefung hier
        // ist Absicht: eine Autorisierung, die nur an der Route haengt,
        // faellt weg, sobald die Route einmal umgehaengt wird.
        $user = $this->user();

        return $user !== null && $user->role === 'admin';
    }

    public function rules(): array
    {
        $rules = [
            'company_name' => ['sometimes', 'string', 'max:200'],
            'company_email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
            'company_phone' => ['sometimes', 'nullable', 'string', 'max:60'],
            'company_address' => ['sometimes', 'nullable', 'string', 'max:1000'],

            // Portal-/Admin-Adresse landen in Mails und Links: nur https,
            // kein javascript:/data:, keine fremden Hosts.
            'portal_url' => ['sometimes', 'nullable', 'string', 'max:255', 'url:https'],
            'admin_url' => ['sometimes', 'nullable', 'string', 'max:255', 'url:https'],

            // "30,14,7" - Tage vor Ablauf. Nur Zahlen und Kommata, damit
            // kein Freitext in die Erinnerungsberechnung geraet.
            'contract_reminder_days' => ['sometimes', 'nullable', 'string', 'max:100',
                'regex:/^\s*\d{1,3}(\s*,\s*\d{1,3})*\s*$/'],

            'welcome_email_enabled' => ['sometimes', Rule::in(['0', '1'])],

            'change_request_auto_approve' => ['sometimes',
                Rule::in(array_keys(ChangeProofPolicy::AUTO_APPROVE_MODES))],

            // Kein Zeilenumbruch: der Wert wanderte frueher in die .env,
            // heute nur noch in system_settings - eine einzeilige Angabe
            // bleibt trotzdem die richtige Erwartung.
            'lexoffice_api_key' => ['sometimes', 'nullable', 'string', 'max:255',
                'regex:/^[^\r\n]*$/'],

            // Siehe Klassenkommentar: dieses Feld fliesst in redirect()->away().
            'legal_external_base' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Nur eine Dateiendung, kein Pfad und keine Query - sonst
            // liesse sich ueber das Suffix am Host-Check vorbei ein
            // fremdes Ziel anhaengen ("?next=..." / "/../..").
            'legal_external_suffix' => ['sometimes', 'nullable', 'string', 'max:20',
                'regex:/^(\.[A-Za-z0-9]{1,10})?$/'],

            'legal_impressum' => ['sometimes', 'nullable', 'string', 'max:' . self::MAX_LEGAL_TEXT],
            'legal_agb' => ['sometimes', 'nullable', 'string', 'max:' . self::MAX_LEGAL_TEXT],
            'legal_datenschutz' => ['sometimes', 'nullable', 'string', 'max:' . self::MAX_LEGAL_TEXT],
            'legal_cookies' => ['sometimes', 'nullable', 'string', 'max:' . self::MAX_LEGAL_TEXT],

            // Marker-Felder der Teilformulare (siehe Controller).
            'security_form' => ['sometimes'],
            'ai_assistant_form' => ['sometimes'],
            'two_factor_required' => ['sometimes'],
        ];

        // KI-Assistent: Schalter sind Checkboxen (an = "1"/"on", aus =
        // gar nicht gesendet), die beiden Zahlen haben feste Grenzen.
        foreach (array_keys(AssistantSettings::DEFAULTS) as $key) {
            $rules[$key] = match ($key) {
                'ai_assistant_max_replies_per_case' => ['sometimes', 'integer', 'between:0,100'],
                'ai_assistant_resume_quiet_hours' => ['sometimes', 'integer', 'between:1,720'],
                default => ['sometimes'],
            };
        }

        return $rules;
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (! $this->has('legal_external_base')) {
                return;
            }

            $base = trim((string) $this->input('legal_external_base'));

            // Leer ist ein gueltiger, dokumentierter Zustand: dann rendert
            // das Portal seine eigenen Rechtsseiten statt weiterzuleiten.
            if ($base === '') {
                return;
            }

            $error = self::legalBaseError($base);
            if ($error !== null) {
                $validator->errors()->add('legal_external_base', $error);
            }
        });
    }

    /**
     * Prueft eine Rechtsseiten-Quelle und liefert die Fehlermeldung oder
     * null. Bewusst statisch und oeffentlich: LegalPageController benutzt
     * dieselbe Regel beim LESEN, damit ein Altbestand aus der Zeit vor
     * dieser Validierung nicht doch noch in einen Redirect geraet.
     */
    public static function legalBaseError(string $base): ?string
    {
        $base = trim($base);

        // Steuerzeichen zuerst: ein "\r\n" im Wert waere im Location-Header
        // eine Response-Splitting-Luecke.
        if ($base === '' || preg_match('/[\x00-\x1F\x7F]/', $base)) {
            return 'Die Rechtsseiten-Quelle enthält unerlaubte Zeichen.';
        }

        if (mb_strlen($base) > 255) {
            return 'Die Rechtsseiten-Quelle ist zu lang (maximal 255 Zeichen).';
        }

        $parts = parse_url($base);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return 'Die Rechtsseiten-Quelle muss eine vollständige https-Adresse sein (z. B. https://dienstly24.de).';
        }

        // Nur https. http waere eine Weiterleitung ins Unverschluesselte,
        // javascript:/data: eine Skriptausfuehrung bzw. ein beliebiger
        // Inhalt unter unserem Link.
        if (strtolower($parts['scheme']) !== 'https') {
            return 'Die Rechtsseiten-Quelle muss mit https:// beginnen.';
        }

        // Zugangsdaten in der URL ("https://evil.com@dienstly24.de") sind
        // der klassische Weg, einen Host-Check zu taeuschen.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'Die Rechtsseiten-Quelle darf keine Zugangsdaten enthalten.';
        }

        if (isset($parts['query']) || isset($parts['fragment'])) {
            return 'Die Rechtsseiten-Quelle darf keine Parameter oder Anker enthalten.';
        }

        $host = strtolower($parts['host']);
        $erlaubt = self::allowedLegalHosts();
        if (! in_array($host, $erlaubt, true)) {
            return 'Die Rechtsseiten-Quelle muss auf eine eigene Domain zeigen ('
                . implode(', ', $erlaubt) . ').';
        }

        // Pfad darf es geben (z. B. /recht), aber ohne Rueckwaertsschritte.
        $path = $parts['path'] ?? '';
        if ($path !== '' && (str_contains($path, '..') || str_contains($path, '\\'))) {
            return 'Die Rechtsseiten-Quelle enthält einen ungültigen Pfad.';
        }

        return null;
    }
}
