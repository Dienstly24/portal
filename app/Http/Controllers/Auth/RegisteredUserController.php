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
        ]);

        $user = DB::transaction(function () use ($request) {
            $user = User::create([
                'name' => trim($request->first_name . ' ' . $request->last_name),
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role' => 'customer',
                'portal_password_set_at' => now(),
            ]);

            Customer::create([
                'user_id' => $user->id,
                'customer_number' => app(CustomerNumberGenerator::class)->generate(),
                'source' => 'website',
                'birth_date' => $request->birth_date ?: null,
                'preferred_lang' => in_array(app()->getLocale(), ['de', 'ar'], true) ? app()->getLocale() : 'de',
            ]);

            return $user;
        });

        event(new Registered($user));
        Auth::login($user);

        return redirect(route('portal.dashboard', absolute: false));
    }
}
