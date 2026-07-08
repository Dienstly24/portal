<?php
namespace App\Services;
use Illuminate\Support\Facades\Http;

class LexofficeService {
    private string $apiKey;
    private string $baseUrl = 'https://api.lexware.io/v1';

    public function __construct() {
        $this->apiKey = env('LEXOFFICE_API_KEY');
    }

    private function http() {
        return Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->retry(2, 500);
    }

    // ===== Profile =====
    public function getProfile(): array {
        $r = $this->http()->get("$this->baseUrl/profile");
        return $r->successful() ? $r->json() : [];
    }

    // ===== Contacts =====
    public function getContacts(int $page = 0, int $size = 25, string $search = ''): array {
        $params = ['page' => $page, 'size' => $size];
        if($search) $params['name'] = $search;
        $r = $this->http()->get("$this->baseUrl/contacts", $params);
        return $r->successful() ? $r->json() : ['content'=>[],'totalElements'=>0,'totalPages'=>0];
    }

    public function getContact(string $id): ?array {
        $r = $this->http()->get("$this->baseUrl/contacts/$id");
        return $r->successful() ? $r->json() : null;
    }

    public function createContact(array $data): ?array {
        $r = $this->http()->post("$this->baseUrl/contacts", $data);
        return $r->successful() ? $r->json() : null;
    }

    // ===== Invoices =====
    public function getInvoices(int $page = 0, int $size = 25, string $contactId = ''): array {
        $params = ['page' => $page, 'size' => $size, 'voucherStatus' => 'any'];
        if($contactId) $params['contactId'] = $contactId;
        $r = $this->http()->get("$this->baseUrl/invoices", $params);
        return $r->successful() ? $r->json() : ['content'=>[],'totalElements'=>0];
    }

    public function getInvoice(string $id): ?array {
        $r = $this->http()->get("$this->baseUrl/invoices/$id");
        return $r->successful() ? $r->json() : null;
    }

    public function createInvoice(array $data, bool $finalize = false): ?array {
        $url = "$this->baseUrl/invoices" . ($finalize ? '?finalize=true' : '');
        $r = $this->http()->post($url, $data);
        return $r->successful() ? $r->json() : null;
    }

    public function renderInvoicePdf(string $id): ?string {
        $r = Http::withHeaders(['Authorization' => 'Bearer ' . $this->apiKey])
            ->post("$this->baseUrl/invoices/$id/document");
        if(!$r->successful()) return null;
        $fileId = $r->json()['documentFileId'] ?? null;
        if(!$fileId) return null;
        $pdf = Http::withHeaders(['Authorization' => 'Bearer ' . $this->apiKey])
            ->get("$this->baseUrl/files/$fileId");
        return $pdf->successful() ? $pdf->body() : null;
    }

    // ===== Vouchers (Belege/Expenses) =====
    public function getVouchers(int $page = 0): array {
        $r = $this->http()->get("$this->baseUrl/voucherlist", [
            'voucherType' => 'purchaseinvoice,creditnote',
            'voucherStatus' => 'any',
            'page' => $page,
            'size' => 25,
        ]);
        return $r->successful() ? $r->json() : ['content'=>[],'totalElements'=>0];
    }

    public function uploadVoucher(string $filePath, string $fileName): ?string {
        $r = Http::withHeaders(['Authorization' => 'Bearer ' . $this->apiKey])
            ->attach('file', file_get_contents($filePath), $fileName)
            ->post("$this->baseUrl/files", ['type' => 'voucher']);
        return $r->successful() ? ($r->json()['id'] ?? null) : null;
    }

    // ===== Financial Overview =====
    public function getFinancialSummary(): array {
        // جلب الفواتير المفتوحة
        $openInvoices = $this->http()->get("$this->baseUrl/invoices", [
            'voucherStatus' => 'open', 'size' => 100
        ]);
        $overdueInvoices = $this->http()->get("$this->baseUrl/invoices", [
            'voucherStatus' => 'overdue', 'size' => 100
        ]);
        $paidInvoices = $this->http()->get("$this->baseUrl/invoices", [
            'voucherStatus' => 'paid', 'size' => 100
        ]);

        $openData = $openInvoices->successful() ? $openInvoices->json() : [];
        $overdueData = $overdueInvoices->successful() ? $overdueInvoices->json() : [];
        $paidData = $paidInvoices->successful() ? $paidInvoices->json() : [];

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
