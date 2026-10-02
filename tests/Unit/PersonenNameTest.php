<?php

namespace Tests\Unit;

use App\Support\PersonenName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Namenspartikel gehoeren an den Nachnamen. Die Regel "letztes Wort =
 * Nachname" macht aus "Yusuf Al Rahman" sonst den Vornamen "Yusuf Al".
 */
class PersonenNameTest extends TestCase
{
    /** @return list<array{0:?string,1:?string,2:?string,3:?string}> */
    public static function faelle(): array
    {
        return [
            // arabische Artikel und Abstammung
            'Al' => ['Yusuf Al', 'Rahman', 'Yusuf', 'Al Rahman'],
            'El' => ['Mona El', 'Sayed', 'Mona', 'El Sayed'],
            'Abu' => ['Ahmed Abu', 'Bakr', 'Ahmed', 'Abu Bakr'],
            'bin' => ['Khalid bin', 'Walid', 'Khalid', 'bin Walid'],
            'Ibn' => ['Hassan Ibn', 'Sina', 'Hassan', 'Ibn Sina'],
            'mehrere Vornamen' => ['Mohamad Adnan Al', 'Masri', 'Mohamad Adnan', 'Al Masri'],

            // europaeische Partikel - dieselbe Regel
            'van der' => ['Jan van der', 'Berg', 'Jan', 'van der Berg'],
            'von' => ['Friedrich von', 'Hayek', 'Friedrich', 'von Hayek'],
            'Le' => ['Jean Le', 'Blanc', 'Jean', 'Le Blanc'],
            'de la' => ['Juan de la', 'Cruz', 'Juan', 'de la Cruz'],
            'Van Damme' => ['Jean Claude Van', 'Damme', 'Jean Claude', 'Van Damme'],

            // unveraendert: kein Partikel im Spiel
            'gewoehnlich' => ['Max', 'Mustermann', 'Max', 'Mustermann'],
            'zwei Vornamen' => ['Lena Marie', 'Vossberg', 'Lena Marie', 'Vossberg'],
            'Partikel mitten drin' => ['Peter Vander', 'Meer', 'Peter Vander', 'Meer'],

            // Schutz: es bleibt IMMER ein Vorname uebrig
            'Al Pacino' => ['Al', 'Pacino', 'Al', 'Pacino'],
            'Van Gogh' => ['Van', 'Gogh', 'Van', 'Gogh'],

            // fehlende Teile werden nie erfunden
            'ohne Nachname' => ['Yusuf Al', null, 'Yusuf Al', null],
            'ohne Vorname' => [null, 'Rahman', null, 'Rahman'],
        ];
    }

    #[DataProvider('faelle')]
    public function test_partikel_wandern_an_den_nachnamen(
        ?string $vorher_vor, ?string $vorher_nach, ?string $erwartet_vor, ?string $erwartet_nach
    ): void {
        [$vor, $nach] = PersonenName::teile($vorher_vor, $vorher_nach);

        $this->assertSame($erwartet_vor, $vor);
        $this->assertSame($erwartet_nach, $nach);
    }

    public function test_abdul_wird_bewusst_nicht_angefasst(): void
    {
        // "Abdul Rahman" ist in aller Regel ein RUFname. Wer ihn zerschneidet,
        // macht aus einem Vornamen einen halben Nachnamen - im Zweifel bleibt
        // der Name, wie er ist.
        [$vor, $nach] = PersonenName::teile('Mohammed Abdul', 'Karim');

        $this->assertSame('Mohammed Abdul', $vor);
        $this->assertSame('Karim', $nach);
    }
}
