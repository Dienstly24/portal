<?php
namespace App\Services\Commission;

use App\Models\Partner;

/**
 * Intelligente Partnererkennung (Architekturplan Abschnitt 16).
 * Partner sind eine kleine, stabile Menge - der Domain-Abgleich ist in
 * der Praxis meist eindeutig; Namensähnlichkeit fängt neue/unbekannte
 * Absenderadressen desselben Partners ab. Erkennung unterhalb der
 * Sicherheitsschwelle liefert null -> manuelle Prüfung (HITL).
 */
class PartnerRecognitionService
{
    private const NAME_SIMILARITY_THRESHOLD = 0.80;

    public function recognize(?string $fromAddress, ?string $fromName): ?Partner
    {
        $domain = mb_strtolower(trim((string) (explode('@', (string) $fromAddress)[1] ?? '')));

        $partners = Partner::active()->get();

        // 1) Absender-Domain exakt in den hinterlegten Partner-Domains
        if ($domain !== '') {
            foreach ($partners as $partner) {
                $domains = array_map(
                    fn ($d) => mb_strtolower(trim((string) $d)),
                    $partner->email_domains ?? []
                );
                if (in_array($domain, $domains, true)) {
                    return $partner;
                }
            }
        }

        // 2) Anzeigename sehr ähnlich zum Partnernamen
        $name = mb_strtolower(trim((string) $fromName));
        if ($name !== '') {
            foreach ($partners as $partner) {
                similar_text($name, mb_strtolower($partner->name), $percent);
                if ($percent / 100 >= self::NAME_SIMILARITY_THRESHOLD) {
                    return $partner;
                }
            }
        }

        return null;
    }
}
