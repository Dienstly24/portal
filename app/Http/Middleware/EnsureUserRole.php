<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            return redirect()->route('login');
        }

        // Deaktivierte Konten sofort abmelden - bisher wurde is_active nur
        // beim Login geprüft, bestehende Sessions blieben gültig. (Audit M2)
        if (isset($user->is_active) && !$user->is_active) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            return redirect()->route('login')->withErrors(['email' => 'Dieses Konto wurde deaktiviert.']);
        }

        if (!in_array($user->role, $roles)) {
            // Falsche Rolle: zum richtigen Bereich umleiten
            if (in_array($user->role, ['admin', 'manager', 'support', 'employee'])) {
                return redirect()->route('admin.dashboard');
            }
            return redirect()->route('portal.dashboard');
        }

        return $next($request);
    }
}
