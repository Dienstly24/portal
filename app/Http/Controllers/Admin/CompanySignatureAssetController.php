<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\CompanySignatureAsset;
use App\Services\Signature\CompanySignatureAssetService;
use App\Support\UploadRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Einstellungen -> Signaturen -> Unternehmenssignaturen.
 *
 * Jede Methode prueft das Recht ERNEUT, obwohl die Route es schon tut:
 * hier entsteht die Unterschrift des Betriebs, und eine Pruefung, die nur
 * an der Route haengt, faellt beim naechsten Umbau der Routendatei
 * lautlos weg (dieselbe Haltung wie beim Provisions-Bereich).
 */
class CompanySignatureAssetController extends Controller
{
    public function __construct(private readonly CompanySignatureAssetService $assets)
    {
    }

    public function index()
    {
        Gate::authorize('firmensignatur-verwalten');

        return view('admin.signatures.company_assets', [
            'assets' => CompanySignatureAsset::with('creator')->orderBy('type')->orderByDesc('created_at')->get(),
            'types' => CompanySignatureAsset::TYPES,
        ]);
    }

    public function store(Request $request)
    {
        Gate::authorize('firmensignatur-verwalten');

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'type' => ['required', 'string', 'in:'.implode(',', CompanySignatureAsset::typeKeys())],
            'is_default' => ['nullable', 'boolean'],
            'bild' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:'.UploadRules::MAX_KB],
        ], [
            'bild.mimes' => 'Erlaubt sind PNG, JPG und WebP. Für Unterschrift und Stempel ist ein PNG mit '
                .'transparentem Hintergrund die richtige Wahl - sonst liegt ein weißer Kasten über dem Vertragstext.',
        ]);

        try {
            $asset = $this->assets->store($request->file('bild'), $data, $request->user());
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        ActivityLog::record('company_signature_added', 'company_signature_asset', $asset->id,
            ['name' => $asset->name, 'type' => $asset->type]);

        return back()->with('success', 'Gespeichert. Sie können das Bild jetzt im Feld-Editor auf ein Dokument setzen.');
    }

    public function makeDefault(string $id)
    {
        Gate::authorize('firmensignatur-verwalten');
        $asset = CompanySignatureAsset::findOrFail($id);
        $this->assets->makeDefault($asset);
        ActivityLog::record('company_signature_default', 'company_signature_asset', $asset->id, ['name' => $asset->name]);

        return back()->with('success', '„'.$asset->name.'" ist jetzt die Voreinstellung für '.$asset->typeLabel().'.');
    }

    public function destroy(string $id)
    {
        Gate::authorize('firmensignatur-verwalten');
        $asset = CompanySignatureAsset::findOrFail($id);
        $name = $asset->name;
        $ergebnis = $this->assets->remove($asset);
        ActivityLog::record('company_signature_removed', 'company_signature_asset', $id,
            ['name' => $name, 'ergebnis' => $ergebnis]);

        return back()->with('success', $ergebnis === 'geloescht'
            ? '„'.$name.'" wurde gelöscht.'
            : '„'.$name.'" steht in bereits unterschriebenen Dokumenten und wurde deshalb nur stillgelegt - '
              .'die Datei bleibt erhalten, damit die Belege vollständig bleiben.');
    }

    /** Vorschau. Laeuft ueber den Controller, nie ueber einen Dateipfad. */
    public function image(string $id)
    {
        // Auch BENUTZER duerfen das Bild sehen - sie muessen es im Editor
        // auswaehlen koennen, ohne es verwalten zu duerfen.
        if (! Gate::allows('firmensignatur-verwalten') && ! Gate::allows('firmensignatur-benutzen')) {
            abort(403);
        }
        $asset = CompanySignatureAsset::findOrFail($id);
        $png = $this->assets->read($asset);
        if ($png === null) {
            abort(404);
        }

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'inline; filename="firmenbild.png"',
            'Cache-Control' => 'private, max-age=600',
        ]);
    }
}
