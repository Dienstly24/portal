<?php

namespace App\Http\Requests\Admin;

/**
 * ARCH-6: die Beschreibungsfelder eines Dokuments (Kategorie, Sichtbarkeit,
 * Farbe, Vertragszuordnung).
 *
 * Sie stehen sowohl beim Hochladen als auch beim Bearbeiten - bisher als
 * zwei identische Bloecke in einem Controller, keine drei Bildschirmseiten
 * auseinander. Eine neue Kategorie waere in einem davon gelandet.
 *
 * BEWUSST NICHT hier: die Pruefung, ob der gewaehlte Vertrag zu DIESEM
 * Kunden gehoert. Das ist keine Formatregel, sondern eine Zugriffsfrage
 * (Fremdzuordnung verhindern) und braucht den Kunden aus dem Pfad - sie
 * bleibt im Controller, wo sie schon war.
 */
class CustomerDocumentMetaRules
{
    /**
     * @return array<string, string>
     */
    public static function rules(): array
    {
        return [
            'category' => 'nullable|in:contract,police,invoice,identity,claim,other',
            'visibility' => 'nullable|in:customer,internal',
            'color' => 'nullable|in:green,yellow,red',
            'contract_id' => 'nullable|string',
        ];
    }
}
