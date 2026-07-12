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
            'lexoffice_api_key' => SystemSetting::get('lexoffice_api_key', config('services.lexoffice.key', '')),
            // Rechtliches (öffentliche Portal-Seiten /impressum, /agb, …)
            'legal_external_base' => SystemSetting::get('legal_external_base', \App\Http\Controllers\LegalPageController::DEFAULT_EXTERNAL_BASE),
            'legal_impressum' => SystemSetting::get('legal_impressum', ''),
            'legal_agb' => SystemSetting::get('legal_agb', ''),
            'legal_datenschutz' => SystemSetting::get('legal_datenschutz', ''),
            'legal_cookies' => SystemSetting::get('legal_cookies', ''),
        ];
        return view('admin.settings', compact('settings'));
    }

    public function update(Request $request) {
        $fields = [
            'company_name','company_email','company_phone','company_address',
            'portal_url','admin_url','contract_reminder_days',
            'welcome_email_enabled','lexoffice_api_key',
            'legal_external_base',
            'legal_impressum','legal_agb','legal_datenschutz','legal_cookies'
        ];
        foreach ($fields as $field) {
            if ($request->has($field)) {
                SystemSetting::set($field, $request->input($field));
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
