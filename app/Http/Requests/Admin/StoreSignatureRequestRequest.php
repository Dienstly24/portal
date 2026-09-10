<?php

namespace App\Http\Requests\Admin;

use App\Support\UploadRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Regeln fuer eine neue Signaturanfrage.
 *
 * Die Datei wird bewusst ENG gefasst: nur PDF. Ein Bild oder eine
 * Word-Datei liesse sich weder seitenweise anzeigen noch mit einer
 * Unterschrift versehen, ohne vorher umgewandelt zu werden - und eine
 * stillschweigende Umwandlung waere ein anderes Dokument als das, was der
 * Mitarbeiter hochgeladen hat.
 *
 * Die Berechtigung prueft der Controller ueber die Policy (dort steht der
 * Kundenbezug); hier geht es ausschliesslich um das FORMAT (ARCH-6).
 */
class StoreSignatureRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isStaff() === true;
    }

    public function rules(): array
    {
        return [
            'document' => ['required', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:'.UploadRules::MAX_KB],
            'title' => ['required', 'string', 'max:180'],
            'customer_id' => ['nullable', 'string', 'exists:customers,id'],
            'contract_id' => ['nullable', 'string', 'exists:contracts,id'],
            'signing_order' => ['nullable', 'in:sequential,parallel'],
            'require_email_verification' => ['nullable', 'boolean'],
            'consent_text' => ['nullable', 'string', 'max:2000'],
            'document_type' => ['nullable', 'string', 'max:60'],
            'reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:2000'],
            'expires_at' => ['nullable', 'date', 'after:today'],

            'signers' => ['array', 'max:10'],
            'signers.*.name' => ['required_with:signers.*.email', 'string', 'max:160'],
            'signers.*.email' => ['nullable', 'email:filter', 'max:190'],
        ];
    }

    public function messages(): array
    {
        return [
            'document.mimes' => 'Es können nur PDF-Dateien zur Unterschrift versendet werden.',
            'document.mimetypes' => 'Die Datei ist kein PDF.',
            'expires_at.after' => 'Das Ablaufdatum muss in der Zukunft liegen.',
        ];
    }
}
