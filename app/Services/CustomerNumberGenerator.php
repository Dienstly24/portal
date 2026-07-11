<?php
namespace App\Services;

use App\Models\Customer;
use Illuminate\Support\Str;

/**
 * Zentraler Kundennummern-Generator (Architekturplan Abschnitt 6).
 * Ersetzt die zuvor an fünf Stellen duplizierte Inline-Erzeugung
 * ('C-' . strtoupper(Str::random(8))) in AdminController,
 * SelfServiceController, ImportExportController, LexofficeController
 * und PortalController - jetzt ein einziger Codepfad für Web-Formular,
 * Self-Service, Import, Lexoffice-Import und (neu) automatisierte
 * Kundenanlage aus der E-Mail-/Fonds-Finanz-Pipeline.
 */
class CustomerNumberGenerator
{
    public function generate(): string
    {
        do {
            $number = 'C-' . strtoupper(Str::random(8));
        } while (Customer::where('customer_number', $number)->exists());

        return $number;
    }
}
