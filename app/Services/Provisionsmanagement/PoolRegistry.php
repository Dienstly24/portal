<?php
namespace App\Services\Provisionsmanagement;

use App\Models\CommissionPool;
use App\Services\CommissionImport\CommissionSourceProfile;

/**
 * DIE Auskunftsstelle fuer Pools und ihre Fristen.
 *
 * Alles, was ueber einen Pool wissen muss (Import, Statusberechnung,
 * Auswertung, Oberflaeche), fragt hier - und nicht die Tabelle direkt.
 * Grund: die Fristen sind eine FACHREGEL und duerfen nicht an vier Stellen
 * unterschiedlich ausgelegt werden. Faellt ein Pool aus der Tabelle (oder
 * traegt ein alter Datensatz einen Pool, den es nicht mehr gibt), liefert
 * diese Klasse den Standard, statt eine Ausnahme zu werfen: eine fehlende
 * Einstellung darf keine Provisionsliste unbenutzbar machen.
 */
class PoolRegistry
{
    /** Fristen, wenn ein Pool (noch) nicht gepflegt ist. */
    public const DEFAULT_EXPECTED_MONTHS = 3;
    public const DEFAULT_CHECK_MONTHS = 5;

    /** @var array<string,CommissionPool>|null */
    private ?array $cache = null;

    /** @return array<string,CommissionPool> key => Pool */
    public function all(): array
    {
        if ($this->cache === null) {
            $this->cache = CommissionPool::orderBy('name')->get()->keyBy('key')->all();
        }
        return $this->cache;
    }

    /** @return array<string,CommissionPool> */
    public function active(): array
    {
        return array_filter($this->all(), fn (CommissionPool $p) => $p->active);
    }

    public function find(?string $key): ?CommissionPool
    {
        return $key === null ? null : ($this->all()[$key] ?? null);
    }

    public function label(?string $key): string
    {
        return $this->find($key)?->name ?? ($key === null || $key === '' ? 'Ohne Pool' : $key);
    }

    public function expectedMonths(?string $key): int
    {
        return $this->find($key)?->expected_months ?? self::DEFAULT_EXPECTED_MONTHS;
    }

    public function checkMonths(?string $key): int
    {
        return $this->find($key)?->check_months ?? self::DEFAULT_CHECK_MONTHS;
    }

    /**
     * Welcher Pool passt zu einem erkannten Dateiprofil?
     *
     * Nur ein VORSCHLAG fuer die Auswahl im Import - die Entscheidung trifft
     * der Admin. Eine unbekannte Datei bekommt so keinen falschen Pool
     * untergeschoben, sondern gar keinen.
     */
    public function forProfile(?string $profile): ?string
    {
        if ($profile === null) {
            return null;
        }
        foreach ($this->all() as $key => $pool) {
            if ($pool->source_profile === $profile) {
                return $key;
            }
        }
        return null;
    }

    /** Auswahlliste fuer Formulare: key => Name (nur aktive Pools). */
    public function options(): array
    {
        $out = [];
        foreach ($this->active() as $key => $pool) {
            $out[$key] = $pool->name;
        }
        return $out;
    }

    /** Profil-Auswahl der Einstellungen: Dateiprofil => Klartext. */
    public function profileOptions(): array
    {
        $out = ['' => 'Kein festes Dateiformat'];
        foreach (array_keys(CommissionSourceProfile::PROFILES) as $key) {
            $out[$key] = CommissionSourceProfile::label($key);
        }
        return $out;
    }

    public function forget(): void
    {
        $this->cache = null;
    }
}
