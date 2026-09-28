<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `activity_logs.meta` wird nie mehr doppelt kodiert
 * (System-Audit 28.09.2026, KI-036).
 */
class ActivityLogMetaKodierungTest extends TestCase
{
    use RefreshDatabase;

    public function test_vor_kodierte_meta_wird_als_json_objekt_gespeichert(): void
    {
        $log = ActivityLog::create(['action' => 'test', 'meta' => json_encode(['datei' => 'Übersicht.pdf'])]);

        $roh = DB::table('activity_logs')->where('id', $log->id)->value('meta');

        $this->assertIsArray(json_decode($roh, true), 'meta liegt doppelt kodiert in der Datenbank: '.$roh);
        $this->assertSame(['datei' => 'Übersicht.pdf'], $log->fresh()->meta);
        $this->assertSame(['datei' => 'Übersicht.pdf'], $log->fresh()->metaArray());
    }

    public function test_array_und_null_bleiben_wie_gehabt(): void
    {
        $this->assertSame(['a' => 1], ActivityLog::create(['action' => 't', 'meta' => ['a' => 1]])->fresh()->meta);
        $this->assertNull(ActivityLog::create(['action' => 't', 'meta' => null])->fresh()->meta);
    }

    public function test_ein_string_der_kein_objekt_ist_bleibt_erhalten(): void
    {
        $this->assertSame('frei', ActivityLog::create(['action' => 't', 'meta' => 'frei'])->fresh()->meta);
    }
}
