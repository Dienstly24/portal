<?php

namespace Tests\Feature;

use App\Support\Sprache;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * KI-014 (Wissensbasis, 23.09.2026): `config/app.php` und `.env.example`
 * standen auf `APP_LOCALE=en`. Web-Anfragen merkten davon nichts -
 * `SetLocale` setzt dort immer de oder ar. Konsole und Warteschlange laufen
 * aber OHNE Middleware: geplante Laeufe, Importe und gequeuete Mails
 * arbeiteten auf Englisch (Validierungsmeldungen, Monatsnamen, jeder
 * `__()`-Text). Die Anwendung kennt nur Deutsch und Arabisch - eine
 * andere Sprache wird deshalb beim Start auf Deutsch gestellt, egal was in
 * einer (womoeglich aus der alten Vorlage kopierten) .env steht.
 */
class StandardspracheTest extends TestCase
{
    public function test_eine_fremde_sprache_wird_zu_deutsch(): void
    {
        config(['app.locale' => 'en']);
        app()->setLocale('en');

        Sprache::erzwingen(app());

        $this->assertSame('de', app()->getLocale());
        $this->assertSame('de', config('app.locale'));
    }

    public function test_arabisch_bleibt_arabisch(): void
    {
        config(['app.locale' => 'ar']);
        app()->setLocale('ar');

        Sprache::erzwingen(app());

        $this->assertSame('ar', app()->getLocale());
    }

    /**
     * Der Start der Anwendung selbst wendet die Regel an - ausserhalb jeder
     * Web-Anfrage (hier: Testlauf ohne HTTP, wie Konsole/Warteschlange).
     */
    public function test_ausserhalb_einer_web_anfrage_spricht_die_anwendung_deutsch_oder_arabisch(): void
    {
        $this->assertContains(app()->getLocale(), Sprache::UNTERSTUETZT);

        app()->setLocale('de');
        $meldung = Validator::make([], ['email' => 'required'])->errors()->first('email');
        $this->assertStringNotContainsString('field is required', $meldung);
    }

    public function test_die_vorlage_und_die_konfiguration_stehen_auf_deutsch(): void
    {
        $this->assertMatchesRegularExpression('/^APP_LOCALE=de$/m', (string) file_get_contents(base_path('.env.example')));
        $this->assertStringContainsString("env('APP_LOCALE', 'de')", (string) file_get_contents(config_path('app.php')));
    }
}
