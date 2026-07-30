<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessMediaAssetJob;
use App\Models\MediaAsset;
use App\Services\Media\SvgSanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Medienverwaltung der Website (/admin/medien, Arbeitsauftrag P1-1).
 *
 * Kern-Workflow: Bild hochladen -> Slot (festen Platz auf der Website)
 * aus der Liste waehlen -> Alt-Texte (DE+AR, PFLICHT) -> speichern ->
 * das Bild ist sofort live, in AVIF/WebP/JPG und drei Breiten, jede
 * Variante unter 200 KB. Kein FTP, kein Code, kein Entwickler.
 *
 * Rollen: Loeschen/Wiederherstellen nur admin/manager (Route-Middleware);
 * Hochladen/Ersetzen/Bearbeiten duerfen alle Staff-Rollen ("Redakteur").
 * Sicherheit: echte MIME-Pruefung (finfo, nicht Dateiendung), SVGs werden
 * sanitisiert (Skripte/Event-Handler raus), Originale liegen PRIVAT
 * (Archiv), nur erzeugte Varianten sind oeffentlich.
 */
class MediaLibraryController extends Controller
{
    private const ALLOWED_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
    ];

    public function index(Request $request)
    {
        $q = trim((string) $request->input('q', ''));
        $slotFilter = (string) $request->input('slot', '');

        $assets = MediaAsset::query()
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('title', 'like', "%{$q}%")
                ->orWhere('original_name', 'like', "%{$q}%")
                ->orWhere('alt_de', 'like', "%{$q}%")))
            ->when($slotFilter !== '', fn ($query) => $query->where('slot', $slotFilter))
            ->latest()
            ->paginate(24)
            ->withQueryString();

        $trashed = MediaAsset::onlyTrashed()->latest('deleted_at')->limit(50)->get();

        // Speicherverbrauch (Original + Varianten, inkl. Papierkorb).
        $usedBytes = MediaAsset::withTrashed()->get()->sum(fn ($a) => $a->totalBytes());

        $slots = config('website.slots');
        $slotUsage = MediaAsset::whereNotNull('slot')->pluck('slot')->flip();

        return view('admin.media', [
            'assets' => $assets,
            'trashed' => $trashed,
            'usedBytes' => $usedBytes,
            'slots' => $slots,
            'slotUsage' => $slotUsage,
            'q' => $q,
            'slotFilter' => $slotFilter,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'files' => 'required|array|min:1|max:10',
            'files.*' => 'required|file|max:' . (int) config('website.media.max_upload_kb'),
            // Alt-Texte sind PFLICHT (P1-1d): ohne geht Speichern nicht.
            'alt_de' => 'required|string|max:500',
            'alt_ar' => 'required|string|max:500',
            'credit' => 'nullable|string|max:500',
            'slot' => ['nullable', Rule::in(array_keys((array) config('website.slots')))],
            'title' => 'nullable|string|max:150',
        ], [], [
            'files' => 'Dateien',
            'alt_de' => 'Alt-Text (Deutsch)',
            'alt_ar' => 'Alt-Text (Arabisch)',
        ]);

        // Ein Slot kann nur EIN Bild tragen -> Mehrfach-Upload nur ohne Slot.
        $files = $request->file('files');
        if (count($files) > 1 && $request->filled('slot')) {
            return back()->withErrors(['slot' => 'Ein Slot kann nur ein Bild tragen - bitte bei Slot-Zuweisung genau eine Datei hochladen.'])->withInput();
        }

        $created = 0;
        foreach ($files as $file) {
            // Echte Inhaltspruefung (finfo/MIME-Sniffing) statt Dateiendung.
            $mime = strtolower((string) $file->getMimeType());
            if ($mime === 'image/svg') {
                $mime = 'image/svg+xml';
            }
            if (! array_key_exists($mime, self::ALLOWED_MIMES)) {
                return back()->withErrors(['files' => 'Dateityp nicht erlaubt (' . $mime . '). Erlaubt: JPG, PNG, WebP, SVG.'])->withInput();
            }

            $content = (string) file_get_contents($file->getRealPath());
            if ($mime === 'image/svg+xml') {
                $content = SvgSanitizer::sanitize($content);
                if ($content === null) {
                    return back()->withErrors(['files' => 'SVG-Datei ist ungueltig oder enthaelt nicht entfernbare aktive Inhalte.'])->withInput();
                }
            }

            $asset = MediaAsset::create([
                'title' => $request->input('title') ?: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
                'original_name' => $file->getClientOriginalName(),
                'mime' => $mime,
                'size_bytes' => strlen($content),
                'alt_de' => $request->input('alt_de'),
                'alt_ar' => $request->input('alt_ar'),
                'credit' => $request->input('credit'),
                'original_path' => 'pending',
                'processing_status' => 'pending',
                'uploaded_by' => $request->user()->id,
            ]);

            // Original PRIVAT archivieren (nie oeffentlich ausgeliefert).
            $ext = self::ALLOWED_MIMES[$mime];
            $originalPath = 'media-originals/' . $asset->id . '/original.' . $ext;
            Storage::disk('local')->put($originalPath, $content);
            $asset->forceFill(['original_path' => $originalPath])->save();

            // Varianten sofort erzeugen (kein Queue-Worker noetig).
            ProcessMediaAssetJob::dispatchSync($asset);
            $asset->refresh();

            if ($request->filled('slot') && $asset->processing_status === 'ready') {
                $this->assignSlot($asset, $request->input('slot'));
            }
            $created++;
        }

        return redirect()->route('admin.media')
            ->with('success', $created . ' Bild(er) hochgeladen' . ($request->filled('slot') ? ' und dem Slot zugewiesen - sofort live.' : '.'));
    }

    public function update(Request $request, MediaAsset $asset)
    {
        $data = $request->validate([
            'title' => 'required|string|max:150',
            'alt_de' => 'required|string|max:500',
            'alt_ar' => 'required|string|max:500',
            'credit' => 'nullable|string|max:500',
            'slot' => ['nullable', Rule::in(array_keys((array) config('website.slots')))],
        ], [], [
            'alt_de' => 'Alt-Text (Deutsch)',
            'alt_ar' => 'Alt-Text (Arabisch)',
        ]);

        $asset->fill([
            'title' => $data['title'],
            'alt_de' => $data['alt_de'],
            'alt_ar' => $data['alt_ar'],
            'credit' => $data['credit'] ?? null,
        ])->save();

        $newSlot = $data['slot'] ?? null;
        if ($newSlot !== $asset->slot) {
            if ($newSlot && $asset->processing_status !== 'ready') {
                return back()->withErrors(['slot' => 'Bild ist noch nicht fertig verarbeitet und kann keinem Slot zugewiesen werden.']);
            }
            $newSlot ? $this->assignSlot($asset, $newSlot) : $asset->forceFill(['slot' => null])->save();
        }

        return redirect()->route('admin.media')->with('success', 'Bild aktualisiert.');
    }

    /**
     * "Ersetzen": neue Datei fuer einen belegten Platz. Das alte Bild wird
     * NICHT geloescht, sondern wandert ins Archiv (Slot = frei); das neue
     * uebernimmt Slot und Alt-Texte (anpassbar).
     */
    public function replace(Request $request, MediaAsset $asset)
    {
        $request->validate([
            'file' => 'required|file|max:' . (int) config('website.media.max_upload_kb'),
        ]);

        $file = $request->file('file');
        $mime = strtolower((string) $file->getMimeType());
        if ($mime === 'image/svg') {
            $mime = 'image/svg+xml';
        }
        if (! array_key_exists($mime, self::ALLOWED_MIMES)) {
            return back()->withErrors(['file' => 'Dateityp nicht erlaubt (' . $mime . '). Erlaubt: JPG, PNG, WebP, SVG.']);
        }

        $content = (string) file_get_contents($file->getRealPath());
        if ($mime === 'image/svg+xml') {
            $content = SvgSanitizer::sanitize($content);
            if ($content === null) {
                return back()->withErrors(['file' => 'SVG-Datei ist ungueltig oder enthaelt nicht entfernbare aktive Inhalte.']);
            }
        }

        $new = MediaAsset::create([
            'title' => $asset->title,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $mime,
            'size_bytes' => strlen($content),
            'alt_de' => $asset->alt_de,
            'alt_ar' => $asset->alt_ar,
            'credit' => $asset->credit,
            'original_path' => 'pending',
            'processing_status' => 'pending',
            'uploaded_by' => $request->user()->id,
        ]);

        $ext = self::ALLOWED_MIMES[$mime];
        $originalPath = 'media-originals/' . $new->id . '/original.' . $ext;
        Storage::disk('local')->put($originalPath, $content);
        $new->forceFill(['original_path' => $originalPath])->save();

        ProcessMediaAssetJob::dispatchSync($new);
        $new->refresh();

        $slot = $asset->slot;
        if ($slot && $new->processing_status === 'ready') {
            $this->assignSlot($new, $slot); // archiviert das alte automatisch
        }

        return redirect()->route('admin.media')->with('success', 'Bild ersetzt' . ($slot ? ' - der Slot zeigt sofort das neue Bild.' : '.'));
    }

    /** Papierkorb (nur admin/manager): 30 Tage wiederherstellbar. */
    public function destroy(MediaAsset $asset)
    {
        // Warnung ist im UI; hier die letzte Verteidigungslinie mit Hinweis.
        $wasSlot = $asset->slot;
        $asset->forceFill(['slot' => null])->save();
        $asset->delete();

        return redirect()->route('admin.media')->with('success',
            'Bild in den Papierkorb gelegt (30 Tage wiederherstellbar).'
            . ($wasSlot ? ' Achtung: Der Slot "' . $wasSlot . '" ist jetzt leer - die Website zeigt dort den eingebauten Fallback.' : ''));
    }

    public function restore(int $id)
    {
        $asset = MediaAsset::onlyTrashed()->findOrFail($id);
        $asset->restore();

        return redirect()->route('admin.media')->with('success', 'Bild wiederhergestellt (ohne Slot - bei Bedarf neu zuweisen).');
    }

    /** Slot exklusiv setzen: bisheriges Slot-Bild wandert ins Archiv. */
    private function assignSlot(MediaAsset $asset, string $slot): void
    {
        DB::transaction(function () use ($asset, $slot) {
            MediaAsset::where('slot', $slot)->where('id', '!=', $asset->id)->update(['slot' => null]);
            $asset->forceFill(['slot' => $slot])->save();
        });
        // Cache der Slot-Aufloesung leeren (Model-Event deckt das Update-Query nicht ab).
        \Illuminate\Support\Facades\Cache::forget('website-media-slot:' . $slot);
    }
}
