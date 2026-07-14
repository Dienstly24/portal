<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\User;
use App\Services\CustomerNumberGenerator;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

/**
 * Selbst-Registrierung für Neukunden ("Noch kein Konto? Konto erstellen").
 * Legt einen VOLLWERTIGEN Kunden an: role=customer + Kundenakte mit
 * interner Kundennummer (Jahresschema) und source=website – derselbe
 * Datenpfad wie jede andere Kundenanlage, keine Karteileichen.
 * Honeypot-Feld + Route-Throttle schützen vor Bot-Anmeldungen.
 */
class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(Request $request): RedirectResponse
    {
        // Honeypot: von Bots ausgefüllt, von Menschen unsichtbar/leer.
        if ($request->filled('website')) {
            abort(422);
        }

        $request->validate([
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:' . User::class],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'agb' => ['accepted'],
            // Freiwillige, getrennte Einwilligung zur E-Mail-Archivierung
            // (Art. 7 DSGVO) - darf leer bleiben, keine Kopplung an die AGB.
            // 'sometimes': nur pruefen, wenn die Checkbox gesetzt wurde
            // (das 'accepted' ist implizit und wuerde sonst leere Felder ablehnen).
            'email_consent' => ['sometimes', 'accepted'],
        ]);

        $user = DB::transaction(function () use ($request) {
            $user = User::create([
                'name' => trim($request->first_name . ' ' . $request->last_name),
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role' => 'customer',
                'portal_password_set_at' => now(),
            ]);

            $customer = Customer::create([
                'user_id' => $user->id,
                'customer_number' => app(CustomerNumberGenerator::class)->generate(),
                'source' => 'website',
                'birth_date' => $request->birth_date ?: null,
                'preferred_lang' => in_array(app()->getLocale(), ['de', 'ar'], true) ? app()->getLocale() : 'de',
            ]);

            // Optionale E-Mail-Archivierung nur bei aktiv gesetzter Checkbox
            // als nachweisbare Einwilligung erfassen (Quelle: Registrierung).
            if ($request->boolean('email_consent')) {
                \App\Models\CustomerConsent::create([
                    'customer_id' => $customer->id,
                    'type' => \App\Models\CustomerConsent::TYPE_EMAIL_PROCESSING,
                    'granted_at' => now(),
                    'consent_text_version' => \App\Models\CustomerConsent::EMAIL_TEXT_VERSION,
                    'ip_address' => $request->ip(),
                    'user_agent' => substr((string) $request->userAgent(), 0, 512),
                    'source' => 'portal_registration',
                    'import_token' => \App\Models\CustomerConsent::newImportToken(),
                ]);
            }

            return $user;
        });

        event(new Registered($user));
        Auth::login($user);

        return redirect(route('portal.dashboard', absolute: false));
    }
}
