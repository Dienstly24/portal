<?php
namespace App\Http\Controllers;
use App\Models\Task;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TaskController extends Controller
{
    public function index(Request $request) {
        $tab = $request->get('tab', 'mine');
        $status = $request->get('status', '');
        $type = $request->get('type', '');
        $due = $request->get('due', '');

        $query = Task::with(['assignedTo','customer.user','createdBy']);

        if($tab === 'mine') $query->where('assigned_to', auth()->id());
        elseif($tab === 'customer') $query->whereNotNull('customer_id');
        elseif($tab === 'done') $query->where('status','done');

        if($status) $query->where('status', $status);
        if($type) $query->where('type', $type);
        if($due === 'today') $query->whereDate('due_date', today());
        elseif($due === '14') $query->whereDate('due_date', '<=', today()->addDays(14));

        // CASE statt MySQL-spezifischem FIELD(), damit die Seite auch auf
        // SQLite/Postgres funktioniert. (Audit M5)
        $tasks = $query->orderBy('due_date')
            ->orderByRaw("CASE priority WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 ELSE 4 END")
            ->get();

        return view('admin.tasks', compact('tasks','tab'));
    }

    public function store(Request $request) {
        $request->validate(['title' => 'required', 'assigned_to' => 'required']);
        Task::create([
            'assigned_to' => $request->assigned_to,
            'created_by' => auth()->id(),
            'customer_id' => $request->customer_id ?: null,
            'title' => $request->title,
            'description' => $request->description,
            'type' => $request->type ?? 'other',
            'status' => 'open',
            'priority' => $request->priority ?? 'medium',
            'due_date' => $request->due_date,
        ]);
        return back()->with('success', 'Aufgabe erstellt.');
    }

    public function update(Request $request, $id) {
        $request->validate(['status' => 'required|in:open,in_progress,done']);
        $task = Task::findOrFail($id);
        $task->update(['status' => $request->status]);
        return back()->with('success', 'Status aktualisiert.');
    }

    public function destroy($id) {
        Task::findOrFail($id)->delete();
        return back()->with('success', 'Aufgabe gelöscht.');
    }
}
