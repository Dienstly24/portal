<?php

namespace App\Services\Signature;

use App\Models\SignatureRequest;
use App\Services\Pdf\PdfDocument;
use App\Services\Pdf\PdfEingangspruefung;
use App\Services\Pdf\PdfSyntax;
use App\Support\FeldGeometrie;
use App\Support\SignatureFieldType;

/**
 * Selbsttest des fertigen PDF VOR "Abgeschlossen" (KI-058).
 *
 * Bis 03.10.2026 galt ein Vorgang als abgeschlossen, sobald IRGENDEIN PDF
 * geschrieben war. Auf dem Server standen dadurch 11 von 17 Vorgaengen als
 * "Abgeschlossen" da, deren Dokument die Unterschrift gar nicht zeigte -
 * und niemand merkte es, bis ein Kunde nachfragte. Jetzt wird das Ergebnis
 * geprueft, bevor es gespeichert und verschickt wird:
 *
 *  1. Das Ergebnis ist eine FORTSCHREIBUNG des Originals und nicht das
 *     Original selbst (sonst ist nichts gestempelt worden).
 *  2. Jedes gesetzte Bild ist in den Ressourcen SEINER Seite unter seinem
 *     Namen auffindbar und ist ein Bild (genau daran scheiterten 8 von 11
 *     Vorgaengen: "XObject 'D24Sig1x0' is unknown", KI-062).
 *  3. Ist poppler vorhanden: jedes ausgefuellte Feld ist im gerenderten
 *     Bild SICHTBAR (dieselbe Regel wie `signaturen:diagnose`), und das
 *     Ergebnis erzeugt keine poppler-Meldung, die das Original nicht hat.
 *     Fehlt poppler, entfaellt nur dieser Punkt - ein fehlendes Programm
 *     auf dem Server soll keinen Vorgang blockieren, den die
 *     Strukturpruefung bereits belegt.
 *  4. Ist qpdf vorhanden: `qpdf --check` meldet keinen Fehler (seit
 *     04.10.2026, Qualitaetsgate A1).
 */
class SignedPdfVerifier
{
    public function __construct(
        private readonly PdfSichtbarkeit $sichtbarkeit,
        private readonly PdfEingangspruefung $eingang,
    ) {
    }

    private function feldName(string $typ, bool $firma): string
    {
        if ($firma) {
            return 'Unternehmenssignatur';
        }

        return match ($typ) {
            SignatureFieldType::SIGNATURE => 'Unterschrift',
            SignatureFieldType::INITIALS => 'Initialen',
            SignatureFieldType::CHECKBOX => 'Ankreuzfeld',
            SignatureFieldType::DATE => 'Datum',
            default => 'Feld "'.(SignatureFieldType::LABELS[$typ] ?? $typ).'"',
        };
    }

    /**
     * @param  list<array{page: int, name: string, object: int}>  $bilder
     * @return list<string> Befunde in Klartext; leer = bestanden
     */
    public function pruefe(SignatureRequest $request, string $original, string $signiert, array $bilder): array
    {
        $befunde = [];
        if ($signiert === $original) {
            return ['Das Ergebnis ist unveraendert das Original - es wurde nichts gestempelt.'];
        }
        if (! str_starts_with($signiert, $original)) {
            $befunde[] = 'Das Ergebnis ist keine Fortschreibung des Originals.';
        }

        try {
            $doc = PdfDocument::open($signiert);
        } catch (\Throwable $e) {
            return ['Das Ergebnis ist als PDF nicht lesbar: '.mb_substr($e->getMessage(), 0, 160)];
        }

        foreach ($bilder as $bild) {
            $xobjects = $this->xobjectsDerSeite($doc, $bild['page']);
            $eintrag = $xobjects[$bild['name']] ?? null;
            $nummer = $eintrag === null ? null : PdfSyntax::referenceNumber($eintrag);
            $koerper = $nummer === null ? null : $doc->objectBody($nummer);
            if ($koerper === null || ! str_contains($koerper, '/Subtype /Image')) {
                $befunde[] = 'Bild '.$bild['name'].' ist auf Seite '.($bild['page'] + 1).' nicht in den Seitenressourcen auffindbar.';
            }
        }

        if ($befunde !== []) {
            return $befunde;
        }

        // Sichtbarkeit am Bild - fuer JEDES ausgefuellte Feld (Bild, Text,
        // Datum, Kreuz). Bis 04.10.2026 nur fuer Bildfelder; ein Text, der
        // ausserhalb der sichtbaren Flaeche landet, waere sonst ebenso
        // still verloren gegangen.
        $request->loadMissing('fields');
        $render = [];
        foreach ($request->fields as $field) {
            if (! $field->isFilled()) {
                continue;
            }
            $seite = (int) $field->page;
            if ($seite < 1 || $seite > $doc->pageCount()) {
                continue;
            }
            $render[$seite] ??= $this->sichtbarkeit->renderPaar($original, $signiert, $seite, $request->nutztCropBox());
            if (! $render[$seite]['verfuegbar']) {
                continue;
            }
            $hoehe = FeldGeometrie::bezugsseite($request, $doc->page($seite - 1))->displayHeight();
            $urteil = $this->sichtbarkeit->vergleiche($render[$seite], $this->sichtbarkeit->bildBereich($field, $hoehe));
            if ($urteil['urteil'] !== 'sichtbar') {
                $befunde[] = $this->feldName($field->type, $field->isCompany()).' auf Seite '.$seite.' ist im fertigen Dokument nicht sichtbar.';
            }
        }

        // poppler-Meldungen beim ERGEBNIS, die das Original nicht hat
        // ("XObject unknown", "Weird page contents" ...): genau diese Klasse
        // Fehler liess 8 von 11 Vorgaengen unbemerkt durch (KI-062). Eine
        // Meldung, die schon das Original erzeugt, ist eine Eigenschaft der
        // hochgeladenen Datei und kein Fehler des Stempelns.
        foreach ($render as $seite => $paar) {
            if ($paar['verfuegbar'] && $paar['stderr_signiert'] !== '' && $paar['stderr_original'] === '') {
                $befunde[] = 'Darstellungsfehler auf Seite '.$seite.': '.mb_substr($paar['stderr_signiert'], 0, 160);
            }
        }

        // Strukturpruefung des Ergebnisses (qpdf), sofern installiert.
        $struktur = $this->eingang->strukturpruefung($signiert);
        if (! $struktur['ok']) {
            $befunde[] = 'Strukturpruefung (qpdf) meldet Fehler: '.implode(' | ', array_slice($struktur['meldungen'], 0, 3));
        }

        return $befunde;
    }

    /**
     * Das /XObject-Woerterbuch, das fuer diese Seite gilt (eigenes oder
     * geerbtes /Resources, Referenzen aufgeloest).
     *
     * @return array<string, string>
     */
    private function xobjectsDerSeite(PdfDocument $doc, int $index): array
    {
        $page = $doc->page($index);
        $owner = $doc->resourcesOwner($page);
        $ressourcen = $owner['inline']
            ? ($doc->dict($owner['object'])['Resources'] ?? null)
            : $doc->objectBody($owner['object']);
        $ressourcen = $doc->resolve($ressourcen);
        if ($ressourcen === null) {
            return [];
        }
        $xobject = PdfSyntax::dictEntries($ressourcen)['XObject'] ?? null;
        $xobject = $doc->resolve($xobject);

        return $xobject === null ? [] : PdfSyntax::dictEntries($xobject);
    }
}
