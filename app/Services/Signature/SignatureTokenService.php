<?php

namespace App\Services\Signature;

use App\Models\SignatureSigner;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Zugang zur Unterschriftsseite - ohne Konto, aber nicht ohne Nachweis.
 *
 * DREI ENTSCHEIDUNGEN, jede aus einem konkreten Risiko:
 *
 * 1. Das Token ist ZUFAELLIG (256 Bit aus dem kryptografischen Generator)
 *    und steht NIE in der Datenbank - dort liegt nur sein SHA-256. Ein
 *    Datenbankleck gibt damit keinen einzigen Vertrag preis. Der Hash ist
 *    absichtlich SHA-256 und nicht bcrypt: er muss NACHSCHLAGBAR sein
 *    (Index auf token_hash), sonst muesste jede Anfrage jede Zeile
 *    durchprobieren. Das ist unbedenklich, weil das Token nicht geraten
 *    werden kann - anders als ein Passwort hat es keine geringe Entropie.
 *
 * 2. Der Bestaetigungscode ist ein sechsstelliger EINMALCODE an dieselbe
 *    Adresse, an die eingeladen wurde. Er wird mit dem normalen
 *    Passwort-Hash gespeichert - hier ist Raten moeglich (eine Million
 *    Moeglichkeiten), deshalb bcrypt plus Versuchszaehler.
 *
 * 3. Der Zugang laesst sich WIDERRUFEN (token_revoked_at). Ein Abbruch der
 *    Anfrage muss den Link sofort tot machen; ein Zustand allein in der
 *    Anfrage reichte nicht, wenn irgendwann ein zweiter Lesepfad entsteht.
 */
class SignatureTokenService
{
    /** Gueltigkeit eines Einladungs-Tokens, wenn die Anfrage keine eigene Frist hat. */
    public const DEFAULT_DAYS = 30;

    /** Der Einmalcode ist kurzlebig - er belegt den Zugriff aufs Postfach JETZT. */
    public const CODE_MINUTES = 30;

    public const MAX_CODE_ATTEMPTS = 6;

    /**
     * Erzeugt einen frischen Zugang und gibt das Token im KLARTEXT zurueck -
     * das ist der einzige Moment, in dem es existiert. Es geht direkt in die
     * E-Mail und wird nirgends zwischengespeichert.
     */
    public function issue(SignatureSigner $signer, ?\DateTimeInterface $expiresAt = null): string
    {
        $token = Str::random(16).bin2hex(random_bytes(24));

        $signer->forceFill([
            'token_hash' => $this->hash($token),
            'token_created_at' => now(),
            'token_expires_at' => $expiresAt ?? now()->addDays(self::DEFAULT_DAYS),
            'token_revoked_at' => null,
            // Ein neuer Zugang setzt die Bestaetigung zurueck: wer einen
            // frischen Link bekommt, weist sich neu aus.
            'verification_hash' => null,
            'verification_expires_at' => null,
            'verification_attempts' => 0,
            'verified_at' => null,
        ])->save();

        return $token;
    }

    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Findet den Unterzeichner zu einem Klartext-Token - oder null. */
    public function find(string $token): ?SignatureSigner
    {
        if ($token === '' || strlen($token) > 200) {
            return null;
        }

        return SignatureSigner::where('token_hash', $this->hash($token))->first();
    }

    public function revoke(SignatureSigner $signer): void
    {
        $signer->forceFill(['token_revoked_at' => now()])->save();
    }

    /**
     * Legt einen neuen Einmalcode an und gibt ihn im Klartext zurueck (fuer
     * die E-Mail). Der Zaehler wird zurueckgesetzt - ein neu angeforderter
     * Code darf nicht an den Fehlversuchen des alten scheitern.
     */
    public function issueVerificationCode(SignatureSigner $signer): string
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $signer->forceFill([
            'verification_hash' => Hash::make($code),
            'verification_expires_at' => now()->addMinutes(self::CODE_MINUTES),
            'verification_attempts' => 0,
        ])->save();

        return $code;
    }

    /**
     * Prueft den eingegebenen Code. Die Antwort ist bewusst grob
     * ("stimmt / stimmt nicht") - eine Meldung, die "abgelaufen" von
     * "falsch" unterscheidet, verraet einem Fremden, dass er ueberhaupt
     * einen gueltigen Vorgang vor sich hat.
     */
    public function verifyCode(SignatureSigner $signer, string $code): bool
    {
        if ($signer->verification_hash === null
            || $signer->verification_expires_at === null
            || $signer->verification_expires_at->isPast()
            || $signer->verification_attempts >= self::MAX_CODE_ATTEMPTS) {
            return false;
        }

        $signer->increment('verification_attempts');

        if (! Hash::check($code, $signer->verification_hash)) {
            return false;
        }

        $signer->forceFill([
            'verified_at' => now(),
            'verification_hash' => null,
            'verification_expires_at' => null,
        ])->save();

        return true;
    }
}
