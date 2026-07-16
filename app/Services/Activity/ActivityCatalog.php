<?php
namespace App\Services\Activity;

use App\Models\SystemSetting;
use Illuminate\Support\Str;

/**
 * Katalog aller erfassten Aktionen (config/activity.php) inkl. der in
 * den Einstellungen ueberschreibbaren Punkte-Gewichte und Schwellwerte.
 * Neue Kriterien lassen sich ergaenzen, ohne Tracker/Reports anzufassen.
 */
class ActivityCatalog
{
    protected ?array $pointOverrides = null;

    /** @return array<string, array{label:string, category:string, points:int, productive:bool}> */
    public function actions(): array
    {
        return config('activity.actions', []);
    }

    public function has(string $action): bool
    {
        return array_key_exists($action, $this->actions());
    }

    public function labelFor(string $action): string
    {
        return $this->actions()[$action]['label'] ?? $action;
    }

    public function categoryFor(string $action): string
    {
        return $this->actions()[$action]['category'] ?? 'other';
    }

    public function isProductive(string $action): bool
    {
        return (bool) ($this->actions()[$action]['productive'] ?? false);
    }

    /** Default-Punkte aus der Config (ohne Overrides). */
    public function defaultPointsFor(string $action): int
    {
        return (int) ($this->actions()[$action]['points'] ?? 0);
    }

    /** Effektive Punkte: Einstellungs-Override vor Config-Default. */
    public function pointsFor(string $action): int
    {
        $overrides = $this->pointOverrides();
        if (array_key_exists($action, $overrides)) {
            return max(0, (int) $overrides[$action]);
        }
        return $this->defaultPointsFor($action);
    }

    /** @return array<string,int> Overrides aus den Einstellungen. */
    public function pointOverrides(): array
    {
        if ($this->pointOverrides === null) {
            $raw = SystemSetting::get('activity_points');
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            $this->pointOverrides = is_array($decoded) ? $decoded : [];
        }
        return $this->pointOverrides;
    }

    /** Max. anrechenbare Luecke zwischen produktiven Aktionen (Sekunden). */
    public function idleThresholdSeconds(): int
    {
        $minutes = (int) SystemSetting::get(
            'activity_idle_threshold_minutes',
            config('activity.idle_threshold_minutes', 5)
        );
        return max(60, $minutes * 60);
    }

    /** Sitzung gilt ohne Requests nach X Sekunden als beendet. */
    public function sessionTimeoutSeconds(): int
    {
        $minutes = (int) SystemSetting::get(
            'activity_session_timeout_minutes',
            config('activity.session_timeout_minutes', 30)
        );
        return max(300, $minutes * 60);
    }

    /** Komplett unsichtbar (kein Log, kein Praesenz-Update). */
    public function isIgnored(string $routeName): bool
    {
        return $this->matchesAny(config('activity.ignored_routes', []), $routeName);
    }

    /** Nicht protokolliert, zaehlt aber als Praesenz. */
    public function isUnlogged(string $routeName): bool
    {
        return $this->matchesAny(config('activity.unlogged_routes', []), $routeName);
    }

    /** Namenlose Auth-Pfade (z. B. POST /login): nicht protokollieren. */
    public function isUnloggedPath(string $path): bool
    {
        return $this->matchesAny(config('activity.unlogged_paths', []), trim($path, '/'));
    }

    /** Aktion fuer eine Schreiboperation (POST/PUT/PATCH/DELETE). */
    public function actionForWrite(?string $routeName, bool $hasFiles = false): string
    {
        if ($routeName !== null) {
            $mapped = $this->matchMap(config('activity.route_map', []), $routeName);
            if ($mapped !== null) {
                return $mapped;
            }
        }
        // Unbekannte Schreiboperation mit Datei-Upload -> als Upload werten.
        return $hasFiles ? 'datei_hochgeladen' : 'aktion_ausgefuehrt';
    }

    /** Aktion fuer einen GET-Request (Downloads/Exporte oder Seitenaufruf). */
    public function actionForGet(?string $routeName): string
    {
        if ($routeName !== null) {
            $mapped = $this->matchMap(config('activity.get_map', []), $routeName);
            if ($mapped !== null) {
                return $mapped;
            }
        }
        return 'seite_geoeffnet';
    }

    protected function matchMap(array $map, string $routeName): ?string
    {
        if (isset($map[$routeName])) {
            return $map[$routeName];
        }
        foreach ($map as $pattern => $action) {
            if (str_ends_with($pattern, '*') && Str::is($pattern, $routeName)) {
                return $action;
            }
        }
        return null;
    }

    protected function matchesAny(array $patterns, string $routeName): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern === $routeName || (str_contains($pattern, '*') && Str::is($pattern, $routeName))) {
                return true;
            }
        }
        return false;
    }
}
