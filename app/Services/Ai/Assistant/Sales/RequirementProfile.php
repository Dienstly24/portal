<?php
namespace App\Services\Ai\Assistant\Sales;

/**
 * Welche Angaben braucht ein Anliegen? (Spezifikation Abschnitte 3 und 9)
 *
 * Der Assistent soll fragen wie ein Mitarbeiter: zielgerichtet, in
 * sinnvoller Reihenfolge, nie doppelt und nie nach Dingen, die er gar
 * nicht braucht. Dafuer gibt es je Absicht ein Profil.
 *
 * Aufbau eines Feldes:
 *   key        interner Name (steht auch im Protokoll)
 *   label      Beschriftung fuer Mitarbeiter und Prompt
 *   required   Pflichtangabe fuer diese Stufe
 *   sensitive  Wert darf NIE an das Modell (siehe SlotExtractor)
 *   stage      'bedarf' = vor dem Angebot, 'vertrag' = nach der Zusage
 */
final class RequirementProfile
{
    public const INTENT_NEW_INTERNET = 'NEW_INTERNET';
    public const INTENT_CONTRACT_CHANGE = 'CONTRACT_CHANGE';
    public const INTENT_UPGRADE = 'UPGRADE';
    public const INTENT_GENERAL_QUESTION = 'GENERAL_QUESTION';
    public const INTENT_TECHNICAL_SUPPORT = 'TECHNICAL_SUPPORT';
    public const INTENT_HUMAN_REQUIRED = 'HUMAN_REQUIRED';

    public const INTENT_LABELS = [
        self::INTENT_NEW_INTERNET => 'Neuer Internetanschluss',
        self::INTENT_CONTRACT_CHANGE => 'Vertragswechsel',
        self::INTENT_UPGRADE => 'Vertrag aufwerten',
        self::INTENT_GENERAL_QUESTION => 'Allgemeine Frage',
        self::INTENT_TECHNICAL_SUPPORT => 'Technische Stoerung',
        self::INTENT_HUMAN_REQUIRED => 'Mitarbeiter gewuenscht',
    ];

    /** Anliegen, die ueberhaupt einen Verkaufsvorgang ausloesen. */
    public const SALES_INTENTS = [
        self::INTENT_NEW_INTERNET,
        self::INTENT_CONTRACT_CHANGE,
        self::INTENT_UPGRADE,
    ];

    /**
     * Angaben je Anliegen. Bewusst knapp gehalten: jede zusaetzliche Frage
     * kostet einen Gespraechsschritt und damit Abschluesse.
     */
    private const FIELDS = [
        self::INTENT_NEW_INTERNET => [
            ['key' => 'installation_address', 'label' => 'Vollstaendige Anschlussadresse', 'required' => true, 'stage' => 'bedarf'],
            ['key' => 'situation', 'label' => 'Umzug oder bestehender Anschluss', 'required' => true, 'stage' => 'bedarf'],
            ['key' => 'current_provider', 'label' => 'Aktueller Anbieter', 'required' => false, 'stage' => 'bedarf'],
            ['key' => 'desired_speed', 'label' => 'Gewuenschte Geschwindigkeit', 'required' => false, 'stage' => 'bedarf'],
            ['key' => 'desired_start', 'label' => 'Gewuenschter Starttermin', 'required' => false, 'stage' => 'bedarf'],
        ],
        self::INTENT_CONTRACT_CHANGE => [
            ['key' => 'current_provider', 'label' => 'Aktueller Anbieter', 'required' => true, 'stage' => 'bedarf'],
            ['key' => 'current_tariff', 'label' => 'Aktueller Tarif', 'required' => false, 'stage' => 'bedarf'],
            ['key' => 'contract_end', 'label' => 'Laufzeitende oder Kuendigungsfrist', 'required' => false, 'stage' => 'bedarf'],
            ['key' => 'installation_address', 'label' => 'Anschlussadresse', 'required' => true, 'stage' => 'bedarf'],
            ['key' => 'change_reason', 'label' => 'Grund fuer den Wechsel', 'required' => false, 'stage' => 'bedarf'],
        ],
        self::INTENT_UPGRADE => [
            ['key' => 'current_tariff', 'label' => 'Aktueller Tarif', 'required' => true, 'stage' => 'bedarf'],
            ['key' => 'desired_speed', 'label' => 'Gewuenschte Geschwindigkeit', 'required' => true, 'stage' => 'bedarf'],
            ['key' => 'installation_address', 'label' => 'Anschlussadresse', 'required' => false, 'stage' => 'bedarf'],
        ],
    ];

    /**
     * Vertragsdaten nach der Zusage (Abschnitt 9). Gilt fuer alle
     * Verkaufs-Anliegen gleich.
     *
     * SENSIBEL heisst: der Wert wird serverseitig erfasst und
     * verschluesselt gespeichert, erreicht aber NIE das Modell - es sieht
     * nur "liegt vor" (Abschnitt 11).
     */
    private const CONTRACT_FIELDS = [
        ['key' => 'full_name', 'label' => 'Vollstaendiger Name', 'required' => true, 'stage' => 'vertrag'],
        ['key' => 'email', 'label' => 'E-Mail-Adresse', 'required' => true, 'stage' => 'vertrag', 'sensitive' => true],
        ['key' => 'birthdate', 'label' => 'Geburtsdatum', 'required' => true, 'stage' => 'vertrag', 'sensitive' => true],
        ['key' => 'billing_address', 'label' => 'Rechnungsanschrift', 'required' => false, 'stage' => 'vertrag'],
        ['key' => 'phone', 'label' => 'Telefonnummer', 'required' => false, 'stage' => 'vertrag', 'sensitive' => true],
        ['key' => 'iban', 'label' => 'IBAN fuer das SEPA-Lastschriftmandat', 'required' => true, 'stage' => 'vertrag', 'sensitive' => true],
    ];

    /** Alle Felder eines Anliegens (Bedarf + Vertrag). */
    public static function fields(?string $intent): array
    {
        $bedarf = self::FIELDS[$intent] ?? [];
        if (!in_array($intent, self::SALES_INTENTS, true)) {
            return $bedarf;
        }

        return array_merge($bedarf, self::CONTRACT_FIELDS);
    }

    /** Felder einer Stufe: 'bedarf' vor dem Angebot, 'vertrag' danach. */
    public static function fieldsForStage(?string $intent, string $stage): array
    {
        return array_values(array_filter(
            self::fields($intent),
            fn ($f) => ($f['stage'] ?? 'bedarf') === $stage
        ));
    }

    /** Ist dieser Feldwert sensibel (darf nie an das Modell)? */
    public static function isSensitive(string $key): bool
    {
        foreach (self::CONTRACT_FIELDS as $field) {
            if ($field['key'] === $key) {
                return (bool) ($field['sensitive'] ?? false);
            }
        }

        return false;
    }

    public static function label(string $key): string
    {
        $alle = array_merge(array_merge(...array_values(self::FIELDS)), self::CONTRACT_FIELDS);
        foreach ($alle as $field) {
            if ($field['key'] === $key) {
                return $field['label'];
            }
        }

        return $key;
    }

    /** Kennt das Profil dieses Feld ueberhaupt? Schutz vor Fantasiefeldern. */
    public static function knows(?string $intent, string $key): bool
    {
        foreach (self::fields($intent) as $field) {
            if ($field['key'] === $key) {
                return true;
            }
        }

        return false;
    }

    public static function intentLabel(?string $intent): string
    {
        return self::INTENT_LABELS[$intent] ?? 'Unbestimmt';
    }
}
