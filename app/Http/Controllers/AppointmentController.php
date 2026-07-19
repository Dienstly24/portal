<?php
namespace App\Http\Controllers;
use App\Http\Controllers\Concerns\ScopesCustomerAccess;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\CustomerTimeline;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AppointmentController extends Controller
{
    use ScopesCustomerAccess;

    public function index() {
        $appointments = Appointment::with(['customer.user','assignedTo'])
            ->where('starts_at', '>=', now()->startOfDay())
            ->orderBy('starts_at')
            ->when($this->visibleCustomerIds() !== null, fn($q) => $q->whereIn('customer_id', $this->visibleCustomerIds()))->get();
        $past = Appointment::with(['customer.user','assignedTo'])
            ->where('starts_at', '<', now()->startOfDay())
            ->orderByDesc('starts_at')
            ->take(20)
            ->get();
        return view('admin.appointments', compact('appointments','past'));
    }

    public function store(Request $request) {
        $request->validate([
            'customer_id' => 'required',
            'title' => 'required',
            'starts_at' => 'required|date',
            'ends_at' => 'required|date|after:starts_at',
        ]);
        $appointment = Appointment::create([
            'customer_id' => $request->customer_id,
            'assigned_to' => $request->assigned_to ?? auth()->id(),
            'title' => $request->title,
            'notes' => $request->notes,
            'starts_at' => $request->starts_at,
            'ends_at' => $request->ends_at,
            'status' => 'scheduled',
        ]);
        CustomerTimeline::create([
            'customer_id' => $request->customer_id,
            'user_id' => auth()->id(),
            'type' => 'appointment',
            'title' => 'Termin erstellt: ' . $request->title,
            'description' => 'Am ' . \Carbon\Carbon::parse($request->starts_at)->format('d.m.Y H:i'),
        ]);
        return back()->with('success', 'Termin erstellt.');
    }

    public function update(Request $request, $id) {
        $request->validate(['status' => 'required|in:scheduled,completed,cancelled']);
        Appointment::findOrFail($id)->update(['status' => $request->status]);
        return back()->with('success', 'Termin aktualisiert.');
    }
}
