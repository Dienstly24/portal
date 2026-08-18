<?php

namespace App\Http\Controllers;

use App\Models\AiLead;
use App\Services\Ai\Assistant\AssistantSettings;
use App\Services\Ai\Assistant\Website\LeadService;
use App\Services\Ai\Assistant\Website\WebsiteAssistantService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Oeffentlicher Chat des Website-Assistenten (Spezifikation Abschnitt 19).
 *
 * Der Besucher ist NICHT angemeldet. Die Zuordnung laeuft ueber die
 * Server-Sitzung: die Lead-Kennung wird in der Session gehalten und NIE
 * vom Browser uebernommen. Sonst koennte jeder eine fremde Kennung
 * schicken und dessen Gespraech mitlesen - dieselbe Regel wie im Portal,
 * wo die Kunden-ID nie aus dem Request kommt.
 */
class WebsiteAssistantController extends Controller
{
    private const SESSION_KEY = 'ai_website_lead';

    /**
     * Ist der Assistent auf der Website ueberhaupt verfuegbar? Der
     * Website-Chat haengt am selben Hauptschalter wie der Portal-Chat.
     */
    public function status(AssistantSettings $settings): array
    {
        return ['verfuegbar' => $settings->enabled()];
    }

    public function send(
        Request $request,
        WebsiteAssistantService $assistant,
        LeadService $leads,
        AssistantSettings $settings,
    ) {
        $data = $request->validate([
            'nachricht' => 'required|string|max:2000',
        ]);

        if (!$settings->enabled()) {
            return response()->json([
                'ok' => false,
                'antwort' => __('Der Assistent steht gerade nicht zur Verfügung. '
                    . 'Bitte nutzen Sie das Kontaktformular – wir melden uns zeitnah.'),
            ], 200);
        }

        // Kennung ausschliesslich aus der Server-Sitzung.
        $key = $request->session()->get(self::SESSION_KEY);
        if (!$key || !Str::isUuid($key)) {
            $key = (string) Str::uuid();
            $request->session()->put(self::SESSION_KEY, $key);
        }

        $lead = $leads->forSession($key, (string) $data['nachricht']);
        $ergebnis = $assistant->handle($lead, (string) $data['nachricht']);

        return response()->json([
            'ok' => true,
            'antwort' => $ergebnis['antwort'],
            'uebergeben' => $ergebnis['uebergeben'],
        ]);
    }

    /** Bisheriger Verlauf der Sitzung (fuer einen Seitenwechsel). */
    public function history(Request $request)
    {
        $key = $request->session()->get(self::SESSION_KEY);
        $lead = $key ? AiLead::find($key) : null;

        return response()->json([
            'verlauf' => $lead ? $lead->transcriptData() : [],
        ]);
    }
}
