<?php
namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Portal\PortalAccessService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Automatischer Portal-Einladungs-Batch (Betreiber-Vorgabe):
 *
 * - Jeder Kunde mit echter E-Mail wird OHNE manuellen Klick zum Portal
 *   eingeladen; neue Kunden landen automatisch in der Warteschlange.
 * - Der Bestand (~1000 nicht registrierte Kunden) wird ALPHABETISCH und im
 *   TAGESBUDGET abgearbeitet (E-Mail-Server erlaubt nur ~100/Tag) - laeuft
 *   ueber den Tag verteilt, "Buchstabe fuer Buchstabe".
 * - Wer sich nicht registriert, wird alle 7 Tage erneut eingeladen, bis zu
 *   einer maximalen Versuchszahl (Reputationsschutz).
 *
 * SICHERHEIT: laeuft NUR, wenn SystemSetting 'portal_invite_auto_enabled'
 * gesetzt ist - so loest ein Deploy nicht versehentlich 1000 Mails aus. Der
 * Betreiber schaltet den Batch bewusst frei.
 */
class SendPortalInvitations extends Command
{
    protected $signature = 'portal:send-invitations {--dry-run : Nur zaehlen, nichts senden}';
    protected $description = 'Sendet automatisch Portal-Einladungen (Tagesbudget, alphabetisch, 7-Tage-Erinnerung)';

    private const REMINDER_DAYS = 7;

    public function handle(PortalAccessService $portal): int
    {
        if (!$this->option('dry-run') && !SystemSetting::get('portal_invite_auto_enabled')) {
            $this->warn('portal_invite_auto_enabled ist AUS - Batch uebersprungen. Zum Aktivieren: SystemSetting::set(\'portal_invite_auto_enabled\', \'1\').');
            return self::SUCCESS;
        }

        $cap = max(1, (int) (SystemSetting::get('portal_invite_daily_cap') ?: 80));
        $maxAttempts = max(1, (int) (SystemSetting::get('portal_invite_max_attempts') ?: 6));

        // Bereits heute versandte Einladungen zaehlen (auch manuelle) - so
        // wird das Tagesbudget nie ueberschritten.
        $sentToday = User::whereDate('invitation_sent_at', now()->toDateString())->count();
        $remaining = $cap - $sentToday;
        if ($remaining <= 0) {
            $this->info("Tagesbudget ($cap) bereits ausgeschoepft ($sentToday heute).");
            return self::SUCCESS;
        }

        // Kandidaten: Kunden ohne Portal-Login, mit echter E-Mail, unter der
        // maximalen Versuchszahl, noch nie eingeladen ODER letzte Einladung
        // aelter als 7 Tage. Reihenfolge: nie eingeladene zuerst, dann
        // alphabetisch (Buchstabe fuer Buchstabe).
        $candidates = User::where('role', 'customer')
            ->where('is_active', true)
            ->whereNull('last_login_at')
            ->whereNotNull('email')
            ->where('email', 'not like', '%@dienstly24.internal')
            ->where('invitation_count', '<', $maxAttempts)
            ->where(function ($q) {
                $q->whereNull('invitation_sent_at')
                    ->orWhere('invitation_sent_at', '<=', now()->subDays(self::REMINDER_DAYS));
            })
            ->orderByRaw('invitation_sent_at IS NULL DESC')
            ->orderBy('name')
            ->with('customer')
            ->limit($remaining)
            ->get();

        if ($this->option('dry-run')) {
            $this->info("$remaining Slot(s) frei, {$candidates->count()} Kandidat(en) faellig (dry-run, nichts gesendet).");
            return self::SUCCESS;
        }

        $sent = 0;
        $skipped = 0;
        foreach ($candidates as $user) {
            $customer = $user->customer;
            if ($customer === null || !$user->hasRealEmail()) {
                $skipped++;
                continue;
            }
            try {
                $portal->sendInvitation($customer);
                $sent++;
            } catch (\Throwable $e) {
                $skipped++;
                Log::warning('Portal-Einladung fehlgeschlagen (' . $user->email . '): ' . $e->getMessage());
            }
        }

        if ($sent > 0) {
            ActivityLog::create([
                'user_id' => null,
                'action' => 'portal_invitations_batch',
                'entity_type' => 'user',
                'entity_id' => null,
                'meta' => json_encode(['sent' => $sent, 'skipped' => $skipped, 'cap' => $cap], JSON_UNESCAPED_UNICODE),
            ]);
        }

        $this->info("$sent Einladung(en) versendet, $skipped uebersprungen (Budget $cap, heute gesamt " . ($sentToday + $sent) . ').');
        return self::SUCCESS;
    }
}
