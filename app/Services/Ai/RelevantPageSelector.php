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

        // Ein Profil greift nur, wenn sich das Dokument auf seiner ERSTEN Seite
        // als solches zu erkennen gibt. Wuerde das ganze Dokument durchsucht,
        // reichte eine Erwaehnung im Rechtstext einer Folgeseite ("...
        // Beratungsprotokolle ..." im Datenschutzhinweis des LichtBlick-
        // Auftrags), um ein voellig fremdes Dokument auf die Seiten eines
        // anderen Formulars zu stutzen - und genau die Datenseite zu verlieren.
        $upper = mb_strtoupper(rtrim($pages[0]));
        foreach ($this->profiles() as $profile) {
            $markers = (array) ($profile['markers'] ?? []);
            $wanted = (array) ($profile['pages'] ?? []);
            if ($markers === [] || $wanted === []) {
                continue;
            }

            foreach ($markers as $marker) {
                if ($marker === '' || ! str_contains($upper, mb_strtoupper((string) $marker))) {
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
            // Kunden-/Fahrzeug-/Tarifdaten (Betreiber-Vorgabe). Die Marke
            // "CHECK24" steht ebenfalls im Kopf der ersten Seite und erkennt
            // das Protokoll auch dann, wenn es sich anders betitelt.
            ['markers' => ['BERATUNGSPROTOKOLL', 'CHECK24'], 'pages' => [1, 2, 4, 5, 6, 7]],
        ]);
    }
}
