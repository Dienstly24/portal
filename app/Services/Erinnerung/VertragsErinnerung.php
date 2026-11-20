<?php

namespace App\Services\Erinnerung;

use App\Models\Contract;
use App\Support\ChatReferenz;
use App\Support\ErinnerungsAnrede;
use App\Support\ErinnerungsSparte;
use App\Support\ErinnerungsTexte;
use App\Support\Kuendigungsfrist;
use App\Support\Kundenportal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Alles, was eine Vertrags-Erinnerung zeigt - an EINER Stelle berechnet,
 * damit HTML, Klartext und Betreff dieselben Werte tragen.
 *
 * Vorlagenwahl (Betreiber-Vorgabe 20.11.2026):
 *   heute <= Kuendigungsfrist          -> 'a'
 *   Frist vorbei, Verlaengerung noch nicht erreicht -> 'b'
 *   Verlaengerung erreicht/vorbei      -> null (nichts senden)
 */
final class VertragsErinnerung
{
    public function __construct(
        public readonly string $sprache,
        public readonly string $variante,
        public readonly string $betreff,
        public readonly array $absaetze,
        public readonly string $anrede,
        public readonly array $zeilen,
        public readonly string $chatUrl,
        public readonly ?string $whatsappUrl,
        public readonly string $telefon,
        public readonly string $impressumUrl,
        public readonly string $datenschutzUrl,
        public readonly ?string $abmeldeUrl,
        public readonly array $rahmen,
    ) {}

    public static function variante(CarbonInterface $heute, CarbonInterface $verlaengerung, CarbonInterface $frist): ?string
    {
        $heute = CarbonImmutable::instance($heute)->startOfDay();
        if ($heute->lte($frist)) {
            return 'a';
        }
        if ($heute->lt(CarbonImmutable::instance($verlaengerung)->startOfDay())) {
            return 'b';
        }

        return null;
    }

    /**
     * null, wenn es fuer diesen Vertrag (heute) nichts zu senden gibt:
     * keine Vorlage, kein Vertragsende, oder Verlaengerung erreicht.
     */
    public static function bauen(
        Contract $vertrag,
        ?string $kurzreferenz,
        ?string $abmeldeUrl,
        ?CarbonInterface $heute = null,
    ): ?self {
        $sparte = ErinnerungsSparte::fuer($vertrag->type);
        $vorlage = ErinnerungsSparte::vorlage($vertrag->type);
        if ($sparte === null || $vorlage === null || $vertrag->end_date === null) {
            return null;
        }

        $heute ??= now();
        $verlaengerung = CarbonImmutable::parse($vertrag->end_date)->startOfDay();
        $frist = Kuendigungsfrist::eingangBis($verlaengerung, ErinnerungsSparte::kuendigungsfristMonate($vertrag->type));
        $variante = self::variante($heute, $verlaengerung, $frist);
        if ($variante === null) {
            return null;
        }

        $kunde = $vertrag->customer;
        $sprache = ($kunde->preferred_lang ?? 'de') === 'ar' ? 'ar' : 'de';
        $rahmen = ErinnerungsTexte::rahmen($sprache);
        $partner = trim((string) $vertrag->insurer);
        $nummer = trim((string) $vertrag->contract_number);

        $ihre = $sprache === 'ar' ? $sparte['ihre_ar'] : $sparte['ihre_de'];
        $werte = [
            '{Ihre}' => $ihre,
            '{ihre}' => $ihre,
            '{bei_partner}' => $partner === '' ? '' : ($sprache === 'ar' ? ' لدى '.$partner : ' bei '.$partner),
            '{verlaengerung}' => $verlaengerung->format('d.m.Y'),
            '{frist}' => $frist->format('d.m.Y'),
        ];
        $text = ErinnerungsTexte::fuer($vorlage, $variante, $sprache, $werte);
        if ($text === null) {
            return null;
        }

        // Leere Zeilen werden NIE gezeigt (z.B. Vertragsnummer fehlt).
        $zeilen = array_filter([
            $rahmen['sparte'] => $sprache === 'ar' ? $sparte['name_ar'] : $sparte['name_de'],
            ($sprache === 'ar' ? $sparte['partner_ar'] : $sparte['partner_de']) => $partner,
            $rahmen['vertragsnummer'] => $nummer,
            $rahmen['verlaengerung'] => $verlaengerung->format('d.m.Y'),
            $rahmen['frist'] => $variante === 'a' ? $frist->format('d.m.Y') : '',
        ], static fn ($wert) => trim((string) $wert) !== '');

        $waText = $kurzreferenz !== null
            ? strtr($rahmen['wa_text'], [
                '{meine}' => $sprache === 'ar' ? $sparte['ihre_ar'] : $sparte['meine_de'],
                '{ref}' => $kurzreferenz,
            ])
            : null;

        return new self(
            sprache: $sprache,
            variante: $variante,
            betreff: $text['betreff'],
            absaetze: $text['absaetze'],
            anrede: ErinnerungsAnrede::fuer($kunde, $sprache),
            zeilen: $zeilen,
            chatUrl: $kunde !== null
                ? Kundenportal::chatUrl(ChatReferenz::erzeugen($kunde, $vertrag))
                : Kundenportal::chatUrl(),
            whatsappUrl: Kundenportal::whatsappLink($waText),
            telefon: Kundenportal::telefonAnzeige(),
            impressumUrl: self::rechtsseite('impressum'),
            datenschutzUrl: self::rechtsseite('datenschutz'),
            abmeldeUrl: $abmeldeUrl,
            rahmen: $rahmen,
        );
    }

    private static function rechtsseite(string $seite): string
    {
        $eigen = config('erinnerung.'.$seite.'_url');
        if (is_string($eigen) && $eigen !== '') {
            return $eigen;
        }

        return 'https://'.config('website.canonical_host', 'www.dienstly24.de').'/'.$seite;
    }
}
