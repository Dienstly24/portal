<?php
namespace App\Services\Ai;

/**
 * Reduziert den (seitenweise per Form-Feed getrennten) Text eines digitalen
 * PDF auf die fachlich relevanten Seiten, wenn das Dokument einem bekannten
 * Profil entspricht.
 *
 * Beispiel CHECK24-Beratungsprotokoll: nur die Seiten 1,2,4,5,6,7 tragen
 * Kunden-, Fahrzeug- und Tarifdaten - der Rest (Vergleichsliste, Rechtstext,
 * Anhang) ist Rauschen. Diese Seiten wegzulassen macht die KI-Extraktion
 * genauer (weniger Fehltreffer aus fremden Abschnitten) UND guenstiger
 * (weniger Tokens). Greift kein Profil, bleibt der Text unveraendert.
 *
 * Die Profile sind konfigurierbar (`services.document_profiles`), damit der
 * Betreiber sie ohne Redeploy anpassen kann.
 */
class RelevantPageSelector
{
    /**
     * Reduziert den Text auf die relevanten Seiten des ersten passenden
     * Profils. Ein "\f" (Form-Feed) trennt die Seiten in der pdftotext-Ausgabe.
     */
    public function reduce(string $text): string
    {
        $pages = explode("\f", $text);
        if (count($pages) < 2) {
            return $text; // Einseitig / keine Seitentrennung -> nichts zu tun.
        }

        $upper = mb_strtoupper($text);
        foreach ($this->profiles() as $profile) {
            $markers = (array) ($profile['markers'] ?? []);
            $wanted = (array) ($profile['pages'] ?? []);
            if ($markers === [] || $wanted === []) {
                continue;
            }

            foreach ($markers as $marker) {
                if ($marker === '' || !str_contains($upper, mb_strtoupper((string) $marker))) {
                    continue;
                }

                $kept = [];
                foreach ($wanted as $pageNumber) {
                    $index = (int) $pageNumber - 1;
                    if ($index >= 0 && isset($pages[$index]) && trim($pages[$index]) !== '') {
                        $kept[] = rtrim($pages[$index]);
                    }
                }

                // Nur reduzieren, wenn dabei wirklich etwas uebrig bleibt -
                // sonst lieber den vollen Text als ein leeres Ergebnis.
                return $kept === [] ? $text : trim(implode("\n\n", $kept));
            }
        }

        return $text;
    }

    /** @return list<array{markers: list<string>, pages: list<int>}> */
    private function profiles(): array
    {
        return config('services.document_profiles', [
            // CHECK24-Beratungsprotokoll (Kfz): nur diese Seiten tragen
            // Kunden-/Fahrzeug-/Tarifdaten (Betreiber-Vorgabe).
            ['markers' => ['BERATUNGSPROTOKOLL'], 'pages' => [1, 2, 4, 5, 6, 7]],
        ]);
    }
}
