<?php
namespace App\Http\Controllers;
use App\Services\LexofficeService;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LexofficeController extends Controller
{
    public function __construct(private LexofficeService $lexoffice) {}

    public function contacts(Request $request) {
        $page = $request->get('page', 0);
        $search = $request->get('search', '');
        $data = $this->lexoffice->getContacts($page, 25, $search);
        return view('admin.lexoffice_contacts', [
            'contacts' => $data['content'] ?? [],
            'total' => $data['totalElements'] ?? 0,
            'page' => $page,
            'search' => $search,
            'pages' => ceil(($data['totalElements'] ?? 0) / 25),
        ]);
    }

    public function invoices(Request $request) {
        $page = $request->get('page', 0);
        $data = $this->lexoffice->getInvoices($page, 25);
        return view('admin.lexoffice_invoices', [
            'invoices' => $data['content'] ?? [],
            'total' => $data['totalElements'] ?? 0,
            'page' => $page,
            'pages' => ceil(($data['totalElements'] ?? 0) / 25),
        ]);
    }

    public function importContact(Request $request) {
        $contact = $this->lexoffice->getContact($request->lexoffice_id);
        if(!$contact) return back()->with('error', 'Kontakt nicht gefunden.');

        $name = isset($contact['company']) ?
            $contact['company']['name'] :
            trim(($contact['person']['firstName'] ?? '') . ' ' . ($contact['person']['lastName'] ?? ''));

        $email = $contact['emailAddresses']['business'][0] ?? $contact['emailAddresses']['private'][0] ?? null;
        $phone = $contact['phoneNumbers']['business'][0] ?? $contact['phoneNumbers']['mobile'][0] ?? null;

        if(!$email) return back()->with('error', 'Kein E-Mail beim Kontakt hinterlegt.');
        if(\App\Models\User::where('email', $email)->exists()) return back()->with('error', 'Kontakt bereits importiert.');

        $user = \App\Models\User::create([
            'name' => $name,
            'email' => $email,
            'password' => bcrypt(Str::random(12)),
            'role' => 'customer',
        ]);

        $address = '';
        if(isset($contact['addresses']['billing'][0])) {
            $a = $contact['addresses']['billing'][0];
            $address = trim(($a['street'] ?? '') . ', ' . ($a['zip'] ?? '') . ' ' . ($a['city'] ?? ''));
        }

        Customer::create([
            'id' => Str::uuid(),
            'user_id' => $user->id,
            'customer_number' => app(\App\Services\CustomerNumberGenerator::class)->generate(),
            'phone' => $phone,
            'address' => $address,
            'company_name' => $contact['company']['name'] ?? null,
            'customer_type' => isset($contact['company']) ? 'firma' : 'privat',
            'preferred_lang' => 'de',
        ]);

        return back()->with('success', "\"$name\" erfolgreich importiert.");
    }

    public function sendInvoice(Request $request, string $id) {
        $invoice = $this->lexoffice->getInvoice($id);
        $email = $request->email;
        if(!$email) return back()->with('error', 'Keine E-Mail-Adresse angegeben.');
        $sent = $this->lexoffice->sendInvoice($id, $email);
        return back()->with($sent ? 'success' : 'error', $sent ? 'Rechnung gesendet!' : 'Fehler beim Senden.');
    }

    public function downloadInvoice(string $id) {
        $pdf = $this->lexoffice->getInvoicePdf($id);
        if(!$pdf) return back()->with('error', 'PDF nicht verfügbar.');
        return response($pdf, 200)->header('Content-Type', 'application/pdf')->header('Content-Disposition', "attachment; filename=rechnung-$id.pdf");
    }
}
