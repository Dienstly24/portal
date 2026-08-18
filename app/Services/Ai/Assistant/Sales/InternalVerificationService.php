<?php
namespace App\Services\Ai\Assistant\Sales;

use App\Models\AiConversation;
use App\Models\AiConversationEvent;
use App\Models\Customer;

/**
 * Stille interne Pruefung der Kundenangaben
 * (Spezifikation Abschnitte 10, 11 und 22).
 *
 * DIE EINE REGEL: nach aussen geht ausschliesslich
 * VERIFICATION_PASSED / VERIFICATION_FAILED / VERIFICATION_PENDING.
 * Weder das Modell noch der Kunde erfaehrt, WELCHE Angabe nicht passt und
 * schon gar nicht, was im Bestand steht. Sonst waere der Chat ein Orakel,
 * mit dem sich gespeicherte Daten Stueck fuer Stueck erraten liessen -
 * genau das verbietet Abschnitt 11.
 *
 * Die Pruefpunkte (welches Feld hat gepasst) sieht NUR der Mitarbeiter in
 * der Beraterwelt, und auch dort ohne den Bestandswert.
 *
 * Die Pruefung ist deterministisch und KOSTENLOS - kein API-Aufruf.
 */
class InternalVerificationService
{
    public const PASSED = 'VERIFICATION_PASSED';
    public const FAILED = 'VERIFICATION_FAILED';
    public const PENDING = 'VERIFICATION_PENDING';

    public function __construct(private ConversationJournal $journal)
    {
    }

    /**
     * Angaben des Gespraechs gegen die Kundenakte pruefen.
     *
     * @return array{status: string, checks: array<string,string>}
     *         checks = Feldname => 'passt' | 'weicht ab' | 'nicht pruefbar'
     *                  (nur fuer die Mitarbeiter-Anzeige)
     */
    public function verify(AiConversation $conversation, ?Customer $customer): array
    {
        $angaben = $conversation->collectedData();
        $checks = [];

        if (!$customer) {
            // Ohne Akte gibt es nichts zu vergleichen (Interessent von der
            // Website). Das ist kein Misserfolg, sondern "noch offen" -
            // der Mitarbeiter prueft die Identitaet beim Abschluss.
            return ['status' => self::PENDING, 'checks' => []];
        }

        $checks['iban'] = $this->compare(
            $angaben['iban'] ?? null,
            [$customer->iban, $customer->iban2],
            fn ($w) => strtoupper(preg_replace('/\s+/', '', (string) $w)),
        );

        // Geburtsdatum: beide Seiten auf JJJJ-MM-TT bringen. `birth_date`
        // ist im Modell NICHT auf ein Datum gecastet und kommt je nach
        // Herkunft als "1990-05-12" oder "12.05.1990" - ein Vergleich der
        // blossen Ziffern wuerde beides nie zur Deckung bringen.
        $checks['birthdate'] = $this->compare(
            $angaben['birthdate'] ?? null,
            [$customer->birth_date],
            fn ($w) => $this->isoDate((string) $w),
        );

        $checks['email'] = $this->compare(
            $angaben['email'] ?? null,
            [$customer->email, $customer->email2],
            fn ($w) => mb_strtolower(trim((string) $w)),
        );

        $checks = array_filter($checks, fn ($e) => $e !== null);

        // Ergebnis: eine einzige Abweichung genuegt fuer FAILED. Gibt es
        // nichts Pruefbares, bleibt es PENDING - "keine Abweichung
        // gefunden" ist kein Nachweis.
        $status = self::PENDING;
        if (in_array('weicht ab', $checks, true)) {
            $status = self::FAILED;
        } elseif (in_array('passt', $checks, true)) {
            $status = self::PASSED;
        }

        $conversation->forceFill(['verification_status' => $status])->save();

        // Protokoll: Ergebnis und Feldnamen, NIE Werte.
        $this->journal->record($conversation, AiConversationEvent::EVENT_VERIFICATION, [
            'ergebnis' => $status,
            'punkte' => $checks,
        ], AiConversationEvent::ACTOR_SYSTEM);

        return ['status' => $status, 'checks' => $checks];
    }

    /**
     * Einen Wert gegen den Bestand vergleichen.
     *
     * @return string|null 'passt' | 'weicht ab' | null (nicht pruefbar)
     */
    private function compare(?string $angabe, array $bestand, callable $normalize): ?string
    {
        $angabe = trim((string) $angabe);
        if ($angabe === '' || $angabe === 'liegt vor') {
            return null;
        }

        $bestand = array_values(array_filter(array_map(
            fn ($w) => $w === null ? null : $normalize($w),
            $bestand
        ), fn ($w) => $w !== null && $w !== ''));

        if ($bestand === []) {
            // Der Kunde hat etwas geliefert, im Bestand steht nichts -
            // eine Abweichung ist das nicht, ein Nachweis auch nicht.
            return null;
        }

        return in_array($normalize($angabe), $bestand, true) ? 'passt' : 'weicht ab';
    }

    /**
     * Datum auf JJJJ-MM-TT bringen; nicht Deutbares bleibt unveraendert
     * (dann greift der Vergleich schlicht nicht).
     */
    private function isoDate(string $wert): string
    {
        $wert = trim($wert);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $wert, $m)) {
            return $m[1] . '-' . $m[2] . '-' . $m[3];
        }
        if (preg_match('/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})$/', $wert, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }

        return $wert;
    }

    /**
     * Text fuer den Kunden. Bei Misserfolg bewusst OHNE Grund: der
     * Hinweis "die IBAN stimmt nicht" wuerde bereits verraten, dass eine
     * andere IBAN gespeichert ist.
     */
    public static function customerHint(string $status, string $language): string
    {
        $texte = match ($status) {
            self::PASSED => [
                'de' => 'Vielen Dank, Ihre Angaben sind bei uns eingegangen.',
                'en' => 'Thank you, we have received your details.',
                'ar' => 'شكراً لك، وصلتنا بياناتك.',
            ],
            default => [
                'de' => 'Vielen Dank. Ein Mitarbeiter prueft Ihre Angaben und meldet sich bei Ihnen.',
                'en' => 'Thank you. A member of our team will review your details and get back to you.',
                'ar' => 'شكراً لك. سيقوم أحد الموظفين بمراجعة بياناتك والتواصل معك.',
            ],
        };

        return $texte[$language] ?? $texte['de'];
    }
}
