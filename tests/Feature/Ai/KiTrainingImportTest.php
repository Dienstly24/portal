<?php

namespace Tests\Feature\Ai;

use App\Models\AiKnowledgeEntry;
use App\Models\AiTrainingExample;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Models\TrainingImport;
use App\Models\User;
use App\Services\Ai\Training\PiiRedactor;
use App\Services\Ai\Training\TrainingImportService;
use App\Services\Ai\Training\WhatsAppExportParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Phase 3: Verlauf hochladen, pruefen, schwaerzen, freigeben.
 *
 * Der Massstab dieser Datei: die Datei wird gelesen, wie sie WIRKLICH
 * aussieht (unsichtbare Zeichen, zwei Formate, mehrzeilige Nachrichten,
 * Systemzeilen); es wird nichts geraten (weder der Kunde noch die
 * Frage-Antwort-Richtung); der Verlauf bleibt STUMM; und keine
 * personenbezogene Angabe erreicht die Wissensbasis.
 */
class KiTrainingImportTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function kunde(string $name = 'Max Muster', array $felder = []): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => $name]);

        return Customer::create(array_merge([
            'user_id' => $user->id,
            'customer_number' => '26'.str_pad((string) $user->id, 5, '0', STR_PAD_LEFT),
            'preferred_lang' => 'de',
        ], $felder));
    }

    private function parser(): WhatsAppExportParser
    {
        return app(WhatsAppExportParser::class);
    }

    // ---------------------------------------------------------------
    // Die Datei, wie sie wirklich aussieht
    // ---------------------------------------------------------------

    /** Android-Format: "17.09.26, 14:03 - Name: Text". */
    public function test_1_das_android_format_wird_gelesen(): void
    {
        $ergebnis = $this->parser()->parse(implode("\n", [
            '17.09.26, 14:03 - Max Muster: Guten Tag, ich habe eine Frage zur Police.',
            '17.09.26, 14:05 - Dienstly24: Gerne, worum geht es denn?',
        ]));

        $this->assertCount(2, $ergebnis['messages']);
        $this->assertSame('Max Muster', $ergebnis['messages'][0]['sender']);
        $this->assertSame(['Max Muster' => 1, 'Dienstly24' => 1], $ergebnis['senders']);
    }

    /**
     * DIE FALLE, die eine Datei "einfach nicht funktionieren" laesst:
     * iOS setzt vor jede Zeile ein Links-nach-rechts-Zeichen und vor
     * AM/PM ein schmales geschuetztes Leerzeichen. Beide sind im Editor
     * unsichtbar - der Ausdruck findet nichts, und niemand sieht warum.
     */
    public function test_2_unsichtbare_zeichen_des_ios_exports_brechen_nichts(): void
    {
        $zeile = "\u{200E}[17.09.2026, 14:03:22] Max Muster: Hallo, ist mein Vertrag aktiv?";

        $ergebnis = $this->parser()->parse($zeile);

        $this->assertCount(1, $ergebnis['messages'], 'Die unsichtbaren Zeichen haben den Kopf zerstoert.');
        $this->assertSame('Max Muster', $ergebnis['messages'][0]['sender']);
        $this->assertNotNull($ergebnis['messages'][0]['sent_at']);
    }

    /**
     * Eine Folgezeile ohne Kopf gehoert zur VORHERIGEN Nachricht. Sie zu
     * einer eigenen zu machen zerlegt jeden mehrzeiligen Text in
     * Bruchstuecke ohne Absender.
     */
    public function test_3_mehrzeilige_nachrichten_bleiben_zusammen(): void
    {
        $ergebnis = $this->parser()->parse(implode("\n", [
            '17.09.26, 14:03 - Max Muster: Erste Zeile',
            'Zweite Zeile derselben Nachricht',
            '17.09.26, 14:04 - Dienstly24: Antwort',
        ]));

        $this->assertCount(2, $ergebnis['messages']);
        $this->assertStringContainsString('Zweite Zeile', $ergebnis['messages'][0]['body']);
    }

    /** Systemzeilen haben keinen Menschen als Urheber. */
    public function test_4_systemzeilen_werden_nicht_zu_nachrichten(): void
    {
        $ergebnis = $this->parser()->parse(implode("\n", [
            '17.09.26, 14:00 - Nachrichten und Anrufe sind Ende-zu-Ende-verschlüsselt.',
            '17.09.26, 14:03 - Max Muster: Echte Nachricht mit genug Text.',
        ]));

        $this->assertCount(1, $ergebnis['messages']);
        $this->assertSame(1, $ergebnis['system_lines']);
    }

    /** Fehlende Dateien muessen sichtbar bleiben - sonst sucht sie jemand. */
    public function test_5_medienhinweise_werden_als_solche_gezaehlt(): void
    {
        $ergebnis = $this->parser()->parse(
            '17.09.26, 14:03 - Max Muster: <Medien ausgeschlossen>'
        );

        $this->assertSame(1, $ergebnis['media_notes']);
        $this->assertTrue($ergebnis['messages'][0]['has_media_note']);
    }

    // ---------------------------------------------------------------
    // Schwaerzen
    // ---------------------------------------------------------------

    /** Die Kategorien, um die es geht - jede einzeln nachgewiesen. */
    public function test_6_personenbezogene_angaben_werden_geschwaerzt(): void
    {
        $text = 'Ich bin Max Muster, DE89370400440532013000, max@example.com, '
            .'Tel. 0170 1234567, geboren am 03.09.1988, Kundennummer 2600042.';

        $ergebnis = app(PiiRedactor::class)->redact($text, ['Max Muster']);

        foreach (['370400440532013000', 'max@example.com', '1234567', '03.09.1988', 'Max Muster'] as $rest) {
            $this->assertStringNotContainsString($rest, $ergebnis['text'], "Nicht geschwaerzt: {$rest}");
        }
        $this->assertStringContainsString(PiiRedactor::IBAN, $ergebnis['text']);
        $this->assertStringContainsString(PiiRedactor::EMAIL, $ergebnis['text']);
        $this->assertStringContainsString(PiiRedactor::NAME, $ergebnis['text']);
    }

    /**
     * REIHENFOLGE IST PROGRAMMLOGIK: wer die Telefonnummer vor der IBAN
     * schwaerzt, zerlegt die IBAN in Ziffernbloecke, und der Rest
     * entgeht jeder weiteren Pruefung.
     */
    public function test_7_die_iban_wird_als_ganzes_erkannt_nicht_in_stuecken(): void
    {
        $ergebnis = app(PiiRedactor::class)->redact('Meine IBAN: DE89 3704 0044 0532 0130 00');

        $this->assertStringContainsString(PiiRedactor::IBAN, $ergebnis['text']);
        $this->assertStringNotContainsString('3704', $ergebnis['text']);
        $this->assertStringNotContainsString(PiiRedactor::PHONE, $ergebnis['text']);
    }

    /**
     * Was nicht sicher zugeordnet wurde, wird GEMELDET. Ein stilles
     * "ist wohl nichts" waere die gefaehrlichste Variante.
     */
    public function test_8_nicht_zugeordnete_zahlenfolgen_werden_gemeldet(): void
    {
        $ergebnis = app(PiiRedactor::class)->redact('Der Schlüssel 123456 steht auf dem Zettel.');

        $this->assertNotSame([], $ergebnis['report']['warnings'],
            'Eine nicht zugeordnete Zahlenfolge muss gemeldet werden - ein stilles '
            .'"ist wohl nichts" waere die gefaehrlichste Variante.');
        $this->assertStringContainsString('123456', $ergebnis['report']['warnings'][0],
            'Die Warnung muss den Fund nennen, sonst kann sie niemand pruefen.');
    }

    /**
     * Eine ZU BREITE Schwaerzung ist harmlos, eine zu enge nicht. Bei
     * einer Kennung, die keinem Muster genau entspricht, verschwinden
     * die Ziffern trotzdem - lieber ein unscharfes Kennzeichen als eine
     * lesbare Nummer in einem Eintrag, den jeder Kunde sieht.
     */
    public function test_8b_auch_eine_unbekannte_kennung_verliert_ihre_ziffern(): void
    {
        $ergebnis = app(PiiRedactor::class)->redact('Zählernummer 1EBZ0103716819 laut Foto.');

        $this->assertStringNotContainsString('0103716819', $ergebnis['text']);
    }

    /**
     * Ein Name wird NUR geschwaerzt, wenn er bekannt ist. Namen
     * allgemein zu erkennen waere Raten - und wuerde gewoehnlichen Text
     * unlesbar machen.
     */
    public function test_9_unbekannte_woerter_bleiben_stehen(): void
    {
        $ergebnis = app(PiiRedactor::class)->redact('Im Mai ist der Beitrag fällig.', ['Max Muster']);

        $this->assertStringContainsString('Mai', $ergebnis['text']);
        $this->assertStringContainsString('Beitrag', $ergebnis['text']);
    }

    // ---------------------------------------------------------------
    // Der zweistufige Weg
    // ---------------------------------------------------------------

    private function hochladen(User $admin, ?string $inhalt = null): TrainingImport
    {
        $inhalt ??= implode("\n", [
            '17.09.26, 14:03 - Max Muster: Wie lange dauert die Bearbeitung meiner Kfz-Police?',
            '17.09.26, 14:05 - Dienstly24: Die Gesellschaft braucht in der Regel zehn Werktage.',
            '17.09.26, 14:20 - Max Muster: Und was kostet eine Änderung der Zahlweise?',
            '17.09.26, 14:22 - Dienstly24: Ein Wechsel der Zahlweise ist bei uns kostenfrei.',
        ]);

        $this->actingAs($admin)->post(route('admin.ki_training.store'), [
            'datei' => UploadedFile::fake()->createWithContent('chat.txt', $inhalt),
        ])->assertRedirect();

        return TrainingImport::latest('id')->firstOrFail();
    }

    /** Der Upload schreibt NICHTS in den Kundenverlauf. */
    public function test_10_der_upload_erzeugt_nur_einen_entwurf(): void
    {
        $import = $this->hochladen($this->admin());

        $this->assertSame(TrainingImport::STATUS_ENTWURF, $import->status);
        $this->assertSame(4, $import->messages()->count());
        $this->assertSame(0, CustomerMessage::count());
        $this->assertSame(0, Conversation::count());
    }

    /**
     * ZWEI Angaben werden nie geraten: welche Akte, und welcher
     * Absender wir sind. Ohne sie ist die Uebernahme gesperrt.
     */
    public function test_11_ohne_kunde_und_absender_wird_nicht_uebernommen(): void
    {
        $admin = $this->admin();
        $import = $this->hochladen($admin);

        $this->assertNotSame([], $import->blockers());

        $this->actingAs($admin)
            ->post(route('admin.ki_training.confirm', $import->id))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, CustomerMessage::count());
    }

    /** Ein Absender, der gar nicht in der Datei steht, wird abgelehnt. */
    public function test_12_ein_erfundener_absender_wird_abgelehnt(): void
    {
        $admin = $this->admin();
        $import = $this->hochladen($admin);

        $this->actingAs($admin)
            ->put(route('admin.ki_training.update', $import->id), ['business_sender' => 'Gibt Es Nicht'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNull($import->fresh()->business_sender);
    }

    private function vorbereiten(User $admin, TrainingImport $import, Customer $kunde): void
    {
        Channel::where('key', 'whatsapp')->update(['is_active' => true]);

        $this->actingAs($admin)->put(route('admin.ki_training.update', $import->id), [
            'customer_id' => $kunde->id,
            'business_sender' => 'Dienstly24',
        ])->assertRedirect();
    }

    /**
     * DIE EIGENTLICHE ZUSAGE: der Verlauf ist STUMM. Er ist sichtbar und
     * durchsuchbar, loest aber nichts aus - sonst bekaeme der Kunde beim
     * Einlesen eine Lawine von Antworten auf Monate alte Fragen.
     */
    public function test_13_der_uebernommene_verlauf_ist_stumm(): void
    {
        $admin = $this->admin();
        $kunde = $this->kunde();
        $import = $this->hochladen($admin);
        $this->vorbereiten($admin, $import, $kunde);

        $this->actingAs($admin)
            ->post(route('admin.ki_training.confirm', $import->id))
            ->assertRedirect()->assertSessionHas('success');

        $this->assertSame(4, CustomerMessage::count());

        foreach (CustomerMessage::all() as $nachricht) {
            $this->assertSame(CustomerMessage::SOURCE_HISTORICAL, $nachricht->source,
                'Eine importierte Nachricht darf nie als live gelten.');
            $this->assertNotNull($nachricht->read_at, 'Historie erzeugt nie einen Ungelesen-Stand.');
            // Die abgeleitete Kennung ist der Anker der Idempotenz - ohne
            // sie erzeugte ein zweiter Anlauf denselben Verlauf noch einmal.
            $this->assertStringStartsWith('training:'.$import->id.':',
                (string) $nachricht->external_message_id);
        }

        // Kein Versand: die ausgehenden Nachrichten haben keine
        // Plattform-Kennung aus einem echten Versand bekommen.
        $this->assertSame(0, CustomerMessage::whereNotNull('sent_at')->count());
    }

    /** Die Richtung folgt der Auswahl des Menschen, nicht einer Vermutung. */
    public function test_14_die_richtung_folgt_dem_gewaehlten_absender(): void
    {
        $admin = $this->admin();
        $kunde = $this->kunde();
        $import = $this->hochladen($admin);
        $this->vorbereiten($admin, $import, $kunde);

        $this->actingAs($admin)->post(route('admin.ki_training.confirm', $import->id));

        $this->assertSame(2, CustomerMessage::where('from_staff', true)->count());
        $this->assertSame(2, CustomerMessage::where('from_staff', false)->count());
    }

    /** Ein Import, den man nach einem Abbruch nicht wiederholen darf, ist wertlos. */
    public function test_15_ein_zweiter_lauf_erzeugt_keine_doppelten_nachrichten(): void
    {
        $admin = $this->admin();
        $kunde = $this->kunde();
        $import = $this->hochladen($admin);
        $this->vorbereiten($admin, $import, $kunde);

        $this->actingAs($admin)->post(route('admin.ki_training.confirm', $import->id));
        $this->assertSame(4, CustomerMessage::count());

        // Der Zustand steht auf "importiert" - ein zweiter Klick wird
        // abgewiesen, und selbst ein erzwungener Lauf bliebe folgenlos.
        $import->fresh()->forceFill(['status' => TrainingImport::STATUS_ENTWURF])->save();
        app(TrainingImportService::class)->confirm($import->fresh(), $admin);

        $this->assertSame(4, CustomerMessage::count());
    }

    // ---------------------------------------------------------------
    // Beispiele und Freigabe
    // ---------------------------------------------------------------

    /** Aus "Kunde fragt -> Betrieb antwortet" entsteht genau ein Paar. */
    public function test_16_es_entstehen_geschwaerzte_paare(): void
    {
        $admin = $this->admin();
        $kunde = $this->kunde();
        $import = $this->hochladen($admin);
        $this->vorbereiten($admin, $import, $kunde);

        $this->actingAs($admin)->post(route('admin.ki_training.confirm', $import->id));

        $this->assertSame(2, AiTrainingExample::count());
        foreach (AiTrainingExample::all() as $b) {
            $this->assertSame(AiTrainingExample::STATUS_OFFEN, $b->status,
                'Nichts wird automatisch zur Auskunft.');
        }
    }

    /**
     * Der Name des Kunden darf NIE in einem Beispiel stehen - ein
     * Wissenseintrag ist fuer jeden Kunden sichtbar.
     */
    public function test_17_kundendaten_erreichen_das_beispiel_nicht(): void
    {
        $admin = $this->admin();
        $kunde = $this->kunde('Mohamad Ali');
        $import = $this->hochladen($admin, implode("\n", [
            '17.09.26, 14:03 - Mohamad Ali: Hier ist Mohamad Ali, meine IBAN DE89370400440532013000.',
            '17.09.26, 14:05 - Dienstly24: Danke Mohamad Ali, wir haben die Bankverbindung geändert.',
        ]));

        Channel::where('key', 'whatsapp')->update(['is_active' => true]);
        $this->actingAs($admin)->put(route('admin.ki_training.update', $import->id), [
            'customer_id' => $kunde->id,
            'business_sender' => 'Dienstly24',
        ]);
        $this->actingAs($admin)->post(route('admin.ki_training.confirm', $import->id));

        $beispiel = AiTrainingExample::firstOrFail();

        $this->assertStringNotContainsString('Mohamad', $beispiel->question.$beispiel->answer);
        $this->assertStringNotContainsString('370400440532013000', $beispiel->question.$beispiel->answer);
    }

    /** Freigeben macht aus einem Beispiel eine Auskunft. */
    public function test_18_die_freigabe_erzeugt_einen_wissenseintrag(): void
    {
        $admin = $this->admin();
        $beispiel = AiTrainingExample::create([
            'question' => 'Wie lange dauert die Bearbeitung?',
            'answer' => 'In der Regel zehn Werktage.',
            'status' => AiTrainingExample::STATUS_OFFEN,
            'is_holdout' => false,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.ki_training.review', $beispiel->id), ['entscheidung' => 'freigeben'])
            ->assertRedirect();

        $eintrag = AiKnowledgeEntry::where('source_key', 'training:'.$beispiel->id)->firstOrFail();
        $this->assertTrue($eintrag->active);
        $this->assertSame('In der Regel zehn Werktage.', $eintrag->content);
        $this->assertSame($eintrag->id, $beispiel->fresh()->knowledge_entry_id);
    }

    /**
     * DIE WICHTIGSTE REGEL DER MESSUNG: der Pruefsatz kommt NIE in die
     * Wissensbasis. Wer die KI an genau den Antworten prueft, die er ihr
     * vorher gegeben hat, bekommt immer ein gutes und immer wertloses
     * Ergebnis.
     */
    public function test_19_ein_pruefsatz_wird_nie_zur_auskunft(): void
    {
        $admin = $this->admin();
        $beispiel = AiTrainingExample::create([
            'question' => 'Was kostet ein Wechsel der Zahlweise?',
            'answer' => 'Der Wechsel ist kostenfrei.',
            'status' => AiTrainingExample::STATUS_OFFEN,
            'is_holdout' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.ki_training.review', $beispiel->id), ['entscheidung' => 'freigeben'])
            ->assertRedirect();

        $this->assertSame(AiTrainingExample::STATUS_FREIGEGEBEN, $beispiel->fresh()->status);
        $this->assertNull($beispiel->fresh()->knowledge_entry_id);
        $this->assertSame(0, AiKnowledgeEntry::count());
    }

    /** Ein abgelehntes Beispiel wird nie benutzt. */
    public function test_20_ein_abgelehntes_beispiel_erzeugt_nichts(): void
    {
        $admin = $this->admin();
        $beispiel = AiTrainingExample::create([
            'question' => 'Frage', 'answer' => 'Veraltete Antwort',
            'status' => AiTrainingExample::STATUS_OFFEN, 'is_holdout' => false,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.ki_training.review', $beispiel->id), ['entscheidung' => 'ablehnen'])
            ->assertRedirect();

        $this->assertSame(AiTrainingExample::STATUS_ABGELEHNT, $beispiel->fresh()->status);
        $this->assertSame(0, AiKnowledgeEntry::count());
    }

    /** Ein Viertel wird zurueckgehalten - deterministisch, nicht zufaellig. */
    public function test_21_jedes_vierte_beispiel_ist_pruefsatz(): void
    {
        $admin = $this->admin();
        $kunde = $this->kunde();

        $zeilen = [];
        for ($i = 1; $i <= 8; $i++) {
            $zeilen[] = "17.09.26, 14:0{$i} - Max Muster: Frage Nummer {$i} mit ausreichend Text darin.";
            $zeilen[] = "17.09.26, 14:0{$i} - Dienstly24: Antwort Nummer {$i} mit ausreichend Text darin.";
        }

        $import = $this->hochladen($admin, implode("\n", $zeilen));
        $this->vorbereiten($admin, $import, $kunde);
        $this->actingAs($admin)->post(route('admin.ki_training.confirm', $import->id));

        $this->assertSame(8, AiTrainingExample::count());
        $this->assertSame(2, AiTrainingExample::where('is_holdout', true)->count());
    }

    /**
     * Die Seite gehoert der Verwaltung - hier entstehen Auskuenfte, die
     * der Assistent spaeter JEDEM Kunden gibt. Dieselbe Rollen-Grenze
     * wie bei der Wissensbasis; die Middleware fuehrt einen Mitarbeiter
     * auf sein Dashboard zurueck, statt eine Fehlerseite zu zeigen.
     */
    public function test_22_ein_mitarbeiter_kommt_nicht_hinein(): void
    {
        $mitarbeiter = User::factory()->create(['role' => 'employee']);

        $this->actingAs($mitarbeiter)
            ->get(route('admin.ki_training'))
            ->assertRedirect(route('admin.dashboard'));

        // Und der schreibende Weg ebenso wenig.
        $this->actingAs($mitarbeiter)
            ->post(route('admin.ki_training.store'), [
                'datei' => UploadedFile::fake()->createWithContent('chat.txt', '17.09.26, 14:03 - A: Text'),
            ])->assertRedirect(route('admin.dashboard'));

        $this->assertSame(0, TrainingImport::count());
    }
}
