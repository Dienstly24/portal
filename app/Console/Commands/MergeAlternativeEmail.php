<?php
namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Portal\PortalAccessService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Alternativ-E-Mail zur Haupt-/Login-E-Mail machen (Betreiber-Vorgabe):
 *
 * Viele Kunden haben ihre E-Mail versehentlich im Feld "E-Mail 2 (Alternativ)"
 * (customers.email2) statt in der Login-E-Mail (users.email) stehen. Dieser
 * Befehl verschiebt fuer jeden betroffenen Kunden die Alternativ-Adresse in die
 * Haupt-/Login-E-Mail, leert das Alternativ-Feld und laedt den Kunden zum
 * Portal ein (Willkommens-Mail).
 *
 * SICHERHEITSREGELN:
 * - Eine bereits vorhandene ECHTE Login-E-Mail wird NIE ueberschrieben
 *   (Aenderung betraefe den Portal-Zugang). Solche Faelle werden nur gezaehlt
 *   und im Bericht als Konflikt ausgewiesen.
 * - Ist die Alternativ-Adresse identisch mit der bestehenden Login-E-Mail,
 *   wird nur das doppelte Alternativ-Feld geleert (keine neue Einladung).
 * - Ist die Adresse schon bei einem ANDEREN Nutzer als Login vergeben, wird der
 *   Datensatz uebersprungen (Unique-Schutz).
 * - Einladungen respektieren das Tages-Budget (portal_invite_daily_cap, Default
 *   80) analog zu SendPortalInvitations - der Rest wird verschoben und vom
 *   regulaeren Einladungs-Batch nachgeholt. So bleibt die Absender-Reputation
 *   geschuetzt (E-Mail-Server erlaubt nur ~100/Tag).
 */
class MergeAlternativeEmail extends Command
{
    protected $signature = 'customers:merge-alternative-email {--dry-run : Nur zaehlen/anzeigen, nichts aendern und nichts senden}';
    protected $description = 'Verschiebt die Alternativ-E-Mail (email2) in die Haupt-/Login-E-Mail, leert email2 und laedt den Kunden ein (Tages-Budget)';

    public function handle(PortalAccessService $portal): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Einladungs-Budget fuer heute (analog SendPortalInvitations).
        $cap = max(1, (int) (SystemSetting::get('portal_invite_daily_cap') ?: 80));
        $sentToday = User::whereDate('invitation_sent_at', now()->toDateString())->count();
        $budget = max(0, $cap - $sentToday);

        $stats = [
            'scanned'    => 0, // Kunden mit gefuelltem email2
            'migrated'   => 0, // email2 -> Login-E-Mail verschoben
            'invited'    => 0, // Willkommens-Mail heute verschickt
            'deferred'   => 0, // verschoben, Einladung wegen Budget vertagt
            'duplicate'  => 0, // email2 == Login-E-Mail -> nur bereinigt
            'conflict'   => 0, // andere echte Login-E-Mail vorhanden -> nicht angetastet
            'taken'      => 0, // email2 schon bei anderem Nutzer als Login vergeben
            'invalid'    => 0, // email2 keine gueltige Adresse
            'no_user'    => 0, // kein Benutzer-Datensatz
            'send_error' => 0, // verschoben, aber Einladung fehlgeschlagen
        ];

        // Kandidaten: Kunden mit gefuelltem Alternativ-Feld.
        $candidates = Customer::query()
            ->whereNotNull('email2')
            ->where('email2', '!=', '')
            ->with('user')
            ->orderBy('customer_number')
            ->get();

        foreach ($candidates as $customer) {
            $email2 = trim((string) $customer->email2);
            if ($email2 === '') {
                continue;
            }
            $stats['scanned']++;

            if (!filter_var($email2, FILTER_VALIDATE_EMAIL)) {
                $stats['invalid']++;
                $this->line("  [ungueltig]  {$customer->customer_number}: '{$email2}'");
                continue;
            }

            $user = $customer->user;
            if ($user === null) {
                $stats['no_user']++;
                $this->line("  [kein User]  {$customer->customer_number}: {$email2}");
                continue;
            }

            // Bereits eine echte Login-E-Mail vorhanden?
            if ($user->hasRealEmail()) {
                if (mb_strtolower($user->email) === mb_strtolower($email2)) {
                    // Alternativ dupliziert nur die Login-E-Mail -> Feld leeren.
                    $stats['duplicate']++;
                    if (!$dryRun) {
                        $customer->forceFill(['email2' => null])->save();
                    }
                    $this->line("  [duplikat]   {$customer->customer_number}: {$email2} (email2 geleert)");
                } else {
                    // Andere echte Login-E-Mail -> NICHT anfassen (Portal-Zugang).
                    $stats['conflict']++;
                    $this->line("  [konflikt]   {$customer->customer_number}: Login={$user->email} vs. Alt={$email2} (uebersprungen)");
                }
                continue;
            }

            // Adresse schon bei einem anderen Nutzer als Login vergeben?
            $taken = User::whereRaw('LOWER(email) = ?', [mb_strtolower($email2)])
                ->where('id', '!=', $user->id)
                ->exists();
            if ($taken) {
                $stats['taken']++;
                $this->line("  [vergeben]   {$customer->customer_number}: {$email2} (bei anderem Nutzer als Login)");
                continue;
            }

            // --- Verschieben: email2 -> Login-E-Mail, email2 leeren ---
            if ($dryRun) {
                $stats['migrated']++;
                if ($budget > 0) {
                    $stats['invited']++;
                    $budget--;
                    $this->line("  [WUERDE]     {$customer->customer_number}: {$email2} -> Login + Einladung");
                } else {
                    $stats['deferred']++;
                    $this->line("  [WUERDE]     {$customer->customer_number}: {$email2} -> Login (Einladung vertagt, Budget 0)");
                }
                continue;
            }

            $user->forceFill(['email' => $email2])->save();
            $customer->forceFill(['email2' => null])->save();
            $customer->setRelation('user', $user);
            $stats['migrated']++;

            ActivityLog::create([
                'user_id'     => null,
                'action'      => 'alternative_email_promoted',
                'entity_type' => 'customer',
                'entity_id'   => $customer->id,
                'meta'        => json_encode(['email' => $email2], JSON_UNESCAPED_UNICODE),
            ]);

            // Einladung nur im Tages-Budget verschicken.
            if ($budget > 0) {
                try {
                    $portal->sendInvitation($customer);
                    $stats['invited']++;
                    $budget--;
                    $this->line("  [OK]         {$customer->customer_number}: {$email2} -> Login + Einladung gesendet");
                } catch (\Throwable $e) {
                    $stats['send_error']++;
                    Log::warning('Einladung nach Alternativ-Merge fehlgeschlagen (' . $email2 . '): ' . $e->getMessage());
                    $this->line("  [OK/Mail-Fehler] {$customer->customer_number}: {$email2} -> Login (Einladung fehlgeschlagen)");
                }
            } else {
                $stats['deferred']++;
                $this->line("  [OK]         {$customer->customer_number}: {$email2} -> Login (Einladung vertagt, Budget 0)");
            }
        }

        if (!$dryRun && $stats['migrated'] > 0) {
            ActivityLog::create([
                'user_id'     => null,
                'action'      => 'alternative_email_merge_batch',
                'entity_type' => 'customer',
                'entity_id'   => null,
                'meta'        => json_encode($stats, JSON_UNESCAPED_UNICODE),
            ]);
        }

        $this->newLine();
        $this->info($dryRun ? '=== BERICHT (dry-run, nichts geaendert) ===' : '=== BERICHT ===');
        $this->table(
            ['Kennzahl', 'Anzahl'],
            [
                ['Kunden mit Alternativ-E-Mail geprueft', $stats['scanned']],
                ['-> in Login-E-Mail verschoben',         $stats['migrated']],
                ['   davon Einladung gesendet',           $stats['invited']],
                ['   davon Einladung vertagt (Budget)',   $stats['deferred']],
                ['   davon Einladung fehlgeschlagen',     $stats['send_error']],
                ['Duplikat (email2 == Login) bereinigt',  $stats['duplicate']],
                ['Konflikt (andere Login-Mail) skip',     $stats['conflict']],
                ['Adresse bei anderem Nutzer vergeben',   $stats['taken']],
                ['Ungueltige Alternativ-Adresse',         $stats['invalid']],
                ['Kein Benutzer-Datensatz',               $stats['no_user']],
            ]
        );

        if ($stats['deferred'] > 0) {
            $this->warn("Hinweis: {$stats['deferred']} Einladung(en) wegen Tages-Budget ({$cap}) vertagt - der Batch 'portal:send-invitations' holt sie an den Folgetagen nach.");
        }

        return self::SUCCESS;
    }
}
