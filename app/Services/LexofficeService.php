<?php
namespace App\Services;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class LexofficeService {
    private string $apiKey;
    private string $baseUrl = 'https://api.lexware.io/v1';

    public function __construct() {
        // env() liefert nach 'php artisan config:cache' null - stattdessen
        // Einstellung aus der DB mit Fallback auf die Config. (Audit M6)
        $this->apiKey = (string) (\App\Models\SystemSetting::get('lexoffice_api_key')
            ?: config('services.lexoffice.key', ''));
    }

    private function http() {
        // throw: false - sonst wirft retry() nach dem letzten Fehlversuch eine
        // RequestException, bevor die Aufrufer $r->successful() pruefen koennen.
        // Folge waere HTTP 500 auf jeder Lexoffice-Seite bei ungueltigem Key
        // oder API-Ausfall statt des vorgesehenen leeren Fallbacks (Audit INT-3).
        return $this->baseHttp()->withHeaders([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->retry(2, 500, throw: false);
    }

    /**
     * Gemeinsame Grundlage ALLER Lexoffice-Aufrufe: Zugang plus - der
     * eigentliche Punkt - ein ausdrueckliches Zeitlimit.
     *
     * Ohne das wartet Laravel 30 s je Versuch (mit retry(2) bis zu 90 s).
     * Solange sitzt ein Mitarbeiter vor einer haengenden Seite und ein
     * PHP-Prozess ist blockiert; bei genug parallelen Aufrufen nimmt ein
     * Ausfall der Buchhaltungs-API die ganze Beraterwelt mit. Lieber nach
     * wenigen Sekunden ehrlich aufgeben - die Aufrufer haben bereits einen
     * Fallback fuer "nicht erreichbar".
     */
    private function baseHttp() {
        return Http::withToken($this->apiKey)
            ->timeout((int) config('services.lexoffice.timeout', 10))
            ->connectTimeout((int) config('services.lexoffice.connect_timeout', 5));
    }

    /**
     * Fuehrt einen HTTP-Aufruf aus und faengt VERBINDUNGS-Fehler ab (Audit INT-5).
     * throw:false unterdrueckt nur HTTP-Status-Fehler (4xx/5xx); ist der Host
     * gar nicht erreichbar (DNS/Timeout/Connection refused, in Produktion bei
     * Lexoffice-Ausfall real moeglich), wirft der Client eine ConnectionException.
     * Ohne diesen Guard wuerde daraus HTTP 500 auf jeder Lexoffice-Seite und auf
     * dem Geld-Pfad (Provisions-/Rechnungsbuchung). null = Aufruf nicht moeglich;
     * die Aufrufer liefern dann ihren vorgesehenen leeren Fallback.
     */
    private function attempt(callable $call): ?Response {
        try {
            return $call();
        } catch (\Throwable $e) {
            Log::warning('Lexoffice-Verbindung fehlgeschlagen: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Zentrales Fehler-Logging fuer fehlgeschlagene Lexoffice-Aufrufe (Audit ARCH-3).
     * Bisher wurde jeder Fehler still zu []/null - Ops hatte keine Sichtbarkeit,
     * gerade auf dem Geld-Pfad (Provisions-/Rechnungsbuchung).
     */
    private function logFailure(string $operation, $response): void {
        Log::warning('Lexoffice-Aufruf fehlgeschlagen: ' . $operation, [
            'status' => is_object($response) && method_exists($response, 'status') ? $response->status() : null,
            'body' => is_object($response) && method_exists($response, 'json') ? $response->json('message', $response->body()) : null,
        ]);
    }

    // ===== Profile =====
    public function getProfile(): array {
        $r = $this->attempt(fn() => $this->http()->get("$this->baseUrl/profile"));
        return $r && $r->successful() ? $r->json() : [];
    }

    // ===== Contacts =====
    public function getContacts(int $page = 0, int $size = 25, string $search = ''): array {
        $params = ['page' => $page, 'size' => $size];
        if($search) $params['name'] = $search;
        $r = $this->attempt(fn() => $this->http()->get("$this->baseUrl/contacts", $params));
        return $r && $r->successful() ? $r->json() : ['content'=>[],'totalElements'=>0,'totalPages'=>0];
    }

    public function getContact(string $id): ?array {
        $r = $this->attempt(fn() => $this->http()->get("$this->baseUrl/contacts/$id"));
        return $r && $r->successful() ? $r->json() : null;
    }

    public function createContact(array $data): ?array {
        $r = $this->attempt(fn() => $this->http()->post("$this->baseUrl/contacts", $data));
        return $r && $r->successful() ? $r->json() : null;
    }

    // ===== Invoices =====
    public function getInvoices(int $page = 0, int $size = 25, string $contactId = ''): array {
        $params = ['page' => $page, 'size' => $size, 'voucherStatus' => 'any'];
        if($contactId) $params['contactId'] = $contactId;
        $r = $this->attempt(fn() => $this->http()->get("$this->baseUrl/invoices", $params));
        return $r && $r->successful() ? $r->json() : ['content'=>[],'totalElements'=>0];
    }

    public function getInvoice(string $id): ?array {
        $r = $this->attempt(fn() => $this->http()->get("$this->baseUrl/invoices/$id"));
        if($r && $r->successful()) return $r->json();
        if($r) $this->logFailure("getInvoice($id)", $r);
        return null;
    }

    public function createInvoice(array $data, bool $finalize = false): ?array {
        $url = "$this->baseUrl/invoices" . ($finalize ? '?finalize=true' : '');
        $r = $this->attempt(fn() => $this->http()->post($url, $data));
        if($r && $r->successful()) return $r->json();
        if($r) $this->logFailure('createInvoice', $r);
        return null;
    }

    public function renderInvoicePdf(string $id): ?string {
        $r = $this->attempt(fn() => $this->baseHttp()->post("$this->baseUrl/invoices/$id/document"));
        if(!$r || !$r->successful()) { if($r) $this->logFailure("renderInvoicePdf.document($id)", $r); return null; }
        $fileId = $r->json()['documentFileId'] ?? null;
        if(!$fileId) return null;
        $pdf = $this->attempt(fn() => $this->baseHttp()->get("$this->baseUrl/files/$fileId"));
        if(!$pdf || !$pdf->successful()) { if($pdf) $this->logFailure("renderInvoicePdf.file($fileId)", $pdf); return null; }
        return $pdf->body();
    }

    /**
     * Rechnungs-PDF holen. Frueher rief der Controller diese Methode auf,
     * obwohl sie nicht existierte -> HTTP 500 (Audit INT-2). Alias auf
     * renderInvoicePdf, das Dokument-Rendering + File-Download kapselt.
     */
    public function getInvoicePdf(string $id): ?string {
        return $this->renderInvoicePdf($id);
    }

    /**
     * Rechnung als PDF an eine E-Mail-Adresse senden. Die Lexoffice-v1-API
     * bietet keinen direkten "per E-Mail versenden"-Endpunkt; wir rendern das
     * PDF und versenden es ueber den eigenen Mailer. Frueher fehlte die Methode
     * komplett -> HTTP 500 (Audit INT-2).
     */
    public function sendInvoice(string $id, string $email): bool {
        $pdf = $this->renderInvoicePdf($id);
        if(!$pdf) return false;
        try {
            Mail::raw('Im Anhang finden Sie Ihre Rechnung.', function ($m) use ($email, $id, $pdf) {
                $m->to($email)
                  ->subject('Ihre Rechnung')
                  ->attachData($pdf, "rechnung-$id.pdf", ['mime' => 'application/pdf']);
            });
            return true;
        } catch (\Throwable $e) {
            Log::warning('Lexoffice-Rechnungsversand fehlgeschlagen: ' . $e->getMessage(), ['invoice' => $id]);
            return false;
        }
    }

    // ===== Vouchers (Belege/Expenses) =====
    public function getVouchers(int $page = 0): array {
        $r = $this->attempt(fn() => $this->http()->get("$this->baseUrl/voucherlist", [
            'voucherType' => 'purchaseinvoice,creditnote',
            'voucherStatus' => 'any',
            'page' => $page,
            'size' => 25,
        ]));
        return $r && $r->successful() ? $r->json() : ['content'=>[],'totalElements'=>0];
    }

    /**
     * Buchhaltungsbeleg (z. B. Provisionsgutschrift = Einnahme) anlegen.
     * Wird ausschließlich aus der Mitarbeiter-Buchung im
     * CommissionController aufgerufen - nie automatisch (HITL Abschnitt 13).
     */
    public function createVoucher(array $data): ?array {
        $r = $this->attempt(fn() => $this->http()->post("$this->baseUrl/vouchers", $data));
        if($r && $r->successful()) return $r->json();
        if($r) $this->logFailure('createVoucher', $r);
        return null;
    }

    public function uploadVoucher(string $filePath, string $fileName): ?string {
        // Guard gegen fehlende/unlesbare Datei (Audit ARCH-3) - frueher warf
        // file_get_contents() eine Warning und lieferte false in den Upload.
        if(!is_file($filePath) || !is_readable($filePath)) {
            Log::warning('Lexoffice uploadVoucher: Datei nicht lesbar', ['path' => $filePath]);
            return null;
        }
        $r = $this->attempt(fn() => $this->baseHttp()
            ->attach('file', file_get_contents($filePath), $fileName)
            ->post("$this->baseUrl/files", ['type' => 'voucher']));
        if($r && $r->successful()) return $r->json()['id'] ?? null;
        if($r) $this->logFailure('uploadVoucher', $r);
        return null;
    }

    // ===== Financial Overview =====
    public function getFinancialSummary(): array {
        // Offene Rechnungen holen
        $openInvoices = $this->attempt(fn() => $this->http()->get("$this->baseUrl/invoices", [
            'voucherStatus' => 'open', 'size' => 100
        ]));
        $overdueInvoices = $this->attempt(fn() => $this->http()->get("$this->baseUrl/invoices", [
            'voucherStatus' => 'overdue', 'size' => 100
        ]));
        $paidInvoices = $this->attempt(fn() => $this->http()->get("$this->baseUrl/invoices", [
            'voucherStatus' => 'paid', 'size' => 100
        ]));

        $openData = $openInvoices && $openInvoices->successful() ? $openInvoices->json() : [];
        $overdueData = $overdueInvoices && $overdueInvoices->successful() ? $overdueInvoices->json() : [];
        $paidData = $paidInvoices && $paidInvoices->successful() ? $paidInvoices->json() : [];

        $calcTotal = function($data) {
            $total = 0;
            foreach($data['content'] ?? [] as $inv) {
                $total += $inv['totalPrice']['totalGrossAmount'] ?? 0;
            }
            return $total;
        };

        return [
            'open_count' => $openData['totalElements'] ?? 0,
            'open_amount' => $calcTotal($openData),
            'overdue_count' => $overdueData['totalElements'] ?? 0,
            'overdue_amount' => $calcTotal($overdueData),
            'paid_count' => $paidData['totalElements'] ?? 0,
            'paid_amount' => $calcTotal($paidData),
            'total_invoices' => ($openData['totalElements'] ?? 0) + ($overdueData['totalElements'] ?? 0) + ($paidData['totalElements'] ?? 0),
        ];
    }
}
