<?php
namespace App\Services\ChangeRequest;

use App\Models\ChangeNotification;
use App\Models\Contract;
use App\Models\CustomerChangeRequest;
use App\Models\SystemSetting;
use Illuminate\Support\Str;

/**
 * Bereitet nach der Freigabe einer sensiblen Aenderung die Mitteilungen an
 * die Gesellschaften des Kunden vor (Betreiber-Vorgabe 29.07.2026).
 *
 * Bisher musste ein Mitarbeiter nach jeder Adress-/Bankaenderung selbst
 * daran denken, jede Krankenkasse, KFZ-Versicherung usw. zu informieren -
 * und den Text jedes Mal neu schreiben. Jetzt entsteht je Gesellschaft
 * EIN fertiger Entwurf (alle betroffenen Vertragsnummern gebuendelt), den
 * der Mitarbeiter nur noch prueft und mit dem Nachweis als Anhang
 * versendet.
 *
 * Bewusst NUR ein Entwurf: nach aussen geht nie eine automatische E-Mail.
 */
class InsurerNotificationBuilder
{
    /** Vertraege in diesen Status werden informiert (laufend/in Bearbeitung). */
    private const RELEVANT_STATUS = ['active', 'pending'];

    /**
     * Legt die Entwuerfe an - je Gesellschaft genau einen. Bereits
     * vorhandene Entwuerfe desselben Antrags werden nicht verdoppelt.
     *
     * @return int Anzahl neu erstellter Mitteilungen
     */
    public function prepare(CustomerChangeRequest $request): int
    {
        if (!$this->isRelevant($request)) {
            return 0;
        }

        $customer = $request->customer;
        if (!$customer) {
            return 0;
        }

        $contracts = Contract::where('customer_id', $customer->id)
            ->whereIn('status', self::RELEVANT_STATUS)
            ->get()
            ->filter(fn(Contract $c) => trim((string) $c->insurer) !== '')
            ->groupBy(fn(Contract $c) => Str::lower(trim((string) $c->insurer)));

        $created = 0;
        foreach ($contracts as $group) {
            $insurer = trim((string) $group->first()->insurer);
            $numbers = $group->pluck('contract_number')->filter()->implode(', ');

            $exists = ChangeNotification::where('change_request_id', $request->id)
                ->where('insurer', $insurer)->exists();
            if ($exists) {
                continue;
            }

            ChangeNotification::create([
                'change_request_id' => $request->id,
                'customer_id' => $customer->id,
                'insurer' => $insurer,
                'contract_numbers' => $numbers !== '' ? Str::limit($numbers, 240, '') : null,
                'subject' => $this->subject($request, $numbers),
                'body' => $this->body($request, $insurer, $numbers),
                'status' => 'pending',
            ]);
            $created++;
        }

        return $created;
    }

    /** Nur Aenderungen, die eine Gesellschaft wirklich betreffen. */
    public function isRelevant(CustomerChangeRequest $request): bool
    {
        if (in_array($request->type, ['bank', 'address'], true)) {
            return true;
        }
        if ($request->type !== 'profile') {
            return false;
        }
        return array_intersect(
            array_keys($request->new_data ?? []),
            ChangeProofPolicy::SENSITIVE_PROFILE_FIELDS
        ) !== [];
    }

    private function subject(CustomerChangeRequest $request, string $numbers): string
    {
        $name = $request->customer?->user?->name ?: 'Kunde';
        $subject = $this->changeTitle($request) . ' – ' . $name;
        if ($numbers !== '') {
            $subject .= ', Vertrag ' . Str::limit($numbers, 60, '');
        }
        return Str::limit($subject, 190, '');
    }

    private function changeTitle(CustomerChangeRequest $request): string
    {
        if ($request->type === 'bank') {
            return 'Änderung der Bankverbindung';
        }
        if ($request->type === 'address') {
            return 'Adressänderung';
        }
        $keys = array_keys($request->new_data ?? []);
        if (array_intersect($keys, ['first_name', 'last_name'])) {
            return 'Namensänderung';
        }
        return 'Adressänderung';
    }

    private function body(CustomerChangeRequest $request, string $insurer, string $numbers): string
    {
        $customer = $request->customer;
        $name = $customer?->user?->name ?: '—';
        $company = SystemSetting::get('company_name', 'Dienstly24');
        $effective = $request->effective_from
            ? \Carbon\Carbon::parse($request->effective_from)->format('d.m.Y')
            : null;

        $lines = [];
        $lines[] = 'Sehr geehrte Damen und Herren,';
        $lines[] = '';
        $lines[] = 'unser gemeinsamer Kunde hat uns eine Änderung seiner Daten mitgeteilt. '
            . 'Bitte berücksichtigen Sie diese Änderung für die unten genannten Verträge.';
        $lines[] = '';
        $lines[] = 'Kunde: ' . $name
            . ($customer?->birth_date ? ', geboren am ' . \Carbon\Carbon::parse($customer->birth_date)->format('d.m.Y') : '');
        if ($customer?->customer_number) {
            $lines[] = 'Unsere Kundennummer: ' . $customer->customer_number;
        }
        $lines[] = 'Gesellschaft: ' . $insurer;
        if ($numbers !== '') {
            $lines[] = 'Vertragsnummer(n): ' . $numbers;
        }
        $lines[] = '';
        $lines[] = $this->changeTitle($request) . ':';
        foreach ($this->changeLines($request) as $line) {
            $lines[] = $line;
        }
        $lines[] = 'Gültig ab: ' . ($effective ?: 'siehe beigefügter Nachweis');
        $lines[] = '';
        $lines[] = 'Einen Nachweis fügen wir dieser Nachricht bei.';
        $lines[] = '';
        $lines[] = 'Bitte bestätigen Sie uns kurz die Übernahme der Änderung.';
        $lines[] = '';
        $lines[] = 'Mit freundlichen Grüßen';
        $lines[] = $company;

        return implode("\n", $lines);
    }

    /** Die geaenderten Angaben in Klartext - nur, was wirklich vorliegt. */
    private function changeLines(CustomerChangeRequest $request): array
    {
        $new = $request->new_data ?? [];
        $old = $request->old_data ?? [];
        $lines = [];

        if ($request->type === 'bank') {
            if (!empty($old['iban'])) {
                $lines[] = 'Bisherige IBAN: ' . $old['iban'];
            }
            if (!empty($new['iban'])) {
                $lines[] = 'Neue IBAN: ' . $this->formatIban((string) $new['iban']);
            }
            if (!empty($new['account_holder'])) {
                $lines[] = 'Kontoinhaber: ' . $new['account_holder'];
            }
            return $lines;
        }

        if ($request->type === 'address') {
            if (!empty($old['street']) || !empty($old['city'])) {
                $lines[] = 'Bisherige Anschrift: ' . trim(($old['street'] ?? '') . ', ' . ($old['zip'] ?? '') . ' ' . ($old['city'] ?? ''), ' ,');
            }
            $lines[] = 'Neue Anschrift: ' . trim(($new['street'] ?? '') . ', ' . ($new['zip'] ?? '') . ' ' . ($new['city'] ?? ''), ' ,');
            return $lines;
        }

        // Profil: Name und/oder strukturierte Adresse
        $newName = trim(($new['first_name'] ?? '') . ' ' . ($new['last_name'] ?? ''));
        $oldName = trim(($old['first_name'] ?? '') . ' ' . ($old['last_name'] ?? ''));
        if ($newName !== '') {
            if ($oldName !== '') {
                $lines[] = 'Bisheriger Name: ' . $oldName;
            }
            $lines[] = 'Neuer Name: ' . $newName;
        }
        $street = trim(($new['address_street'] ?? '') . ' ' . ($new['address_house_number'] ?? '') . ($new['address_house_suffix'] ?? ''));
        $city = trim(($new['address_zip'] ?? '') . ' ' . ($new['address_city'] ?? ''));
        if ($street !== '' || $city !== '') {
            $oldStreet = trim(($old['address_street'] ?? '') . ' ' . ($old['address_house_number'] ?? ''));
            $oldCity = trim(($old['address_zip'] ?? '') . ' ' . ($old['address_city'] ?? ''));
            if ($oldStreet !== '' || $oldCity !== '') {
                $lines[] = 'Bisherige Anschrift: ' . trim($oldStreet . ', ' . $oldCity, ' ,');
            }
            $lines[] = 'Neue Anschrift: ' . trim($street . ', ' . $city, ' ,');
        }

        return $lines;
    }

    /** IBAN in Vierergruppen - so steht sie in jedem Schreiben. */
    private function formatIban(string $iban): string
    {
        return trim(chunk_split(str_replace(' ', '', strtoupper($iban)), 4, ' '));
    }
}
