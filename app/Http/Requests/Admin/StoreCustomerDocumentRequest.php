<?php

namespace App\Http\Requests\Admin;

use App\Support\UploadRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ARCH-6: Dokumente in die Kundenakte hochladen.
 *
 * AUTHORISIERUNG BLEIBT, WO SIE WAR. Der Zugriff auf den Kunden wird
 * weiterhin im Controller ueber authorizeCustomerAccess() geprueft, die
 * Rolle ueber die Route-Middleware. Hier true zurueckzugeben ist deshalb
 * kein Loch, sondern die bewusste Entscheidung, die Pruefung NICHT zu
 * verschieben - eine verschobene Berechtigungspruefung ist genau die Art
 * Aenderung, die bei einem Umbau still etwas anderes tut als vorher.
 */
class StoreCustomerDocumentRequest extends FormRequest
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
            'documents' => 'required|array|min:1|max:20',
            'documents.*' => UploadRules::each(UploadRules::DOCUMENT_MIMES),
        ] + CustomerDocumentMetaRules::rules();
    }
}
