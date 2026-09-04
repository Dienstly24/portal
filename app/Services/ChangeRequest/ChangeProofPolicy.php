<?php

namespace App\Services\ChangeRequest;

use App\Models\CustomerChangeRequest;
use App\Models\SystemSetting;

/**
 * EINE Quelle fuer die Frage: "Welche Aenderung braucht welchen Nachweis?"
 * (Betreiber-Vorgabe 29.07.2026).
 *
 * Sensibel sind genau die Aenderungen, mit denen Missbrauch echten Schaden
 * anrichtet: Bankverbindung (Geldfluss), Adresse (Postweg/Identitaet) und
 * der Name (Identitaet). Fuer sie ist ein Nachweis PFLICHT - ohne Beleg
 * kommt der Antrag gar nicht erst ins System.
 *
 * Unkritische Aenderungen (Telefonnummer, Familienstand, Vertragsmeldung
 * ...) bleiben bewusst ohne Nachweispflicht: eine Huerde ohne Nutzen
 * wuerde nur dazu fuehren, dass Kunden ihre Daten gar nicht mehr pflegen.
 */
class ChangeProofPolicy
{
    /** Aenderungstypen mit Nachweispflicht. */
    public const SENSITIVE_TYPES = ['bank', 'address'];

    /** Profilfelder, die den Antrag nachweispflichtig machen. */
    public const SENSITIVE_PROFILE_FIELDS = [
        'first_name', 'last_name', 'birth_date',
        'address_street', 'address_house_number', 'address_house_suffix',
        'address_zip', 'address_city',
    ];

    /**
     * Braucht dieser Antrag einen Nachweis?
     *
     * @param array<string,mixed> $newData
     */
    public function requiresProof(string $type, array $newData = []): bool
    {
        if (in_array($type, self::SENSITIVE_TYPES, true)) {
            return true;
        }
        if ($type === 'profile') {
            return array_intersect(array_keys($newData), self::SENSITIVE_PROFILE_FIELDS) !== [];
        }
        return false;
    }

    /** Welche Nachweise akzeptieren wir fuer diesen Typ? (Kundentext) */
    public function acceptedProofs(string $type, array $newData = []): array
    {
        if ($type === 'bank') {
            return [
                'Foto Ihrer Bankkarte (IBAN und Name sichtbar)',
                'Kontoauszug oder Bestätigung der Bank (Kontonummer/IBAN sichtbar)',
                'Zusätzlich hilfreich: Ausweis (Vorder- und Rückseite)',
            ];
        }
        if ($type === 'address' || $this->addressFieldsChanged($newData)) {
            return [
                'Meldebescheinigung der neuen Anschrift',
                'Ausweis mit der neuen Anschrift (Vorder- und Rückseite)',
                'Alternativ: Mietvertrag oder aktuelle Meldebestätigung',
            ];
        }
        return [
            'Ausweis (Vorder- und Rückseite)',
            'Alternativ: Reisepass mit Meldebescheinigung',
        ];
    }

    /** Kurzer Pflichthinweis fuer das Portal-Formular. */
    public function hint(string $type): string
    {
        return match ($type) {
            'bank' => 'Zum Schutz vor Missbrauch benötigen wir einen Nachweis, dass das Konto Ihnen gehört.',
            'address' => 'Zum Schutz vor Missbrauch benötigen wir einen Nachweis Ihrer neuen Anschrift.',
            default => 'Zum Schutz vor Missbrauch benötigen wir einen Identitätsnachweis.',
        };
    }

    /**
     * Welche Angaben muessen im hochgeladenen Nachweis auftauchen, damit
     * die Aenderung als geprueft gilt? Liefert eine Liste von Pruefungen
     * fuer den ChangeProofVerifier.
     *
     * @return array<int, array{key: string, label: string, value: string, mode: string, required: bool}>
     */
    public function checks(CustomerChangeRequest $request): array
    {
        $data = $request->new_data ?? [];
        $checks = [];

        if ($request->type === 'bank') {
            if (! empty($data['iban'])) {
                $checks[] = ['key' => 'iban', 'label' => 'IBAN', 'value' => (string) $data['iban'], 'mode' => 'iban', 'required' => true];
            }
            if (! empty($data['account_holder'])) {
                $checks[] = ['key' => 'account_holder', 'label' => 'Kontoinhaber', 'value' => (string) $data['account_holder'], 'mode' => 'name', 'required' => false];
            }
            return $checks;
        }

        if ($request->type === 'address') {
            if (! empty($data['zip'])) {
                $checks[] = ['key' => 'zip', 'label' => 'PLZ', 'value' => (string) $data['zip'], 'mode' => 'text', 'required' => true];
            }
            if (! empty($data['city'])) {
                $checks[] = ['key' => 'city', 'label' => 'Ort', 'value' => (string) $data['city'], 'mode' => 'text', 'required' => true];
            }
            if (! empty($data['street'])) {
                $checks[] = ['key' => 'street', 'label' => 'Straße', 'value' => (string) $data['street'], 'mode' => 'street', 'required' => true];
            }
            $checks[] = $this->nameCheck($request);
            return array_values(array_filter($checks));
        }

        if ($request->type === 'profile') {
            if (! empty($data['address_zip'])) {
                $checks[] = ['key' => 'address_zip', 'label' => 'PLZ', 'value' => (string) $data['address_zip'], 'mode' => 'text', 'required' => true];
            }
            if (! empty($data['address_city'])) {
                $checks[] = ['key' => 'address_city', 'label' => 'Ort', 'value' => (string) $data['address_city'], 'mode' => 'text', 'required' => true];
            }
            if (! empty($data['address_street'])) {
                $checks[] = ['key' => 'address_street', 'label' => 'Straße', 'value' => (string) $data['address_street'], 'mode' => 'street', 'required' => true];
            }
            $name = trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? ''));
            if ($name !== '') {
                $checks[] = ['key' => 'name', 'label' => 'Name', 'value' => $name, 'mode' => 'name', 'required' => true];
            } else {
                $checks[] = $this->nameCheck($request);
            }
            if (! empty($data['birth_date'])) {
                $checks[] = ['key' => 'birth_date', 'label' => 'Geburtsdatum', 'value' => (string) $data['birth_date'], 'mode' => 'date', 'required' => false];
            }
            return array_values(array_filter($checks));
        }

        return $checks;
    }

    /**
     * Automatische Freigabe: NUR wenn der Nachweis alle Pflichtangaben
     * bestaetigt hat. Ein Treffer beweist, dass der beantragte Wert
     * wirklich auf dem Beleg steht - nicht, dass der Beleg echt ist.
     * Deshalb ist die Bankverbindung (Geldfluss) standardmaessig vom
     * Automatismus ausgenommen: dort bleibt das Vier-Augen-Prinzip.
     * Einstellbar unter Einstellungen -> Kundenaenderungen.
     */
    public const AUTO_APPROVE_MODES = [
        'off' => 'Aus – jede Änderung wird von einem Mitarbeiter freigegeben',
        'address' => 'Adresse und Name automatisch (Bankverbindung immer manuell)',
        'all' => 'Alle geprüften Änderungen automatisch (inkl. Bankverbindung)',
    ];

    public function autoApproveMode(): string
    {
        $mode = (string) SystemSetting::get('change_request_auto_approve', 'address');
        return array_key_exists($mode, self::AUTO_APPROVE_MODES) ? $mode : 'off';
    }

    /** Darf dieser Antrag nach bestandener Prüfung ohne Mitarbeiter freigegeben werden? */
    public function autoApproveAllowed(CustomerChangeRequest $request): bool
    {
        if ($request->proof_status !== 'verified') {
            return false;
        }
        // Ohne echte Pflichtprüfung (nichts Nachweisbares) nie automatisch.
        $required = array_filter($request->proofChecks(), fn ($c) => ! empty($c['required']));
        if ($required === []) {
            return false;
        }

        return match ($this->autoApproveMode()) {
            'all' => true,
            'address' => $request->type !== 'bank',
            default => false,
        };
    }

    /** Name des Kunden als (weiche) Zusatzpruefung - der Beleg soll IHM gehoeren. */
    private function nameCheck(CustomerChangeRequest $request): ?array
    {
        $name = trim((string) ($request->customer?->user?->name ?? ''));
        if ($name === '') {
            return null;
        }
        return ['key' => 'name', 'label' => 'Name', 'value' => $name, 'mode' => 'name', 'required' => false];
    }

    private function addressFieldsChanged(array $newData): bool
    {
        return array_intersect(array_keys($newData), ['address_street', 'address_zip', 'address_city', 'street', 'zip', 'city']) !== [];
    }
}
