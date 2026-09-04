<?php

namespace Tests\Feature;

use App\Http\Requests\Admin\ReplaceCustomerDocumentRequest;
use App\Http\Requests\Admin\StoreCustomerDocumentRequest;
use App\Http\Requests\Admin\UpdateCustomerDocumentRequest;
use App\Support\UploadRules;
use Tests\TestCase;

/**
 * ARCH-6: die Datei-Regeln haben genau EINE Quelle - und sie sagt dasselbe
 * wie die Kopien vorher.
 *
 * Der Sinn dieser Tests ist die Gegenprobe zur Zusammenfassung: eine
 * "Vereinheitlichung", die dabei eine Regel weiter oder enger macht, ist
 * keine Aufraeumaktion, sondern eine unbemerkte Verhaltensaenderung - beim
 * Datei-Upload also eine Sicherheitsfrage.
 */
class UploadValidationTest extends TestCase
{
    public function test_die_groessengrenze_ist_unveraendert_zehn_megabyte(): void
    {
        $this->assertSame(10240, UploadRules::MAX_KB);
    }

    public function test_die_erlaubten_dateitypen_sind_unveraendert(): void
    {
        // Exakt die Listen, die vorher als Zeichenketten in den Controllern
        // standen - Reihenfolge inklusive, damit die Gegenprobe eindeutig ist.
        $this->assertSame('pdf,jpg,jpeg,png,webp', UploadRules::ATTACHMENT_MIMES);
        $this->assertSame('pdf,jpg,jpeg,png,doc,docx,xls,xlsx', UploadRules::DOCUMENT_MIMES);
        $this->assertSame('pdf,jpg,jpeg,png,webp', UploadRules::PROOF_MIMES);
    }

    public function test_die_regelketten_haben_die_erwartete_form(): void
    {
        $this->assertSame(
            ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
            UploadRules::required(UploadRules::ATTACHMENT_MIMES)
        );
        $this->assertSame(
            ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
            UploadRules::optional(UploadRules::PROOF_MIMES)
        );
        // Ohne "required": ob das ARRAY Pflicht ist, entscheidet die Regel am
        // Array. Stuende es hier, waere jeder optionale Anhang ploetzlich
        // Pflicht - der wahrscheinlichste Fehler bei so einer Umstellung.
        $this->assertSame(
            ['file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
            UploadRules::each(UploadRules::ATTACHMENT_MIMES)
        );
    }

    public function test_der_dokument_upload_prueft_dieselben_regeln_wie_vorher(): void
    {
        $regeln = (new StoreCustomerDocumentRequest)->rules();

        $this->assertSame('required|array|min:1|max:20', $regeln['documents']);
        $this->assertSame(
            ['file', 'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx', 'max:10240'],
            $regeln['documents.*']
        );
        $this->assertSame('nullable|in:contract,police,invoice,identity,claim,other', $regeln['category']);
        $this->assertSame('nullable|in:customer,internal', $regeln['visibility']);
        $this->assertSame('nullable|in:green,yellow,red', $regeln['color']);
        $this->assertSame('nullable|string', $regeln['contract_id']);
    }

    public function test_bearbeiten_und_ersetzen_pruefen_dieselben_regeln_wie_vorher(): void
    {
        $bearbeiten = (new UpdateCustomerDocumentRequest)->rules();
        $this->assertSame('nullable|in:contract,police,invoice,identity,claim,other', $bearbeiten['category']);
        $this->assertSame('nullable|string|max:255', $bearbeiten['file_name']);

        $ersetzen = (new ReplaceCustomerDocumentRequest)->rules();
        $this->assertSame(
            ['required', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx', 'max:10240'],
            $ersetzen['document']
        );
    }

    /**
     * Die Berechtigungspruefung wurde bewusst NICHT in die FormRequests
     * verschoben - sie haengt am Kunden bzw. Dokument aus dem Pfad und
     * bleibt im Controller. Eine verschobene Berechtigungspruefung ist
     * genau die Art Aenderung, die still etwas anderes tut als vorher.
     */
    public function test_die_berechtigungspruefung_blieb_im_controller(): void
    {
        $quelle = file_get_contents(app_path('Http/Controllers/Admin/CustomerDocumentController.php'));

        $this->assertStringContainsString('$this->authorizeCustomerAccess($id);', $quelle);
        $this->assertSame(5, substr_count($quelle, '$this->authorizeDocumentAccess($doc);'));
    }
}
