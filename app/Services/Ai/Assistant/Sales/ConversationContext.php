<?php
namespace App\Services\Ai\Assistant\Sales;

use App\Models\AiConversation;
use App\Models\Customer;

/**
 * Der gesammelte Stand eines Gespraechs (Spezifikation Abschnitte 3, 9
 * und 14): was ist bekannt, was fehlt noch, was ist der naechste Schritt.
 *
 * Warum eine eigene Klasse: sowohl der Prompt (damit die KI nie zweimal
 * dieselbe Frage stellt) als auch die Beraterwelt (damit der Mitarbeiter
 * ohne Nachlesen weiss, wo der Kunde steht) brauchen dieselbe Sicht. Zwei
 * getrennte Berechnungen wuerden frueher oder spaeter auseinanderlaufen.
 *
 * Bekannte Angaben aus der KUNDENAKTE zaehlen als vorhanden - ein
 * Bestandskunde soll seine Anschrift nicht diktieren muessen, nur weil
 * die KI sie nicht im Chat gelesen hat.
 */
class ConversationContext
{
    public function __construct(private AiConversation $conversation, private ?Customer $customer = null)
    {
    }

    /** Alle bekannten Angaben: aus dem Chat gesammelt + aus der Akte. */
    public function known(): array
    {
        return array_merge($this->fromCustomer(), $this->conversation->collectedData());
    }

    /** Fehlende Pflichtangaben der aktuellen Stufe, in Frage-Reihenfolge. */
    public function missing(string $stage = 'bedarf'): array
    {
        $bekannt = $this->known();
        $fehlend = [];

        foreach (RequirementProfile::fieldsForStage($this->conversation->intent, $stage) as $feld) {
            if (!($feld['required'] ?? false)) {
                continue;
            }
            $wert = $bekannt[$feld['key']] ?? null;
            if ($wert === null || trim((string) $wert) === '') {
                $fehlend[] = $feld;
            }
        }

        return $fehlend;
    }

    /** Stufe, an der gerade gearbeitet wird. */
    public function stage(): string
    {
        return in_array($this->conversation->state, [
            ConversationState::CUSTOMER_ACCEPTED,
            ConversationState::COLLECTING_CONTRACT_DATA,
            ConversationState::VERIFYING_DATA,
            ConversationState::VERIFICATION_PASSED,
            ConversationState::CONTRACT_READY,
        ], true) ? 'vertrag' : 'bedarf';
    }

    /** Fortschritt "3/5" fuer Beraterwelt und Uebergabe-Zusammenfassung. */
    public function progress(string $stage = 'bedarf'): array
    {
        $pflicht = array_filter(
            RequirementProfile::fieldsForStage($this->conversation->intent, $stage),
            fn ($f) => $f['required'] ?? false
        );
        $gesamt = count($pflicht);
        $offen = count($this->missing($stage));

        return ['erledigt' => $gesamt - $offen, 'gesamt' => $gesamt];
    }

    /**
     * Kurzfassung fuer den System-Prompt.
     *
     * SICHERHEIT: sensible Felder werden NUR als "liegt vor" gemeldet -
     * nie mit Wert (Abschnitt 11). Deshalb baut diese Methode den Text
     * selbst und gibt niemals das Rohfeld weiter.
     */
    public function forPrompt(): string
    {
        $bekannt = $this->known();
        $zeilen = [];

        foreach (RequirementProfile::fields($this->conversation->intent) as $feld) {
            $key = $feld['key'];
            $vorhanden = isset($bekannt[$key]) && trim((string) $bekannt[$key]) !== '';

            if (!$vorhanden) {
                continue;
            }
            $zeilen[] = '- ' . $feld['label'] . ': '
                . (RequirementProfile::isSensitive($key) ? 'liegt vor' : (string) $bekannt[$key]);
        }

        $offen = array_map(fn ($f) => '- ' . $f['label'], $this->missing($this->stage()));

        $text = "GESPRAECHSSTAND\n";
        $text .= 'Anliegen: ' . RequirementProfile::intentLabel($this->conversation->intent) . "\n";
        $text .= 'Zustand: ' . ConversationState::label($this->conversation->state) . "\n";
        $text .= "Bereits bekannt (NICHT noch einmal fragen):\n"
            . ($zeilen === [] ? "- (nichts)\n" : implode("\n", $zeilen) . "\n");
        $text .= "Noch offen (danach fragen, hoechstens zwei Angaben je Nachricht):\n"
            . ($offen === [] ? "- (nichts)\n" : implode("\n", $offen) . "\n");

        return $text;
    }

    /** Angaben, die schon in der Kundenakte stehen. */
    private function fromCustomer(): array
    {
        if (!$this->customer) {
            return [];
        }

        $adresse = trim(implode(' ', array_filter([
            trim(($this->customer->address_street ?? '') . ' ' . ($this->customer->address_house_number ?? '')),
            trim(($this->customer->address_zip ?? '') . ' ' . ($this->customer->address_city ?? '')),
        ])));

        $name = $this->customer->loadMissing('user')->user?->name;

        return array_filter([
            'installation_address' => $adresse !== '' ? $adresse : null,
            'billing_address' => $adresse !== '' ? $adresse : null,
            'full_name' => $name,
            // Sensible Felder gelten als vorhanden, wenn sie in der Akte
            // stehen - der Wert selbst verlaesst die Akte nicht.
            'birthdate' => $this->customer->birth_date ? 'liegt vor' : null,
            'email' => $this->customer->email ?: null,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
