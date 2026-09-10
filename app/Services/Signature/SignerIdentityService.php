<?php

namespace App\Services\Signature;

use App\Models\SignatureSigner;
use Illuminate\Support\Carbon;

/**
 * Die zusaetzliche Identitaetspruefung ueber das GEBURTSDATUM.
 *
 * WARUM DAS GEBURTSDATUM UND NICHT MEHR: es steht auf jedem Ausweis und
 * in jedem Versicherungsschein - es ist KEIN Geheimnis, und dieses Modul
 * behauptet auch nirgends, dass es eines waere. Es leistet genau eine
 * Sache: es haelt den zufaelligen Empfaenger eines weitergeleiteten Links
 * auf. Wer die Daten der Person ohnehin kennt, kommt damit durch - und
 * genau deshalb ist die E-Mail-Bestaetigung die staerkere Wahl und bleibt
 * die Voreinstellung.
 *
 * VIER REGELN, alle aus derselben Ueberlegung:
 *  1. Der Wert erreicht NIE eine URL, ein Token, eine E-Mail oder einen
 *     Query-String - er kommt ausschliesslich als POST-Feld.
 *  2. Er wird NIE vollstaendig protokolliert. Im Protokoll steht, DASS
 *     geprueft wurde, nie WAS eingegeben wurde.
 *  3. Die Antwort ist grob: "stimmt nicht". Ein "fast richtig", ein
 *     "falsches Jahr" oder ein Unterschied zwischen "kein Datum
 *     hinterlegt" und "falsches Datum" waere eine Ratehilfe.
 *  4. Raten wird teuer: fuenf Versuche, dann 30 Minuten Sperre - je
 *     Unterzeichner, serverseitig, nicht im Browser.
 */
class SignerIdentityService
{
    public const MAX_ATTEMPTS = 5;

    public const BLOCK_MINUTES = 30;

    public function __construct(private readonly SignatureAuditService $audit)
    {
    }

    /** Hinterlegt das Vergleichsdatum. Ein leerer Wert entfernt es. */
    public function setDateOfBirth(SignatureSigner $signer, ?string $value): void
    {
        $datum = $this->normalize($value);
        $signer->forceFill([
            'dob_check' => $datum,
            'dob_attempts' => 0,
            'dob_blocked_until' => null,
            'dob_verified_at' => null,
        ])->save();
    }

    /** Muss dieser Unterzeichner sein Geburtsdatum noch bestaetigen? */
    public function needsDob(SignatureSigner $signer): bool
    {
        if (! $signer->request?->requiresDob()) {
            return false;
        }

        // OHNE hinterlegtes Datum gibt es nichts zu vergleichen. Dann wird
        // NICHT gefragt - eine Frage, die niemand richtig beantworten kann,
        // waere eine Sackgasse, und "irgendwas eingeben genuegt" waere eine
        // Pruefung, die nichts prueft.
        return $signer->dob_check !== null && $signer->dob_verified_at === null;
    }

    public function isBlocked(SignatureSigner $signer): bool
    {
        return $signer->dob_blocked_until !== null && $signer->dob_blocked_until->isFuture();
    }

    /**
     * Prueft die Eingabe. Liefert nur true/false - der Grund bleibt
     * bewusst im Dunkeln.
     */
    public function verifyDob(SignatureSigner $signer, ?string $input): bool
    {
        if ($this->isBlocked($signer) || $signer->dob_check === null) {
            return false;
        }

        $signer->increment('dob_attempts');
        $signer->refresh();

        $eingabe = $this->normalize($input);
        // Zeitkonstanter Vergleich: bei einem frueh abbrechenden Vergleich
        // laesst sich aus der Antwortzeit ablesen, wie viele Zeichen schon
        // stimmen.
        $treffer = $eingabe !== null && hash_equals((string) $signer->dob_check, $eingabe);

        if (! $treffer) {
            if ($signer->dob_attempts >= self::MAX_ATTEMPTS) {
                $signer->forceFill(['dob_blocked_until' => now()->addMinutes(self::BLOCK_MINUTES)])->save();
            }
            // Im Protokoll steht, DASS geprueft wurde - nie, WAS eingegeben
            // wurde. Ein Fehlversuch ist ausserdem ein Sicherheitsereignis
            // und gehoert deshalb ins Protokoll.
            $this->audit->record($signer->request, 'identity_failed', $signer,
                'Geburtsdatum stimmt nicht (Versuch '.$signer->dob_attempts.')');

            return false;
        }

        $signer->forceFill([
            'dob_verified_at' => now(),
            'dob_attempts' => 0,
            'dob_blocked_until' => null,
        ])->save();
        $this->audit->record($signer->request, 'identity_verified', $signer, 'Geburtsdatum bestaetigt');

        return true;
    }

    /**
     * Bringt jede uebliche Schreibweise auf JJJJ-MM-TT.
     *
     * Bewusst tolerant bei der EINGABE (der Unterzeichner tippt auf einem
     * Telefon: "1.5.1980", "01.05.1980", "1980-05-01") und streng beim
     * VERGLEICH - sonst scheitert eine richtige Person an einem Punkt.
     */
    private function normalize(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        foreach (['d.m.Y', 'j.n.Y', 'Y-m-d', 'd/m/Y', 'd-m-Y'] as $format) {
            try {
                $datum = Carbon::createFromFormat($format, $value);
            } catch (\Throwable) {
                continue;
            }
            // Nur exakte Treffer: Carbon "repariert" sonst still, aus
            // "32.13.1980" wuerde ein echtes Datum.
            if ($datum === null || $datum->format($format) !== $value) {
                continue;
            }
            // Ein Geburtsdatum in der Zukunft oder vor 1900 ist ein Tippfehler,
            // kein Datum.
            if ($datum->isFuture() || $datum->year < 1900) {
                return null;
            }

            return $datum->format('Y-m-d');
        }

        return null;
    }
}
