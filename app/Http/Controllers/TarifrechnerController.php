<?php
namespace App\Http\Controllers;
use App\Models\TarifrechnerLink;
use App\Models\Announcement;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TarifrechnerController extends Controller
{
    public function index() {
        $links = TarifrechnerLink::orderBy('category')->orderBy('sort_order')->get()->groupBy('category');
        $categories = [
            'kfz' => ['label'=>'KFZ-Versicherung','icon'=>'🚗','color'=>'#E6F1FB','text'=>'#185FA5'],
            'kranken' => ['label'=>'Krankenversicherung','icon'=>'🏥','color'=>'#E4F0E7','text'=>'#3B7A57'],
            'energie' => ['label'=>'Energie','icon'=>'⚡','color'=>'#FEF3C7','text'=>'#92400E'],
            'internet' => ['label'=>'Internet','icon'=>'📶','color'=>'#EDE9FE','text'=>'#6D28D9'],
            'sim' => ['label'=>'SIM & Handy','icon'=>'📱','color'=>'#F3E8FF','text'=>'#7C3AED'],
            'sonstige' => ['label'=>'Sonstige Links','icon'=>'🔗','color'=>'#F1EFE8','text'=>'#5F5E5A'],
        ];
        return view('admin.tarifrechner', compact('links','categories'));
    }

    public function store(Request $request) {
        $request->validate(['category'=>'required','title'=>'required','url'=>'required|url']);
        TarifrechnerLink::create([
            'category' => $request->category,
            'title' => $request->title,
            'url' => $request->url,
            'description' => $request->description,
            'sort_order' => $request->sort_order ?? 0,
        ]);
        return back()->with('success', 'Link hinzugefügt.');
    }

    public function destroy($id) {
        TarifrechnerLink::findOrFail($id)->delete();
        return back()->with('success', 'Link gelöscht.');
    }

    public function announcements() {
        $announcements = Announcement::with('createdBy')->latest()->get();
        return view('admin.announcements', compact('announcements'));
    }

    public function storeAnnouncement(Request $request) {
        $request->validate(['title'=>'required','body'=>'required']);
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
