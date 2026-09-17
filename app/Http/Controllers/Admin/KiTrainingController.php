<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AiKnowledgeEntry;
use App\Models\AiTrainingExample;
use App\Models\TrainingImport;
use App\Services\Ai\Training\TrainingImportService;
use Illuminate\Http\Request;

/**
 * KI-Training: Verlauf hochladen, pruefen, schwaerzen, freigeben
 * (Betreiber-Auftrag 17.09.2026, Auftrag Abschnitte 18-21).
 *
 * DER ABLAUF, den der Betreiber bedient:
 *   1. Datei hochladen (der Knopf "Chat exportieren" im Telefon).
 *   2. VORSCHAU - was wurde erkannt, wer hat geschrieben, was fehlt.
 *   3. Kundenakte zuordnen und sagen, welcher Absender der Betrieb ist.
 *   4. Uebernehmen: der Verlauf steht stumm in der Kundenakte, daraus
 *      entstehen geschwaerzte Frage-Antwort-Paare.
 *   5. Jedes Paar einzeln freigeben oder ablehnen.
 *
 * NUR admin/manager: hier entstehen Auskuenfte, die der Assistent
 * spaeter JEDEM Kunden gibt - dieselbe Begruendung wie bei der
 * Wissensbasis.
 */
class KiTrainingController extends Controller
{
    public function __construct(private readonly TrainingImportService $service) {}

    public function index(Request $request)
    {
        $import = $request->query('verlauf')
            ? TrainingImport::with(['customer.user', 'user'])->find($request->query('verlauf'))
            : null;

        return view('admin.ki_training.index', [
            'imports' => TrainingImport::with(['customer.user', 'user'])
                ->withCount(['messages', 'examples'])
                ->latest()->paginate(20)->withQueryString(),
            'active' => $import,
            // Nur ein AUSSCHNITT: eine Datei kann tausende Zeilen haben,
            // und die Vorschau soll zeigen, ob die Erkennung stimmt -
            // nicht den ganzen Verlauf noch einmal.
            'vorschau' => $import
                ? $import->messages()->orderBy('row_number')->limit(30)->get()
                : collect(),
            'offeneBeispiele' => AiTrainingExample::with('import')
                ->where('status', AiTrainingExample::STATUS_OFFEN)
                ->orderBy('created_at')->limit(50)->get(),
            'zahlen' => [
                'offen' => AiTrainingExample::where('status', AiTrainingExample::STATUS_OFFEN)->count(),
                'freigegeben' => AiTrainingExample::where('status', AiTrainingExample::STATUS_FREIGEGEBEN)->count(),
                'abgelehnt' => AiTrainingExample::where('status', AiTrainingExample::STATUS_ABGELEHNT)->count(),
                'pruefsatz' => AiTrainingExample::where('is_holdout', true)->count(),
            ],
        ]);
    }

    /**
     * Datei hochladen und als ENTWURF ablegen.
     *
     * Es entsteht KEINE Nachricht - der Betreiber sieht erst, was
     * erkannt wurde.
     */
    public function store(Request $request)
    {
        $request->validate([
            // Der Export ist eine reine Textdatei. Bewusst eng: was hier
            // hereinkommt, wird als Gespraech gelesen.
            'datei' => 'required|file|max:10240|mimetypes:text/plain,application/octet-stream',
        ]);

        $datei = $request->file('datei');
        $inhalt = (string) file_get_contents($datei->getRealPath());

        if (trim($inhalt) === '') {
            return back()->with('error', 'Die Datei ist leer.');
        }

        $import = $this->service->analyze($inhalt, (string) $datei->getClientOriginalName(), $request->user());

        if (($import->stats['message_count'] ?? 0) === 0) {
            return redirect()->route('admin.ki_training', ['verlauf' => $import->id])
                ->with('error', 'In der Datei wurde keine Nachricht erkannt. '
                    .'Bitte prüfen, ob es die Datei aus "Chat exportieren" ist.');
        }

        return redirect()->route('admin.ki_training', ['verlauf' => $import->id])
            ->with('success', $import->stats['message_count'].' Nachricht(en) erkannt. '
                .'Bitte Kundenakte und Absender festlegen, dann übernehmen.');
    }

    /** Kundenakte und Betriebs-Absender festlegen - beides nie geraten. */
    public function update(Request $request, int $id)
    {
        $import = TrainingImport::findOrFail($id);
        abort_unless($import->isDraft(), 422, 'Dieser Verlauf ist bereits übernommen.');

        $data = $request->validate([
            'customer_id' => 'nullable|exists:customers,id',
            'business_sender' => 'nullable|string|max:190',
        ]);

        // Der Absender muss WIRKLICH in der Datei vorkommen - ein
        // freigetippter Name traefe nie zu, und der Import haette
        // anschliessend jede Nachricht als Kundennachricht gefuehrt.
        if (! empty($data['business_sender'])
            && ! array_key_exists($data['business_sender'], $import->senders())) {
            return back()->with('error', 'Dieser Absender kommt in der Datei nicht vor.');
        }

        if (! empty($data['customer_id'])) {
            abort_unless($request->user()->canAccessCustomer($data['customer_id']), 403);
        }

        $import->fill($data)->save();

        return back()->with('success', 'Zuordnung gespeichert.');
    }

    /** Den Entwurf uebernehmen: stummer Verlauf + geschwaerzte Paare. */
    public function confirm(Request $request, int $id)
    {
        $import = TrainingImport::with('customer.user')->findOrFail($id);
        abort_unless($request->user()->canAccessCustomer($import->customer_id), 403);

        try {
            $ergebnis = $this->service->confirm($import, $request->user());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        // Ueber record(): die Spalte ist als Array gecastet, ein
        // vor-json_encode'ter Wert stuende doppelt kodiert darin
        // (Lehre 19.08.2026).
        ActivityLog::record('ki_training_import', 'training_import', $import->id, [
            'messages' => $ergebnis['messages'],
            'examples' => $ergebnis['examples'],
        ], $request->user()->id);

        return back()->with('success', sprintf(
            '%d Nachricht(en) in die Kundenakte übernommen, %d Frage-Antwort-Paar(e) zur Prüfung erstellt.'
            .' Der Verlauf ist stumm: er löst keine KI-Antwort und keinen Versand aus.',
            $ergebnis['messages'],
            $ergebnis['examples']
        ));
    }

    /** Einen Entwurf verwerfen - die Datei war die falsche. */
    public function discard(int $id)
    {
        $import = TrainingImport::findOrFail($id);
        abort_unless($import->isDraft(), 422, 'Ein übernommener Verlauf wird nicht verworfen.');

        $import->forceFill(['status' => TrainingImport::STATUS_VERWORFEN])->save();
        $import->messages()->delete();

        return back()->with('success', 'Entwurf verworfen. Es wurde nichts übernommen.');
    }

    /**
     * Ein Beispiel freigeben oder ablehnen (Auftrag Abschnitt 19).
     *
     * DIE FREIGABE HAT ZWEI BEDEUTUNGEN, je nachdem, in welchem Teil das
     * Beispiel liegt:
     *  - normal   -> es wird zur AUSKUNFT (Wissensbasis-Eintrag).
     *  - Pruefsatz -> es wird zur MESSLATTE der Kompetenzpruefung und
     *                 gelangt ausdruecklich NICHT in die Wissensbasis.
     * Wer den Pruefsatz in die Wissensbasis liesse, pruefte die KI
     * spaeter an genau den Antworten, die er ihr vorher gegeben hat.
     */
    public function review(Request $request, int $id)
    {
        $beispiel = AiTrainingExample::findOrFail($id);
        $data = $request->validate([
            'entscheidung' => 'required|in:freigeben,ablehnen',
            'titel' => 'nullable|string|max:200',
        ]);

        if ($data['entscheidung'] === 'ablehnen') {
            $beispiel->forceFill([
                'status' => AiTrainingExample::STATUS_ABGELEHNT,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ])->save();

            return back()->with('success', 'Beispiel abgelehnt. Es wird nie als Auskunft benutzt.');
        }

        $eintrag = null;

        if (! $beispiel->is_holdout) {
            $eintrag = AiKnowledgeEntry::create([
                'title' => mb_substr(trim((string) (($data['titel'] ?? null) ?: $beispiel->question)), 0, 200),
                'category' => 'faq',
                'content' => $beispiel->answer,
                'language' => $beispiel->language ?: 'de',
                // AKTIV: der Mitarbeiter hat beide Texte und den
                // Schwaerzungsbericht gerade gelesen - ihn danach noch
                // einmal in der Wissensbasis freigeben zu lassen waere
                // dieselbe Entscheidung ein zweites Mal.
                'active' => true,
                // Herkunft, und zugleich der Duplikatsschutz: dasselbe
                // Beispiel kann nie zwei Eintraege erzeugen.
                'source_key' => 'training:'.$beispiel->id,
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ]);
        }

        $beispiel->forceFill([
            'status' => AiTrainingExample::STATUS_FREIGEGEBEN,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'knowledge_entry_id' => $eintrag?->id,
        ])->save();

        return back()->with('success', $eintrag
            ? 'Freigegeben und als Auskunft in die Wissensbasis übernommen.'
            : 'Freigegeben als Prüfsatz. Er dient der Kompetenzmessung und wird bewusst NICHT zur Auskunft.');
    }
}
