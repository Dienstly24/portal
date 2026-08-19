<?php
namespace App\Http\Controllers;
use App\Models\SystemSetting;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function index() {
        $settings = [
            'company_name' => SystemSetting::get('company_name', 'Dienstly24'),
            'company_email' => SystemSetting::get('company_email', 'info@dienstly24.de'),
            'company_phone' => SystemSetting::get('company_phone', ''),
            'company_address' => SystemSetting::get('company_address', ''),
            'portal_url' => SystemSetting::get('portal_url', 'https://portal.dienstly24.de'),
            'admin_url' => SystemSetting::get('admin_url', 'https://admin.dienstly24.de'),
            'contract_reminder_days' => SystemSetting::get('contract_reminder_days', '30,14,7'),
            'welcome_email_enabled' => SystemSetting::get('welcome_email_enabled', '1'),
            // Automatische Freigabe von Kundenaenderungen mit geprueftem Nachweis
            'change_request_auto_approve' => app(\App\Services\ChangeRequest\ChangeProofPolicy::class)->autoApproveMode(),
            'lexoffice_api_key' => SystemSetting::get('lexoffice_api_key', config('services.lexoffice.key', '')),
            // Rechtliches (öffentliche Portal-Seiten /impressum, /agb, …)
            'legal_external_base' => SystemSetting::get('legal_external_base', \App\Http\Controllers\LegalPageController::DEFAULT_EXTERNAL_BASE),
            'legal_external_suffix' => SystemSetting::get('legal_external_suffix', \App\Http\Controllers\LegalPageController::DEFAULT_EXTERNAL_SUFFIX),
            'legal_impressum' => SystemSetting::get('legal_impressum', ''),
            'legal_agb' => SystemSetting::get('legal_agb', ''),
            'legal_datenschutz' => SystemSetting::get('legal_datenschutz', ''),
            'legal_cookies' => SystemSetting::get('legal_cookies', ''),
            // Sicherheit: Zwei-Faktor-Pflicht fuer Personal. Voreinstellung
            // AN - eine Schutzschicht, die man erst einschalten muss, ist
            // in der Praxis meistens aus.
            'two_factor_required' => SystemSetting::get('two_factor_required', '1'),
        ];

        // KI-Kundenassistent (Spezifikation Abschnitt 30): Betriebsschalter
        // liegen als SystemSetting, damit der Betreiber ohne Deploy
        // eingreifen kann - inklusive Notbremse.
        $assistant = app(\App\Services\Ai\Assistant\AssistantSettings::class);
        $settings = array_merge($settings, $assistant->all());

        $assistantProvider = app(\App\Services\Ai\Assistant\Contracts\AssistantProviderInterface::class);

        return view('admin.settings', [
            'settings' => $settings,
            // Ist der Anbieter ueberhaupt einsatzbereit (API-Key gesetzt)?
            // Ehrliche Anzeige: der Schalter allein macht keinen Assistenten.
            'assistantProviderReady' => $assistantProvider->isEnabled(),
            // Der Name des benoetigten Schluessels haengt vom gewaehlten
            // Anbieter ab - sonst schickt die Warnung den Betreiber zum
            // falschen Eintrag in der .env.
            'assistantKeyName' => $assistantProvider->name() === 'openai'
                ? 'OPENAI_API_KEY'
                : 'ANTHROPIC_API_KEY',
        ]);
    }

    public function update(Request $request) {
        $fields = [
            'company_name','company_email','company_phone','company_address',
            'portal_url','admin_url','contract_reminder_days',
            'welcome_email_enabled','lexoffice_api_key','change_request_auto_approve',
            'legal_external_base','legal_external_suffix',
            'legal_impressum','legal_agb','legal_datenschutz','legal_cookies'
        ];
        foreach ($fields as $field) {
            if ($request->has($field)) {
                SystemSetting::set($field, $request->input($field));
            }
        }

        // Zwei-Faktor-Pflicht: eigener Marker, damit ein anderes Formular
        // die Sicherheitsschicht nicht versehentlich abschaltet. Ohne
        // Marker bleibt der bisherige Wert stehen.
        if ($request->has('security_form')) {
            SystemSetting::set('two_factor_required', $request->boolean('two_factor_required') ? '1' : '0');
        }

        // KI-Kundenassistent: Schalter kommen als Checkboxen, ein nicht
        // angehakter Kasten sendet NICHTS. Deshalb werden sie immer alle
        // geschrieben (0 oder 1) - sonst liesse sich ein Schalter nie
        // ausschalten. Nur bei einem abgeschickten Assistenten-Formular
        // (Marker-Feld), damit ein anderes Formular sie nicht zuruecksetzt.
        if ($request->has('ai_assistant_form')) {
            foreach (\App\Services\Ai\Assistant\AssistantSettings::DEFAULTS as $key => $default) {
                if ($key === 'ai_assistant_max_replies_per_case') {
                    // Zahl: 0 = unbegrenzt, hart begrenzt gegen Tippfehler.
                    $value = (int) $request->input($key, $default);
                    SystemSetting::set($key, (string) max(0, min(100, $value)));
                    continue;
                }
                SystemSetting::set($key, $request->boolean($key) ? '1' : '0');
            }
        }
        // Der API-Key wird nur noch in system_settings gespeichert.
        // Das frühere Schreiben in die .env-Datei erlaubte Env-Injection
        // (Zeilenumbrüche im Eingabefeld) und kollidiert mit config:cache.
        // LexofficeService liest den Key aus system_settings mit
        // Fallback auf config('services.lexoffice.key'). (Audit M6)
        return back()->with('success', 'Einstellungen gespeichert.');
    }
}
