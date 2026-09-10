<?php

namespace App\Services\Signature;

use App\Models\SignatureRequest;
use App\Services\Pdf\PdfDocument;
use App\Services\Pdf\PdfException;
use App\Services\Pdf\PdfStamp;
use App\Services\Pdf\PdfStamper;
use App\Support\LocalTime;
use App\Support\SignatureFieldType;

/**
 * Erzeugt aus Original + ausgefuellten Feldern das fertige, unterschriebene
 * PDF.
 *
 * DAS ORIGINAL BLEIBT UNANGETASTET - es wird gelesen, nie ueberschrieben.
 * Das Ergebnis ist eine FORTSCHREIBUNG desselben Dokuments (siehe
 * PdfStamper): die ersten Bytes der unterschriebenen Datei sind Byte fuer
 * Byte das Original. Damit laesst sich beweisen, dass am Vertragstext
 * nichts geaendert wurde - und nicht nur behaupten.
 *
 * Die Protokollseite am Ende macht die Datei fuer sich allein
 * aussagekraeftig: wer sie weiterreicht, reicht den Nachweis mit. Das
 * vollstaendige Ereignisprotokoll bleibt zusaetzlich im Portal.
 */
class SignedPdfBuilder
{
    public function __construct(private readonly SignatureStorage $storage)
    {
    }

    /**
     * @return array{pdf: string, hash: string}
     *
     * @throws PdfException
     */
    public function build(SignatureRequest $request): array
    {
        $original = $this->storage->read($request->original_path);
        if ($original === null) {
            throw new \RuntimeException('Das Original-PDF der Signaturanfrage fehlt im Speicher.');
        }

        $document = PdfDocument::open($original);
        $stamper = new PdfStamper($document);

        $request->loadMissing(['fields.signer', 'fields.companyAsset', 'signers']);

        foreach ($request->fields as $field) {
            if (! $field->isFilled()) {
                continue;
            }
            $pageIndex = max(0, $field->page - 1);
            if ($pageIndex >= $document->pageCount()) {
                continue; // Eine Seite, die es nicht (mehr) gibt, wird uebersprungen statt geraten.
            }
            $page = $document->page($pageIndex);

            // Anteilige Position in Punkte der ANZEIGE-Seite umrechnen.
            $x = $field->pos_x * $page->displayWidth();
            $y = $field->pos_y * $page->displayHeight();
            $width = $field->width * $page->displayWidth();
            $height = $field->height * $page->displayHeight();

            if ($field->isDrawn()) {
                $png = $this->storage->read($field->image_path);
                if ($png !== null) {
                    $stamper->add(PdfStamp::image($pageIndex, $png, $x, $y, $width, $height));
                }

                continue;
            }

            // FIRMENBILD: dieselbe Einbettung wie die Handschrift (Alphakanal
            // bleibt), aber es kommt aus dem hinterlegten Bestand und nicht
            // aus einer Zeichenflaeche. Fehlt die Datei, wird NICHTS gesetzt
            // statt ein Platzhalter - ein leerer Fleck ist ehrlicher als ein
            // Kasten, den jemand fuer den Stempel haelt.
            if ($field->isCompany()) {
                $asset = $field->companyAsset;
                $png = $asset === null ? null : $this->storage->disk()->get($asset->path);
                if ($png !== null && $png !== '') {
                    $stamper->add(PdfStamp::image($pageIndex, $png, $x, $y, $width, $height));
                }

                continue;
            }

            if ($field->type === SignatureFieldType::CHECKBOX) {
                $stamper->add(PdfStamp::box($pageIndex, $x, $y, $width, $height));
                if ($this->isChecked((string) $field->value)) {
                    $stamper->add(PdfStamp::text($pageIndex, 'X', $x + $width * 0.2, $y, $width, $height, $height * 0.9));
                }

                continue;
            }

            $stamper->add(PdfStamp::text(
                $pageIndex,
                (string) $field->value,
                $x,
                $y,
                $width,
                $height,
                $this->fontSize($height),
            ));
        }

        $stamper->withProtocolPage('Signaturprotokoll', $this->protocolSections($request));

        $pdf = $stamper->build();

        return ['pdf' => $pdf, 'hash' => hash('sha256', $pdf)];
    }

    /**
     * Schriftgroesse aus der Feldhoehe. Bewusst gedeckelt: ein versehentlich
     * riesig gezogenes Textfeld soll den Vertragstext nicht ueberdecken.
     */
    private function fontSize(float $height): float
    {
        return max(7.0, min(14.0, $height * 0.62));
    }

    private function isChecked(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'ja', 'x', 'true', 'on'], true);
    }

    /** @return list<array{title: string, lines: list<string>}> */
    private function protocolSections(SignatureRequest $request): array
    {
        $request->loadMissing(['fields.companyAsset.creator']);
        // Zeitpunkte in deutscher Ortszeit - gespeichert wird UTC
        // (Betreiber-Vorgabe 21.08.2026). Hier bewusst ueber LocalTime und
        // nicht ueber das Blade-Makro ->lokal(): das Protokoll entsteht in
        // einem Dienst, nicht in einer Ansicht.
        $sections = [[
            'title' => 'Dokument',
            'lines' => array_values(array_filter([
                'Titel: '.$request->title,
                'Original-Datei: '.$request->original_name,
                'SHA-256 des Originals: '.$request->original_hash,
                'Signaturanfrage: '.$request->id,
                $request->reference !== null ? 'Referenz: '.$request->reference : null,
                'Erstellt: '.$this->zeit($request->created_at),
                'Versendet: '.$this->zeit($request->sent_at),
            ])),
        ]];

        foreach ($request->signers as $index => $signer) {
            $sections[] = [
                'title' => 'Unterzeichner '.($index + 1).': '.$signer->name,
                'lines' => array_values(array_filter([
                    'E-Mail: '.$signer->email,
                    $signer->verified_at
                        ? 'E-Mail bestaetigt: '.$this->zeit($signer->verified_at)
                        : 'E-Mail-Bestaetigung: nicht angefordert',
                    $signer->viewed_at ? 'Dokument geoeffnet: '.$this->zeit($signer->viewed_at) : null,
                    $signer->signed_at ? 'Unterschrieben: '.$this->zeit($signer->signed_at) : null,
                    $signer->ip_address ? 'IP-Adresse: '.$signer->ip_address : null,
                    $signer->user_agent ? 'Geraet: '.mb_substr($signer->user_agent, 0, 90) : null,
                ])),
            ];
        }

        // FIRMENBILDER stehen in einem EIGENEN Abschnitt - nicht bei den
        // Unterzeichnern. Wer das Protokoll liest, soll auf einen Blick
        // sehen, was eine abgegebene Erklaerung eines Menschen ist und was
        // eine vom Betrieb aufgebrachte Grafik.
        $firmenfelder = $request->fields->filter(fn ($f) => $f->isCompany() && $f->company_asset_id !== null);
        if ($firmenfelder->isNotEmpty()) {
            $zeilen = [];
            foreach ($firmenfelder as $feld) {
                $asset = $feld->companyAsset;
                if ($asset === null) {
                    continue;
                }
                $zeilen[] = $asset->typeLabel().': '.$asset->name.' (Seite '.$feld->page.')';
                $zeilen[] = '  eingesetzt von: '.($asset->creator->name ?? 'unbekannt')
                    .'; SHA-256 des Bildes: '.mb_substr($asset->hash, 0, 32).'...';
            }
            $zeilen[] = 'Firmenbilder sind KEINE Unterschrift einer Person und keine Willenserklaerung.';
            $sections[] = ['title' => 'Firmenbilder', 'lines' => $zeilen];
        }

        // Die rechtliche Einordnung steht im Dokument, aber sie behauptet
        // NICHTS: dieses Modul erzeugt eine einfache elektronische Signatur
        // mit technischem Nachweis - keine qualifizierte im Sinne der
        // eIDAS-Verordnung. Was fuer den jeweiligen Geschaeftsfall noetig
        // ist, entscheidet die Rechtspruefung, nicht die Software.
        $sections[] = [
            'title' => 'Rechtlicher Hinweis',
            'lines' => array_values(array_filter([
                'Einfache elektronische Signatur (eIDAS Art. 3 Nr. 10) mit technischem Nachweis.',
                'Keine qualifizierte elektronische Signatur; keine Signaturpruefung durch einen Vertrauensdienst.',
                $request->consent_text ? 'Zustimmungstext: '.mb_substr($request->consent_text, 0, 160) : null,
                'Vollstaendiges Ereignisprotokoll: im Dienstly24-Portal zur Signaturanfrage.',
            ])),
        ];

        return $sections;
    }

    /** Ein Zeitpunkt in deutscher Ortszeit; "-", wenn es keinen gibt. */
    private function zeit(mixed $wert): string
    {
        $zeit = LocalTime::for($wert);

        return $zeit === null ? '-' : $zeit->format('d.m.Y H:i').' Uhr';
    }
}
