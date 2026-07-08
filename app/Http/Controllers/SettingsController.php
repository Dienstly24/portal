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
            'lexoffice_api_key' => SystemSetting::get('lexoffice_api_key', env('LEXOFFICE_API_KEY', '')),
        ];
        return view('admin.settings', compact('settings'));
    }

    public function update(Request $request) {
        $fields = [
            'company_name','company_email','company_phone','company_address',
            'portal_url','admin_url','contract_reminder_days',
            'welcome_email_enabled','lexoffice_api_key'
        ];
        foreach ($fields as $field) {
            if ($request->has($field)) {
                SystemSetting::set($field, $request->input($field));
            }
        }
        if ($request->filled('lexoffice_api_key')) {
            $envFile = base_path('.env');
            $content = file_get_contents($envFile);
            $content = preg_replace('/LEXOFFICE_API_KEY=.*/', 'LEXOFFICE_API_KEY=' . $request->lexoffice_api_key, $content);
            file_put_contents($envFile, $content);
        }
        return back()->with('success', 'Einstellungen gespeichert.');
    }
}
