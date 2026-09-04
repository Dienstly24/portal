<?php

namespace App\Console\Commands;

use App\Models\ServicePage;
use App\Services\UmlautRepair;
use Illuminate\Console\Command;

/**
 * P0-7: Repariert fehlende Umlaute in den oeffentlichen Leistungsseiten
 * ("verstaendlich erklaert" -> "verständlich erklärt"). Konservative
 * Wortliste (UmlautRepair), laeuft ueber alle deutschen Textfelder inkl.
 * FAQ- und Formularfeld-JSON. Einmal auf dem Server ausfuehren:
 *   php artisan service-pages:fix-umlauts          (Vorschau)
 *   php artisan service-pages:fix-umlauts --write  (schreiben)
 */
class FixServicePageUmlauts extends Command
{
    protected $signature = 'service-pages:fix-umlauts {--write : Aenderungen speichern (sonst nur Vorschau)}';

    protected $description = 'Repariert fehlende Umlaute (ae/oe/ue) in den Leistungsseiten-Texten';

    private const TEXT_FIELDS = ['title_de', 'subtitle_de', 'intro_de', 'highlights_de', 'body_de', 'meta_description_de', 'providers'];

    public function handle(): int
    {
        $write = (bool) $this->option('write');
        $changedPages = 0;

        foreach (ServicePage::all() as $page) {
            $changes = [];

            foreach (self::TEXT_FIELDS as $field) {
                $old = $page->{$field};
                $new = UmlautRepair::fix($old);
                if ($new !== $old) {
                    $changes[$field] = [$old, $new];
                    $page->{$field} = $new;
                }
            }

            // FAQ-JSON: Fragen/Antworten Deutsch.
            $faq = $page->faq;
            if (is_array($faq)) {
                $faqChanged = false;
                foreach ($faq as $i => $item) {
                    foreach (['q_de', 'a_de'] as $key) {
                        $old = $item[$key] ?? null;
                        $new = UmlautRepair::fix($old);
                        if ($new !== $old) {
                            $faq[$i][$key] = $new;
                            $faqChanged = true;
                        }
                    }
                }
                if ($faqChanged) {
                    $changes['faq'] = ['(JSON)', '(repariert)'];
                    $page->faq = $faq;
                }
            }

            // Formularfelder-JSON: Labels/Optionen Deutsch.
            $fields = $page->fields;
            if (is_array($fields)) {
                $fieldsChanged = false;
                foreach ($fields as $i => $item) {
                    foreach (['label_de', 'options_de', 'placeholder_de'] as $key) {
                        $old = $item[$key] ?? null;
                        $new = UmlautRepair::fix($old);
                        if ($new !== $old) {
                            $fields[$i][$key] = $new;
                            $fieldsChanged = true;
                        }
                    }
                }
                if ($fieldsChanged) {
                    $changes['fields'] = ['(JSON)', '(repariert)'];
                    $page->fields = $fields;
                }
            }

            if ($changes === []) {
                continue;
            }
            $changedPages++;
            $this->line(($write ? 'Repariert' : 'Wuerde reparieren').': '.$page->slug);
            foreach ($changes as $field => [$old, $new]) {
                if ($field !== 'faq' && $field !== 'fields') {
                    $this->line('  - '.$field.': "'.mb_substr((string) $old, 0, 70).'" -> "'.mb_substr((string) $new, 0, 70).'"');
                } else {
                    $this->line('  - '.$field.' (JSON) repariert');
                }
            }

            if ($write) {
                $page->save();
            }
        }

        if ($changedPages === 0) {
            $this->info('Alle Leistungsseiten sind bereits korrekt.');
        } elseif (! $write) {
            $this->warn('Vorschau - nichts gespeichert. Mit --write anwenden.');
        } else {
            $this->info($changedPages.' Seite(n) repariert.');
        }

        return self::SUCCESS;
    }
}
