<?php

namespace App\Console\Commands;

use App\Models\AiKnowledgeEntry;
use App\Models\ServicePage;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Wissensbasis des KI-Assistenten aus BEREITS GEPFLEGTEN Inhalten
 * vorschlagen (Betreiber-Auftrag 18.08.2026).
 *
 * Ausgangslage: der Assistent ist fertig, aber die Wissensbasis ist leer -
 * und solange sie leer ist, uebergibt er fast jede allgemeine Frage an das
 * Team. Gleichzeitig stehen die Antworten laengst im System: die
 * Leistungsseiten der Website tragen je Sparte Einleitung, Leistungs-
 * punkte, Anbieterliste und zweisprachige haeufige Fragen - vom Betreiber
 * selbst geschrieben und oeffentlich sichtbar.
 *
 * Dieser Befehl uebertraegt genau diese Texte WOERTLICH in Entwuerfe.
 * Bewusst NICHT: umformulieren, zusammenfassen, ergaenzen oder aus
 * Weltwissen auffuellen - dann stuende in der Wissensbasis etwas, das
 * niemand geprueft hat, und der Assistent gaebe es als Auskunft weiter.
 *
 * Jeder Entwurf entsteht INAKTIV. Erst die Freigabe unter
 * /admin/ki-wissensbasis macht ihn zur Auskunft des Assistenten.
 */
class DraftKnowledgeBaseEntries extends Command
{
    protected $signature = 'ki:wissensbasis-vorschlag
        {--sprache=alle : Welche Sprachen (de, ar oder alle)}
        {--schreiben : Entwuerfe INAKTIV in der Wissensbasis anlegen}';

    protected $description = 'Wissensbasis-Entwürfe aus den gepflegten Leistungsseiten erzeugen (inaktiv, nie automatisch aktiv)';

    public function handle(): int
    {
        $sprache = strtolower(trim((string) $this->option('sprache')));
        if (!in_array($sprache, ['alle', 'de', 'ar'], true)) {
            $this->error('Unbekannte Sprache: ' . $sprache . ' (erlaubt: de, ar, alle)');

            return self::FAILURE;
        }

        $seiten = ServicePage::active()->ordered()->get();
        if ($seiten->isEmpty()) {
            $this->warn('Keine aktiven Leistungsseiten vorhanden.');
            $this->line('Der Befehl uebertraegt vorhandene Texte - ohne Leistungsseiten gibt es nichts zu uebertragen.');
            $this->line("Leistungsseiten pflegen: /admin/service-pages");

            return self::FAILURE;
        }

        $kandidaten = [];
        foreach ($seiten as $seite) {
            foreach ($this->kandidatenFuerSeite($seite) as $kandidat) {
                if ($sprache !== 'alle' && $kandidat['language'] !== $sprache) {
                    continue;
                }
                $kandidaten[] = $kandidat;
            }
        }

        if ($kandidaten === []) {
            $this->warn('Keine uebertragbaren Texte gefunden (Einleitung bzw. haeufige Fragen sind leer).');

            return self::FAILURE;
        }

        // Zweiter Lauf legt nichts doppelt an: die Quelle ist der Schluessel.
        $vorhanden = AiKnowledgeEntry::whereIn('source_key', array_column($kandidaten, 'source_key'))
            ->pluck('source_key')
            ->all();
        $neu = array_values(array_filter(
            $kandidaten,
            fn ($k) => !in_array($k['source_key'], $vorhanden, true)
        ));

        $this->info('Geprueft: ' . $seiten->count() . ' Leistungsseiten, ' . count($kandidaten) . ' uebertragbare Texte.');
        $this->line('  Bereits in der Wissensbasis: ' . count($vorhanden));
        $this->line('  Neu:                         ' . count($neu));

        if ($neu === []) {
            $this->newLine();
            $this->info('Nichts zu tun - alle Texte sind bereits uebernommen.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->table(
            ['Kategorie', 'Sprache', 'Titel', 'Zeichen'],
            array_map(fn ($k) => [
                AiKnowledgeEntry::CATEGORIES[$k['category']] ?? $k['category'],
                $k['language'],
                Str::limit($k['title'], 60),
                mb_strlen($k['content']),
            ], $neu)
        );

        if (!$this->option('schreiben')) {
            $this->newLine();
            $this->line('Nur angezeigt, nichts gespeichert. Zum Anlegen (INAKTIV, zur Freigabe):');
            $this->line('  php artisan ki:wissensbasis-vorschlag --schreiben');

            return self::SUCCESS;
        }

        foreach ($neu as $kandidat) {
            AiKnowledgeEntry::create($kandidat + ['active' => false]);
        }

        $this->newLine();
        $this->info(count($neu) . ' Entwuerfe angelegt - alle INAKTIV.');
        $this->line('Freigeben unter /admin/ki-wissensbasis (Filter "Nur Entwürfe", Sammelfreigabe).');
        $this->line('Vorher lesen: der Assistent gibt diese Texte sinngemaess an Kunden weiter.');

        return self::SUCCESS;
    }

    /**
     * Die uebertragbaren Texte EINER Leistungsseite.
     *
     * @return list<array<string,string>>
     */
    private function kandidatenFuerSeite(ServicePage $seite): array
    {
        $kandidaten = [];

        foreach (['de', 'ar'] as $lang) {
            $titel = trim((string) $seite->{'title_' . $lang});
            $einleitung = trim((string) $seite->{'intro_' . $lang});
            if ($titel === '' || $einleitung === '') {
                continue;
            }

            $kandidaten[] = [
                'source_key' => 'servicepage:' . $seite->slug . ':produkt:' . $lang,
                'title' => Str::limit($this->produktTitel($titel, $lang), 250, ''),
                'category' => 'produkt',
                'language' => $lang,
                'content' => $this->produktInhalt($seite, $lang),
                'keywords' => $this->stichwoerter($seite, $titel),
            ];
        }

        foreach ((array) ($seite->faq ?? []) as $index => $eintrag) {
            foreach (['de', 'ar'] as $lang) {
                $frage = trim((string) ($eintrag['q_' . $lang] ?? ''));
                $antwort = trim((string) ($eintrag['a_' . $lang] ?? ''));
                if ($frage === '' || $antwort === '') {
                    continue;
                }

                $kandidaten[] = [
                    'source_key' => 'servicepage:' . $seite->slug . ':faq:' . $index . ':' . $lang,
                    'title' => Str::limit($frage, 250, ''),
                    'category' => 'faq',
                    'language' => $lang,
                    // Die Antwort woertlich, davor die Leistung als Bezug -
                    // ohne ihn steht "Die Beratung ist kostenlos" ohne Thema
                    // in der Wissensbasis und passt scheinbar ueberall.
                    'content' => $this->bezug($seite, $lang) . "\n\n" . $antwort,
                    'keywords' => $this->stichwoerter($seite, $frage),
                ];
            }
        }

        return $kandidaten;
    }

    private function produktTitel(string $titel, string $lang): string
    {
        return $lang === 'ar' ? 'الخدمة: ' . $titel : 'Leistung: ' . $titel;
    }

    private function bezug(ServicePage $seite, string $lang): string
    {
        $titel = trim((string) ($seite->{'title_' . $lang} ?: $seite->title_de));

        return $lang === 'ar' ? 'يخص: ' . $titel : 'Betrifft: ' . $titel;
    }

    /**
     * Einleitung + Leistungspunkte + Anbieter - alles woertlich von der
     * Seite. Der ausfuehrliche Fliesstext (body) bleibt bewusst draussen:
     * er ist ein Website-Text mit Zwischenueberschriften und wuerde die
     * Stichwortsuche verwaessern, statt eine Frage zu beantworten.
     */
    private function produktInhalt(ServicePage $seite, string $lang): string
    {
        $teile = [trim((string) $seite->{'intro_' . $lang})];

        $punkte = $this->zeilen((string) $seite->{'highlights_' . $lang});
        if ($punkte !== []) {
            $teile[] = ($lang === 'ar' ? 'ما بنقدّمه:' : 'Das bieten wir:') . "\n"
                . implode("\n", array_map(fn ($p) => '- ' . $p, $punkte));
        }

        // Die Anbieterliste ist sprachneutral (Eigennamen) und steht nur
        // einmal auf der Seite - sie gilt fuer beide Sprachfassungen.
        $anbieter = $this->zeilen((string) $seite->providers);
        if ($anbieter !== []) {
            $teile[] = ($lang === 'ar' ? 'شركات منتعامل معها:' : 'Anbieter, mit denen wir arbeiten:') . ' '
                . implode(', ', $anbieter);
        }

        return Str::limit(implode("\n\n", $teile), 7900, '');
    }

    /** @return list<string> */
    private function zeilen(string $wert): array
    {
        return array_values(array_filter(array_map(
            'trim',
            preg_split('/\r\n|\r|\n/', $wert) ?: []
        ), fn ($z) => $z !== ''));
    }

    /**
     * Stichwoerter fuer die Suche: Slug-Bestandteile und die laengeren
     * Woerter des Titels. Die Suche (KnowledgeBase) wertet Titel und
     * Stichwoerter hoeher als den Fliesstext.
     */
    private function stichwoerter(ServicePage $seite, string $titel): string
    {
        $woerter = array_merge(
            explode('-', (string) $seite->slug),
            preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($titel), -1, PREG_SPLIT_NO_EMPTY) ?: []
        );

        $woerter = array_values(array_unique(array_filter(
            array_map('trim', $woerter),
            fn ($w) => mb_strlen($w) >= 4
        )));

        return Str::limit(implode(', ', $woerter), 490, '');
    }
}
