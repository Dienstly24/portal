<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\FacebookBewertung;
use App\Models\SystemSetting;
use App\Services\Social\FacebookBewertungAbruf;
use App\Support\FacebookEmpfehlung;
use Illuminate\Http\Request;

/**
 * /admin/facebook-bewertungen (nur admin/manager): Empfehlungen der
 * eigenen Facebook-Seite pruefen und fuer die Website freigeben.
 *
 * Auf die Website kommt nur, was hier freigegeben ist. Die Sperren aus
 * `FacebookBewertung::freigabeGesperrt()` prueft der SERVER - ein Knopf,
 * der im Browser fehlt, ist kein Schutz.
 */
class FacebookBewertungController extends Controller
{
    public function index(Request $request)
    {
        $status = in_array($request->query('status'), FacebookBewertung::STATUS, true)
            ? $request->query('status') : 'neu';

        return view('admin.facebook_bewertungen', [
            'status' => $status,
            'bewertungen' => FacebookBewertung::with('pruefer:id,name')
                ->where('status', $status)
                ->latest('verfasst_at')->paginate(30)->withQueryString(),
            'zaehler' => FacebookBewertung::whereNull('entfernt_at')
                ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'stand' => FacebookEmpfehlung::stand(),
            'eingerichtet' => FacebookEmpfehlung::abrufEingerichtet(),
            'automatik' => FacebookBewertung::automatikAn(),
        ]);
    }

    public function status(Request $request, int $id)
    {
        $data = $request->validate(['status' => 'required|in:freigegeben,abgelehnt,neu']);
        $bewertung = FacebookBewertung::findOrFail($id);

        if ($data['status'] === 'freigegeben') {
            if ($bewertung->entfernt_at) {
                return back()->withErrors(['status' => 'Diese Empfehlung steht bei Facebook nicht mehr - sie kann nicht freigegeben werden.']);
            }
            if ($grund = $bewertung->freigabeGesperrt()) {
                return back()->withErrors(['status' => 'Freigabe nicht moeglich: '.$grund]);
            }
        }

        $bewertung->update([
            'auto_freigegeben' => false,
            'status' => $data['status'],
            'geprueft_von' => $data['status'] === 'neu' ? null : $request->user()->id,
            'geprueft_at' => $data['status'] === 'neu' ? null : now(),
        ]);
        ActivityLog::record('facebook_bewertung_'.$data['status'], FacebookBewertung::class, $bewertung->id);

        return back()->with('success', match ($data['status']) {
            'freigegeben' => 'Freigegeben - die Empfehlung erscheint auf der Startseite.',
            'abgelehnt' => 'Abgelehnt - die Empfehlung erscheint nicht auf der Website.',
            default => 'Zurueck auf "neu" gesetzt.',
        });
    }

    /** Automatische Freigabe an/aus; beim Einschalten sofort anwenden. */
    public function automatik(Request $request, FacebookBewertungAbruf $abruf)
    {
        $an = $request->boolean('an');
        SystemSetting::set(FacebookBewertung::SETTING_AUTOMATISCH, $an ? '1' : '0');
        ActivityLog::record('facebook_bewertungen_automatik_'.($an ? 'an' : 'aus'));
        $anzahl = $an ? $abruf->automatischFreigeben() : 0;

        return back()->with('success', $an
            ? 'Automatische Freigabe eingeschaltet - '.$anzahl.' Empfehlung(en) sofort freigegeben.'
            : 'Automatische Freigabe ausgeschaltet. Bereits freigegebene bleiben stehen.');
    }

    public function abrufen(FacebookBewertungAbruf $abruf)
    {
        try {
            $e = $abruf->aktualisieren();
        } catch (\Throwable $ex) {
            return back()->withErrors(['abruf' => $ex->getMessage()]);
        }

        return back()->with('success', sprintf(
            'Abgerufen: %d Empfehlungen (%d positiv). Neu: %d, geaendert: %d, bei Facebook entfernt: %d, automatisch freigegeben: %d.',
            $e['gesamt'], $e['positiv'], $e['neu'], $e['geaendert'], $e['entfernt'], $e['automatisch']
        ));
    }
}
