<?php

namespace App\Support;

use App\Models\Customer;

/**
 * Anrede fuer Erinnerungen: "Herr Nachname" / "Frau Nachname", wenn das
 * Geschlecht bekannt ist, sonst "Vorname Nachname". Nie leer und nie "null":
 * fehlt alles, liefert sie '' und die Vorlage schreibt nur "Guten Tag,".
 *
 * Der Nachname kommt ueber PersonenName - "Yusuf Al Rahman" ist Herr
 * Al Rahman, nicht Herr Rahman.
 */
final class ErinnerungsAnrede
{
    public static function fuer(?Customer $kunde, string $sprache = 'de'): string
    {
        $name = trim((string) ($kunde->user->name ?? ''));
        $name = preg_replace('/\s+/u', ' ', $name) ?? '';

        if ($name === '' || strcasecmp($name, 'null') === 0) {
            $firma = trim((string) ($kunde->company_name ?? ''));

            return $firma;
        }

        $nachname = self::nachname($name);
        $geschlecht = $kunde?->gender;

        if ($sprache === 'ar') {
            return match ($geschlecht) {
                'male' => 'السيد '.$nachname,
                'female' => 'السيدة '.$nachname,
                default => $name,
            };
        }

        return match ($geschlecht) {
            'male' => 'Herr '.$nachname,
            'female' => 'Frau '.$nachname,
            default => $name,
        };
    }

    private static function nachname(string $name): string
    {
        $teile = explode(' ', $name);
        if (count($teile) < 2) {
            return $name;
        }
        $nachname = (string) array_pop($teile);
        [, $nachname] = PersonenName::teile(implode(' ', $teile), $nachname);

        return (string) $nachname;
    }
}
