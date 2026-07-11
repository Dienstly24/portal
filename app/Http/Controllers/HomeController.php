<?php
namespace App\Http\Controllers;

/**
 * Ersetzt die Route-Closure auf '/'. Closures in Routen verhindern
 * 'php artisan route:cache' in Produktion. (Audit M8)
 */
class HomeController extends Controller
{
    public function __invoke()
    {
        $user = auth()->user();
        if ($user && in_array($user->role, ['admin', 'manager', 'employee'])) {
            return redirect()->route('admin.dashboard');
        }
        if ($user && $user->role === 'partner') {
            return redirect()->route('partner.dashboard');
        }
        return redirect()->route('portal.dashboard');
    }
}
