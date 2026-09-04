<?php

namespace App\Http\Controllers;

use App\Support\WebsiteHosts;
use Illuminate\Http\Request;

/**
 * Ersetzt die Route-Closure auf '/'. Closures in Routen verhindern
 * 'php artisan route:cache' in Produktion. (Audit M8)
 *
 * Seit dem Website-Merge (30.07.2026) zeigt '/' auf den Website-Hosts
 * (www.dienstly24.de) die Marketing-Startseite; auf portal./admin. bleibt
 * das bisherige Login-/Dashboard-Verhalten.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request)
    {
        if (WebsiteHosts::isWebsiteRequest($request)) {
            return app(WebsiteController::class)->home();
        }

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
