<?php
namespace App\Http\Controllers;

use App\Mail\DirectEmailMail;
use App\Models\Customer;
use App\Models\CustomerTimeline;
use App\Models\MessageTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/**
 * E-Mail-Composer der Beraterwelt: eine E-Mail per Klick an einen Kunden
 * oder an eine Gesellschaft (freier Empfaenger) - mit Vorlagen und
 * Platzhaltern. Versand an Kunden wird in der Kunden-Historie protokolliert.
 *
 * Berechtigung: admin/manager/support immer; employee nur mit dem
 * bestehenden Rechte-Flag can_send_emails.
 */
class ComposeEmailController extends Controller
{
    private function authorizeCompose(): void
    {
        $user = auth()->user();
        abort_unless(
            in_array($user->role, ['admin', 'manager', 'support'], true) || $user->can_send_emails,
            403,
            'Keine Berechtigung zum E-Mail-Versand.'
        );
    }

    public function create(Request $request)
    {
        $this->authorizeCompose();

        $customer = null;
        if ($request->filled('customer_id')) {
            abort_unless(auth()->user()->canAccessCustomer($request->customer_id), 403);
            $customer = Customer::with('user')->findOrFail($request->customer_id);
        }

        return view('admin.compose_email', [
            'customer' => $customer,
            'templates' => MessageTemplate::orderBy('category')->orderBy('sort')->orderBy('name')
                ->get(['id', 'name', 'category']),
            'placeholders' => MessageTemplate::PLACEHOLDERS,
        ]);
    }

    public function send(Request $request)
    {
        $this->authorizeCompose();
        $request->validate([
            'to' => 'required|email|max:190',
            'subject' => 'required|string|max:200',
            'body' => 'required|string|max:10000',
            'customer_id' => 'nullable|string',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|mimes:pdf,jpg,jpeg,png,webp,doc,docx|max:10240',
        ]);

        $customer = null;
        if ($request->filled('customer_id')) {
            abort_unless(auth()->user()->canAccessCustomer($request->customer_id), 403);
            $customer = Customer::with('user')->findOrFail($request->customer_id);
        }

        $files = [];
        foreach ($request->file('attachments', []) as $file) {
            $files[] = [
                'data' => $file->get(),
                'name' => $file->getClientOriginalName(),
                'mime' => $file->getClientMimeType() ?: 'application/octet-stream',
            ];
        }

        try {
            Mail::to($request->to)->send(new DirectEmailMail(
                mailSubject: $request->subject,
                mailBody: $request->body,
                customer: $customer,
                fileAttachments: $files,
                senderName: (string) auth()->user()->name,
            ));
        } catch (\Throwable $e) {
            \Log::warning('Direkt-E-Mail fehlgeschlagen: ' . $e->getMessage());
            return back()->withInput()->with('error', 'E-Mail konnte nicht gesendet werden: ' . $e->getMessage());
        }

        // Nachvollziehbarkeit: Versand in der Kundenakte protokollieren.
        if ($customer) {
            CustomerTimeline::create([
                'customer_id' => $customer->id,
                'user_id' => auth()->id(),
                'type' => 'email',
                'title' => 'E-Mail gesendet: ' . $request->subject,
                'description' => 'An ' . $request->to
                    . ($files !== [] ? ' · ' . count($files) . ' Anhang/Anhänge' : ''),
            ]);
        }

        return redirect(route('admin.email.compose') . ($customer ? '?customer_id=' . $customer->id : ''))
            ->with('success', 'E-Mail an ' . $request->to . ' gesendet.');
    }
}
