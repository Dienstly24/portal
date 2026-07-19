<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\KrankenkasseType;
use Tests\TestCase;

/**
 * Versicherungsart (gesetzlich/privat) automatisch aus dem Kassennamen
 * ableiten - und nur dann, wenn der Name eindeutig ist (sonst leer, kein Raten).
 */
class KrankenkasseTypeTest extends TestCase
{
    public function test_resolves_known_gkv_and_pkv(): void
    {
        $this->assertSame('gesetzlich', KrankenkasseType::resolve('AOK Bayern'));
        $this->assertSame('gesetzlich', KrankenkasseType::resolve('Techniker Krankenkasse'));
        $this->assertSame('gesetzlich', KrankenkasseType::resolve('TK'));
        $this->assertSame('gesetzlich', KrankenkasseType::resolve('KKH Kaufmaennische Krankenkasse'));
        $this->assertSame('gesetzlich', KrankenkasseType::resolve('Barmer'));

        $this->assertSame('privat', KrankenkasseType::resolve('Debeka Krankenversicherung'));
        $this->assertSame('privat', KrankenkasseType::resolve('DKV Deutsche Krankenversicherung'));
        $this->assertSame('privat', KrankenkasseType::resolve('Signal Iduna'));
    }

    public function test_returns_null_for_unknown_or_empty(): void
    {
        $this->assertNull(KrankenkasseType::resolve('Irgendeine Unbekannte Kasse'));
        $this->assertNull(KrankenkasseType::resolve(''));
        $this->assertNull(KrankenkasseType::resolve(null));
    }

    public function test_validated_health_fills_type_from_company(): void
    {
        $obj = new class {
            use ValidatesExtractedFields;
            public function run(array $in): array { return $this->validatedHealth($in); }
        };

        // Nur Kasse angegeben, kein Typ -> automatisch abgeleitet.
        $this->assertSame('gesetzlich', $obj->run(['health_insurance_company' => 'AOK Nordost'])['health_insurance_type']);
        $this->assertSame('privat', $obj->run(['health_insurance_company' => 'Allianz Private Krankenversicherung'])['health_insurance_type']);

        // Unbekannte Kasse -> Typ bleibt leer (kein Raten).
        $this->assertArrayNotHasKey('health_insurance_type', $obj->run(['health_insurance_company' => 'Superkasse XY']));

        // Explizit gesetzter Typ hat Vorrang und wird nicht ueberschrieben.
        $this->assertSame('privat', $obj->run([
            'health_insurance_company' => 'AOK', 'health_insurance_type' => 'privat',
        ])['health_insurance_type']);
    }
}
