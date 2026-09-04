<?php

namespace App\Http\Requests\Admin;

use App\Support\UploadRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ARCH-6: die Datei eines vorhandenen Dokuments ersetzen.
 *
 * Authorisierung bleibt im Controller (authorizeDocumentAccess) - siehe
 * StoreCustomerDocumentRequest.
 */
class ReplaceCustomerDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'document' => UploadRules::required(UploadRules::DOCUMENT_MIMES),
        ];
    }
}
