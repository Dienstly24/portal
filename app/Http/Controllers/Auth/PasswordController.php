<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PasswordController extends Controller
{
    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('updatePassword', [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', \App\Support\PasswordPolicy::for($request->user()), 'confirmed'],
        ]);

        $request->user()->setPassword($validated['password']);

        // Andere offene Sitzungen dieses Kontos beenden - ein
        // Passwortwechsel soll fremde Zugriffe wirklich aussperren, nicht
        // nur den Schluessel umbenennen. Wirkt ueber die
        // AuthenticateSession-Middleware in der Web-Gruppe.
        Auth::logoutOtherDevices($validated['password']);
        \App\Support\SessionPasswordHash::refresh($request);

        return back()->with('status', 'password-updated');
    }
}
