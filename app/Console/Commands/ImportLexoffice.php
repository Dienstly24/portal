<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use App\Models\User;
use App\Models\Customer;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;

class ImportLexoffice extends Command
{
    protected $signature = 'lexoffice:import';
    protected $description = 'Import all contacts from lexoffice';

    public function handle()
    {
        $apiKey = \App\Models\SystemSetting::get('lexoffice_api_key') ?: config('services.lexoffice.key');
        $baseUrl = 'https://api.lexware.io/v1';
        $imported = 0;
        $skipped = 0;
        $page = 0;
        $totalPages = 999;

        $this->info('=== Import gestartet ===');

        while ($page < $totalPages) {
            $r = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Accept' => 'application/json',
            ])->get("$baseUrl/contacts", ['page' => $page, 'size' => 100]);

            if (!$r->successful()) {
                $this->error('API Fehler: ' . $r->status());
                break;
            }

            $data = $r->json();
            $totalPages = $data['totalPages'] ?? 1;
            $contacts = $data['content'] ?? [];

            $this->info("Seite " . ($page+1) . "/$totalPages (" . count($contacts) . " Kontakte)");

            foreach ($contacts as $c) {
                $isCompany = isset($c['company']['name']);
                $name = $isCompany
                    ? $c['company']['name']
                    : trim(($c['person']['firstName'] ?? '') . ' ' . ($c['person']['lastName'] ?? ''));

                if (empty(trim($name))) { $skipped++; continue; }

                $email = $c['emailAddresses']['business'][0]
                    ?? $c['emailAddresses']['private'][0]
                    ?? $c['emailAddresses']['other'][0]
                    ?? null;

                if (!$email) {
                    $slug = substr(preg_replace('/[^a-z0-9]/', '', strtolower($name)), 0, 20);
                    $email = 'import_' . $slug . '_' . substr(md5($c['id']), 0, 6) . '@dienstly24.internal';
                }

                if (User::where('email', $email)->exists()) { $skipped++; continue; }

                $phone = $c['phoneNumbers']['business'][0]
                    ?? $c['phoneNumbers']['mobile'][0]
                    ?? $c['phoneNumbers']['private'][0]
                    ?? null;

                $address = '';
                $addr = $c['addresses']['billing'][0] ?? $c['addresses']['shipping'][0] ?? null;
                if ($addr) {
                    $address = trim(
                        trim($addr['street'] ?? '') . ', ' .
                        trim($addr['zip'] ?? '') . ' ' .
                        trim($addr['city'] ?? ''),
                    ', ');
                }

                $birthDate = null;
                if (!empty($c['note']) && preg_match('/(\d{2}\.\d{2}\.\d{4})/', $c['note'], $m)) {
                    try {
                        $birthDate = \Carbon\Carbon::createFromFormat('d.m.Y', $m[1])->format('Y-m-d');
                    } catch(\Exception $e) {}
                }

                $tags = !empty($c['tags']) ? implode(', ', $c['tags']) : null;

                try {
                    $user = User::create([
                        'name' => $name,
                        'email' => $email,
                        'password' => bcrypt(Str::random(12)),
                        'role' => 'customer',
                    ]);

                    Customer::create([
                        'id' => Str::uuid(),
                        'user_id' => $user->id,
                        'customer_number' => 'LEX-' . ($c['roles']['customer']['number'] ?? strtoupper(Str::random(6))),
                        'phone' => $phone,
                        'address' => $address ?: null,
                        'birth_date' => $birthDate,
                        'company_name' => $isCompany ? $name : null,
                        'customer_type' => $isCompany ? 'firma' : 'privat',
                        'preferred_lang' => 'de',
                        'marital_status' => $tags,
                    ]);

                    $imported++;
                    if ($imported % 50 === 0) {
                        $this->info("  ✓ $imported importiert...");
                    }

                } catch (\Exception $e) {
                    $skipped++;
                }

                usleep(100000);
            }
            $page++;
        }

        $this->info("\n=== Fertig ===");
        $this->info("✓ Importiert: $imported");
        $this->info("⊘ Übersprungen: $skipped");
        return 0;
    }
}
