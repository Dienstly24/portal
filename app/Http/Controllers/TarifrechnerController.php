<?php
namespace App\Http\Controllers;
use App\Models\TarifrechnerLink;
use App\Models\Announcement;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TarifrechnerController extends Controller
{
    /**
     * Kanonische Abschnitte des Link-Centers (Vergleichsportale).
     * keywords = zusaetzliche Suchbegriffe fuer die Volltextsuche.
     */
    public static function categories(): array {
        return [
            'kfz'          => ['label'=>'KFZ',                  'icon'=>'🚗','color'=>'#D9F4E6','text'=>'#128A4B','keywords'=>'auto fahrzeug kfz kennzeichen evb'],
            'kranken'      => ['label'=>'Krankenversicherung',  'icon'=>'🏥','color'=>'#E4F0E7','text'=>'#3B7A57','keywords'=>'pkv gkv gesundheit zahn kranken'],
            'sach'         => ['label'=>'Sachversicherung',     'icon'=>'🏠','color'=>'#FEF3C7','text'=>'#92400E','keywords'=>'hausrat wohngebaeude gebaeude sach elementar'],
            'haftpflicht'  => ['label'=>'Haftpflicht',          'icon'=>'🛡️','color'=>'#F0E6FB','text'=>'#6D28D9','keywords'=>'privathaftpflicht haftpflicht tierhalter'],
            'rechtsschutz' => ['label'=>'Rechtsschutz',         'icon'=>'⚖️','color'=>'#FBEAD6','text'=>'#B5651D','keywords'=>'rechtsschutz jura anwalt'],
            'leben'        => ['label'=>'Leben & Vorsorge',     'icon'=>'❤️','color'=>'#FBEAF0','text'=>'#993556','keywords'=>'leben bu berufsunfaehigkeit rente altersvorsorge riester ruerup'],
            'unfall'       => ['label'=>'Unfall',               'icon'=>'🚑','color'=>'#F9E3E3','text'=>'#A32D2D','keywords'=>'unfall invaliditaet'],
            'tier'         => ['label'=>'Tier',                 'icon'=>'🐾','color'=>'#FCEFD9','text'=>'#92620E','keywords'=>'tier hund katze pferd op tierkranken'],
            'energie'      => ['label'=>'Energie',              'icon'=>'⚡','color'=>'#FEF9C3','text'=>'#854D0E','keywords'=>'strom gas energie waerme tarif'],
            'internet'     => ['label'=>'Internet & Mobilfunk', 'icon'=>'📶','color'=>'#E7EEF9','text'=>'#2C5AA0','keywords'=>'internet dsl glasfaser mobilfunk handy sim dsl'],
            'reise'        => ['label'=>'Reise',                'icon'=>'✈️','color'=>'#E0F2F1','text'=>'#0F766E','keywords'=>'reise urlaub auslandskranken gepaeck'],
            'sonstige'     => ['label'=>'Sonstiges',            'icon'=>'🔗','color'=>'#EFEDE4','text'=>'#5F6B62','keywords'=>'sonstige portal tool'],
        ];
    }

    public function index() {
        $categories = self::categories();
        $known = array_keys($categories);
        // Links nach sort_order, dann alphabetisch. Unbekannte (alte) Kategorien
        // landen unter "sonstige", damit nie Links unsichtbar werden.
        $links = TarifrechnerLink::orderBy('sort_order')->orderBy('title')->get()
            ->groupBy(fn($l) => in_array($l->category, $known, true) ? $l->category : 'sonstige');
        return view('admin.tarifrechner', compact('links','categories'));
    }

    public function store(Request $request) {
        $data = $request->validate([
            'category'    => 'required|in:'.implode(',', array_keys(self::categories())),
            'title'       => 'required|string|max:120',
            'url'         => 'required|url|max:2048',
            'description' => 'nullable|string|max:255',
        ]);
        // Neuer Link ans Ende seines Abschnitts.
        $max = TarifrechnerLink::where('category', $data['category'])->max('sort_order');
        TarifrechnerLink::create([
            'category'    => $data['category'],
            'title'       => $data['title'],
            'url'         => $data['url'],
            'description' => $data['description'] ?? null,
            'sort_order'  => is_null($max) ? 0 : $max + 1,
        ]);
        return back()->with('success', 'Link hinzugefügt.');
    }

    public function destroy($id) {
        TarifrechnerLink::findOrFail($id)->delete();
        return back()->with('success', 'Link gelöscht.');
    }

    /**
     * Reihenfolge innerhalb eines Abschnitts per Drag & Drop speichern.
     */
    public function reorder(Request $request) {
        $data = $request->validate([
            'order'   => 'required|array',
            'order.*' => 'string',
        ]);
        foreach ($data['order'] as $index => $id) {
            TarifrechnerLink::where('id', $id)->update(['sort_order' => $index]);
        }
        return response()->json(['ok' => true]);
    }

    public function announcements() {
        $announcements = Announcement::with('createdBy')->latest()->get();
        return view('admin.announcements', compact('announcements'));
    }

    public function storeAnnouncement(Request $request) {
        // priority ist eine ENUM-Spalte (normal/important/urgent) - Whitelist
        // gegen 500 unter MySQL strict (Audit DATA-P2).
        $request->validate([
            'title' => 'required',
            'body' => 'required',
            'priority' => 'nullable|in:normal,important,urgent',
            // Gegen 500 bei krummem Datum unter MySQL strict (Audit ANN-1).
            'expires_at' => 'nullable|date',
        ]);
        Announcement::create([
            'created_by' => auth()->id(),
            'title' => $request->title,
            'body' => $request->body,
            'priority' => $request->priority ?? 'normal',
            'expires_at' => $request->expires_at ?: null,
        ]);
        return back()->with('success', 'Ankündigung erstellt.');
    }

    public function destroyAnnouncement($id) {
        Announcement::findOrFail($id)->delete();
        return back()->with('success', 'Ankündigung gelöscht.');
    }
}
