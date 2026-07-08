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

        if (!in_array($user->role, $roles)) {
            // Falsche Rolle: zum richtigen Bereich umleiten
            if (in_array($user->role, ['admin', 'manager', 'employee'])) {
                return redirect()->route('admin.dashboard');
            }
            return redirect()->route('portal.dashboard');
        }

        return $next($request);
    }
}
