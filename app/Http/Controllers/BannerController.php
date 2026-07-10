<?php
namespace App\Http\Controllers;

use App\Models\Banner;
use Illuminate\Http\Request;

/**
 * Bannerverwaltung (Punkt 3): erstellen, bearbeiten, löschen,
 * aktivieren/deaktivieren, sortieren, Start-/Enddatum. Bild ODER Video.
 * Nur Admin/Manager (Route-Middleware) - Banner sind Marketing-Inhalte
 * und liegen deshalb auf der public Disk.
 */
class BannerController extends Controller
{
    public function index() {
        return view('admin.banners', ['banners' => Banner::orderBy('sort_order')->orderBy('id')->get()]);
    }

    public function store(Request $request) {
        $data = $this->validated($request, true);
        $data['media_type'] = $this->storeMedia($request, $data);
        Banner::create($data);
        return back()->with('success', 'Banner erstellt.');
    }

    public function update(Request $request, Banner $banner) {
        $data = $this->validated($request, false);
        if ($request->hasFile('media')) {
            $data['media_type'] = $this->storeMedia($request, $data);
            try { \Storage::disk('public')->delete($banner->media_path); } catch (\Throwable $e) {}
        }
        $banner->update($data);
        return back()->with('success', 'Banner aktualisiert.');
    }

    public function toggle(Banner $banner) {
        $banner->update(['is_active' => !$banner->is_active]);
        return back()->with('success', $banner->is_active ? 'Banner aktiviert.' : 'Banner deaktiviert.');
    }

    public function destroy(Banner $banner) {
        try { \Storage::disk('public')->delete($banner->media_path); } catch (\Throwable $e) {}
        $banner->delete();
        return back()->with('success', 'Banner gelöscht.');
    }

    private function validated(Request $request, bool $isCreate): array {
        return $request->validate([
            'title' => 'required|string|max:150',
            'media' => ($isCreate ? 'required' : 'nullable') . '|file|mimes:jpg,jpeg,png,webp,mp4,webm|max:20480',
            'sort_order' => 'nullable|integer|min:0|max:9999',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'is_active' => 'nullable|boolean',
        ]);
    }

    private function storeMedia(Request $request, array &$data): string {
        $file = $request->file('media');
        $data['media_path'] = $file->store('banners', 'public');
        unset($data['media']);
        return in_array(strtolower($file->getClientOriginalExtension()), ['mp4', 'webm']) ? 'video' : 'image';
    }
}
