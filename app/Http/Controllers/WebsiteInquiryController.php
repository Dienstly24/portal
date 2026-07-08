<?php
namespace App\Http\Controllers;

use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WebsiteInquiryController extends Controller
{
    // استقبال من نموذج الووردبريس (POST مع token)
    public function store(Request $request)
    {
        // Fail closed: without a configured token, header (null) === config (null)
        // previously passed the check and let anyone create tickets. (Audit C5)
        $token = config('services.inquiry.token');
        if (!is_string($token) || $token === ''
            || !is_string($request->header('X-Inquiry-Token'))
            || !hash_equals($token, $request->header('X-Inquiry-Token'))) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        $data = $request->validate([
            'name' => 'required|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'nullable|max:50',
            'subject' => 'nullable|max:255',
            'message' => 'required|max:5000',
        ]);
        Ticket::forceCreate([
            'id' => Str::uuid(),
            'customer_id' => null,
            'source' => 'website',
            'type' => 'other',
            'priority' => 'mittel',
            'status' => 'open',
            'subject' => ($data['subject'] ?? null) ?: ('Website-Anfrage von ' . $data['name']),
            'description' => $data['message'],
            'guest_name' => $data['name'],
            'guest_email' => $data['email'],
            'guest_phone' => $data['phone'] ?? null,
        ]);
        return response()->json(['success' => true]);
    }

    // إدخال يدوي (إيميلات info@)
    public function createManual()
    {
        return view('admin.inquiry_create');
    }

    public function storeManual(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|max:50',
            'subject' => 'required|max:255',
            'message' => 'required',
        ]);
        Ticket::forceCreate([
            'id' => Str::uuid(),
            'customer_id' => null,
            'source' => 'email',
            'type' => 'other',
            'priority' => $request->priority ?? 'mittel',
            'status' => 'open',
            'subject' => $data['subject'],
            'description' => $data['message'],
            'guest_name' => $data['name'],
            'guest_email' => $data['email'] ?? null,
            'guest_phone' => $data['phone'] ?? null,
        ]);
        return redirect()->route('admin.inquiries')->with('success', 'Anfrage erfasst.');
    }
}
