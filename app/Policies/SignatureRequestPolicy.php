<?php

namespace App\Policies;

use App\Models\SignatureField;
use App\Models\SignatureRequest;
use App\Models\User;
use App\Support\SignatureStatus;

/**
 * Wer darf was mit einer Signaturanfrage?
 *
 * ZWEI ACHSEN, und beide sind noetig:
 *  1. Das PORTFOLIO. Sobald eine Anfrage einem Kunden gehoert, gilt
 *     dieselbe Sichtbarkeit wie ueberall sonst - ein Mitarbeiter sieht in
 *     den Signaturen nie mehr als in seiner Kundenliste.
 *  2. Die URHEBERSCHAFT. Eine Anfrage OHNE Kunden hat kein Portfolio, an
 *     dem sie haengen koennte. Sie gehoert deshalb dem, der sie angelegt
 *     hat (und der Leitung) - sonst saehe jeder Mitarbeiter jeden
 *     eigenstaendigen Vorgang des Hauses, und die Nullbarkeit von
 *     customer_id waere ein Loch in der Zugriffskontrolle statt einer
 *     Erleichterung.
 *
 * Die Faehigkeiten sind bewusst einzeln benannt (sehen, anlegen,
 * bearbeiten, versenden, abbrechen, herunterladen, Protokoll, zuordnen).
 * Sie fallen heute teilweise zusammen; getrennt lassen sie sich spaeter
 * verschieben, ohne dass jede Aufrufstelle zu suchen waere.
 */
class SignatureRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function create(User $user): bool
    {
        return $user->isStaff();
    }

    public function view(User $user, SignatureRequest $request): bool
    {
        if (! $user->isStaff()) {
            return false;
        }
        // Wer INTERN unterschreiben soll, muss das Dokument sehen koennen -
        // auch ausserhalb seines Portfolios (der Geschaeftsfuehrer zeichnet
        // den Vertrag, den ein Mitarbeiter vorbereitet hat). Ausgewaehlt hat
        // ihn jemand, der den Vorgang bearbeiten darf; mehr als SEHEN und
        // SEINE Unterschrift setzen erlaubt das nicht.
        if ($this->istInternZugeordnet($user, $request)) {
            return true;
        }
        if ($request->customer_id !== null) {
            return $user->canAccessCustomer($request->customer_id);
        }

        return $this->isOwnerOrManagement($user, $request);
    }

    /** Bearbeiten heisst: Felder setzen, Unterzeichner pflegen, Angaben aendern. */
    public function update(User $user, SignatureRequest $request): bool
    {
        return $this->view($user, $request) && $this->isOwnerOrManagement($user, $request);
    }

    public function send(User $user, SignatureRequest $request): bool
    {
        return $this->update($user, $request);
    }

    /**
     * "Erneut erzeugen" nach einem gescheiterten Abschluss: alle haben
     * unterschrieben, nur das fertige Dokument fehlt oder hat den
     * Selbsttest nicht bestanden. Es aendert keine Unterschrift - es
     * erzeugt das Dokument aus den gespeicherten Daten neu.
     */
    public function regenerate(User $user, SignatureRequest $request): bool
    {
        return $request->status === SignatureStatus::COMPLETION_FAILED
            && $this->update($user, $request);
    }

    public function cancel(User $user, SignatureRequest $request): bool
    {
        return $this->update($user, $request);
    }

    public function download(User $user, SignatureRequest $request): bool
    {
        return $this->view($user, $request);
    }

    /** Das Protokoll ist der Nachweis - wer den Vorgang sehen darf, darf ihn belegen. */
    public function audit(User $user, SignatureRequest $request): bool
    {
        return $this->view($user, $request);
    }

    /**
     * Zuordnen veraendert eine KUNDENAKTE. Das darf nur, wer die Anfrage
     * fuehrt - und wer den Zielkunden ohnehin sehen duerfte; letzteres
     * prueft der Controller am gewaehlten Kunden, nicht hier (der Zielkunde
     * steht zu diesem Zeitpunkt noch nicht fest).
     */
    public function assignCustomer(User $user, SignatureRequest $request): bool
    {
        return $this->update($user, $request);
    }

    public function delete(User $user, SignatureRequest $request): bool
    {
        // Ein abgeschlossener Vorgang wird NIE geloescht: an ihm haengt der
        // Nachweis fuer eine Unterschrift, die jemand geleistet hat.
        return ! $request->isCompleted() && $this->isOwnerOrManagement($user, $request)
            && in_array($user->role, ['admin', 'manager'], true);
    }

    /**
     * Die EIGENE interne Unterschrift setzen (Teil B). Nur wer ein Feld in
     * diesem Vorgang hat und das Recht "Darf fuer das Unternehmen
     * unterschreiben" besitzt - das Bild kommt dabei immer aus SEINER Ablage.
     */
    public function signInternal(User $user, SignatureRequest $request): bool
    {
        return $user->darfFuerFirmaUnterschreiben() && $request->isDraft()
            && $this->istInternZugeordnet($user, $request);
    }

    /** Interne Unterschriften zuruecknehmen - wie Bearbeiten, nur im Entwurf. */
    public function resetInternal(User $user, SignatureRequest $request): bool
    {
        return $request->isDraft() && $this->update($user, $request);
    }

    private function istInternZugeordnet(User $user, SignatureRequest $request): bool
    {
        return SignatureField::query()
            ->where('signature_request_id', $request->id)
            ->where('internal_user_id', $user->id)
            ->exists();
    }

    private function isOwnerOrManagement(User $user, SignatureRequest $request): bool
    {
        return (int) $request->created_by === (int) $user->id
            || in_array($user->role, ['admin', 'manager'], true);
    }
}
