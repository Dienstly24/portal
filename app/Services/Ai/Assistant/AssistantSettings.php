<?php
namespace App\Services\Ai\Assistant;

use App\Models\SystemSetting;

/**
 * Betriebsschalter des KI-Kundenassistenten (Spezifikation Abschnitt 30).
 *
 * Liegen als SystemSetting, damit der Betreiber sie in der Beraterwelt
 * OHNE Deploy umstellen kann - Notbremse inklusive: `enabled = 0` schaltet
 * den Assistenten sofort und vollstaendig ab.
 *
 * Grundhaltung der Voreinstellungen: der Assistent ist von sich aus AUS.
 * Er geht erst in Betrieb, wenn der Betreiber ihn nach der Testphase
 * (Abschnitt 33) bewusst einschaltet - eine Integration darf sich nicht
 * selbst live schalten.
 */
class AssistantSettings
{
    /** Schalter mit ihren Voreinstellungen (Schluessel = SystemSetting-Key). */
    public const DEFAULTS = [
        'ai_assistant_enabled' => '0',
        'ai_assistant_auto_reply' => '1',
        'ai_assistant_auto_document_request' => '1',
        'ai_assistant_auto_ticket' => '1',
        'ai_assistant_auto_handover' => '1',
        'ai_assistant_max_replies_per_case' => '10',
        'ai_assistant_auto_resume' => '1',
        'ai_assistant_resume_quiet_hours' => '24',
    ];

    /** KI-Kundenassistent insgesamt (Hauptschalter / Notbremse). */
    public function enabled(): bool
    {
        return $this->flag('ai_assistant_enabled');
    }

    /** Automatische Antworten im Portal-Chat. */
    public function autoReply(): bool
    {
        return $this->flag('ai_assistant_auto_reply');
    }

    /** Darf die KI selbst eine Dokumentenanforderung anlegen? */
    public function autoDocumentRequest(): bool
    {
        return $this->flag('ai_assistant_auto_document_request');
    }

    /** Darf die KI selbst einen Vorgang/ein Ticket anlegen? */
    public function autoTicket(): bool
    {
        return $this->flag('ai_assistant_auto_ticket');
    }

    /**
     * Automatische Uebergabe an Mitarbeiter. AUS bedeutet: kein Ticket und
     * keine Zuweisung entsteht automatisch - die Glocke an das Team geht
     * TROTZDEM raus, sonst blieben unsichere Anfragen unbemerkt liegen
     * (das waere ein Sicherheitsverlust, kein Sparen).
     */
    public function autoHandover(): bool
    {
        return $this->flag('ai_assistant_auto_handover');
    }

    /**
     * Maximale Anzahl automatischer Antworten pro Vorgang/Unterhaltung.
     * 0 = unbegrenzt (bewusste Entscheidung des Betreibers).
     */
    public function maxRepliesPerCase(): int
    {
        return max(0, (int) $this->value('ai_assistant_max_replies_per_case'));
    }

    /**
     * Darf die KI nach einer Uebernahme von selbst zurueckkommen, sobald
     * der Vorgang abgeschlossen ist bzw. die Ruhefrist abgelaufen
     * (Betreiber-Vorgabe 20.08.2026)? AUS bedeutet: jede Uebernahme wirkt
     * dauerhaft, die KI kehrt nur per Knopf zurueck (Stand bis 19.08.2026).
     */
    public function autoResume(): bool
    {
        return $this->flag('ai_assistant_auto_resume');
    }

    /**
     * Ruhefrist in Stunden: so lange nach der letzten Mitarbeiter-Nachricht
     * bleibt der Mensch zustaendig. Mindestens 1 Stunde - eine Frist von 0
     * hiesse, die KI faellt dem Kollegen mitten im Gespraech ins Wort.
     */
    public function resumeQuietHours(): int
    {
        return max(1, (int) $this->value('ai_assistant_resume_quiet_hours'));
    }

    /** Aktueller Stand aller Schalter (fuer die Einstellungen-Seite). */
    public function all(): array
    {
        $values = [];
        foreach (array_keys(self::DEFAULTS) as $key) {
            $values[$key] = $this->value($key);
        }

        return $values;
    }

    private function flag(string $key): bool
    {
        return (string) $this->value($key) === '1';
    }

    private function value(string $key): string
    {
        return (string) SystemSetting::get($key, self::DEFAULTS[$key] ?? '');
    }
}
