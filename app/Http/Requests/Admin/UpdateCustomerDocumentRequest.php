<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ARCH-6: Beschreibungsfelder eines vorhandenen Dokuments aendern.
 *
 * Authorisierung bleibt im Controller (authorizeDocumentAccess) - siehe
 * StoreCustomerDocumentRequest.
 */
class UpdateCustomerDocumentRequest extends FormRequest
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
        return CustomerDocumentMetaRules::rules() + [
            'file_name' => 'nullable|string|max:255',
        ];
    }
}
