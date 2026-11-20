<?php

namespace App\Console\Commands;

use App\Http\Controllers\ServicePageController;
use App\Models\AiKnowledgeEntry;
use App\Models\ServicePage;
use App\Support\Tippgeber\ServiceSeitenTexte;
use Database\Seeders\ServicePageSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Stellt die Texte der Leistungsseiten in der DATENBANK auf die
 * Tippgeber-Rolle um (Betreiber-Vorgabe 20.11.2026).
 *
 * - Die Seite "versicherungsmakler" wird zu "so-arbeiten-wir" (neuer
 *   Inhalt aus dem Seeder; die alte Adresse leitet per 301 um).
 * - Auf allen anderen Seiten werden nur die bekannten Saetze ersetzt
 *   (ServiceSeitenTexte) - selbst gepflegter Text bleibt stehen und wird
 *   als Rest-Fund gemeldet.
 * - Vor dem Schreiben wird JEDE geaenderte Seite vollstaendig gesichert
 *   (storage/app/private/backups/...). Geloescht wird nichts.
 *
 * Ohne --schreiben ein reiner Probelauf. Idempotent: ein zweiter Lauf
 * findet nichts mehr.
 */
class WebsiteTippgeberTexte extends Command
{
    protected $signature = 'website:tippgeber-texte {--schreiben : Aenderungen wirklich speichern}';

    protected $description = 'Leistungsseiten: Beratungs-/Makler-Texte neutral umstellen (Tippgeber-Rolle)';

    /** Textspalten, die ersetzt werden. */
    public const SPALTEN = [
        'title_de', 'title_ar', 'subtitle_de', 'subtitle_ar', 'intro_de', 'intro_ar',
        'highlights_de', 'highlights_ar', 'body_de', 'body_ar', 'meta_description_de', 'meta_description_ar',
    ];

    public function handle(): int
    {
        $schreiben = (bool) $this->option('schreiben');
        $sicherung = [];
        $geaendert = 0;

        // 1) Makler-Seite -> "So arbeiten wir"
        foreach (ServicePageController::ALTE_SLUGS as $alt => $neu) {
            $seite = ServicePage::where('slug', $alt)->first();
            if (! $seite) {
                continue;
            }
            if (ServicePage::where('slug', $neu)->exists()) {
                $this->warn("Seite '{$alt}' existiert neben '{$neu}' - bitte im Admin entscheiden (nichts geaendert).");

                continue;
            }
            $vorlage = collect((new ServicePageSeeder)->pages())->firstWhere('slug', $neu);
            if (! $vorlage) {
                continue;
            }
            $sicherung[] = $seite->getAttributes();
            $this->line("Seite '{$alt}' -> '{$neu}' (neuer Inhalt).");
            if ($schreiben) {
                $seite->forceFill(['slug' => $neu] + collect($vorlage)->only(array_merge(self::SPALTEN, ['faq', 'fields']))->all())->save();
            }
            $geaendert++;
        }

        // 2) Alle Seiten: bekannte Saetze ersetzen
        foreach (ServicePage::orderBy('id')->get() as $seite) {
            $neu = [];
            foreach (self::SPALTEN as $spalte) {
                $wert = $seite->{$spalte};
                $ersetzt = ServiceSeitenTexte::anwenden($wert);
                if ($ersetzt !== $wert) {
                    $neu[$spalte] = $ersetzt;
                }
            }
            $faq = $seite->faq;
            if (is_array($faq)) {
                $neueFaq = $faq;
                array_walk_recursive($neueFaq, function (&$v) {
                    if (is_string($v)) {
                        $v = ServiceSeitenTexte::anwenden($v);
                    }
                });
                if ($neueFaq !== $faq) {
                    $neu['faq'] = $neueFaq;
                }
            }
            if ($neu === []) {
                continue;
            }
            $sicherung[] = $seite->getAttributes();
            $this->line("Seite '{$seite->slug}': ".count($neu).' Feld(er) neutral umgestellt.');
            if ($schreiben) {
                $seite->forceFill($neu)->save();
            }
            $geaendert++;
        }

        // 2b) KI-Wissensbasis: Eintraege, die woertlich aus den Leistungsseiten
        // uebernommen wurden (ki:wissensbasis-vorschlag) - sonst wiederholte
        // der Assistent die alten Aussagen. Von Hand angelegte Eintraege
        // (ohne Quelle) werden nicht angefasst.
        foreach (AiKnowledgeEntry::where('source_key', 'like', 'servicepage:%')->orderBy('id')->get() as $eintrag) {
            $neu = [];
            foreach (['title', 'content'] as $spalte) {
                $ersetzt = ServiceSeitenTexte::anwenden($eintrag->{$spalte});
                if ($ersetzt !== $eintrag->{$spalte}) {
                    $neu[$spalte] = $ersetzt;
                }
            }
            if ($neu === []) {
                continue;
            }
            $sicherung[] = ['ai_knowledge_entry' => $eintrag->getAttributes()];
            $this->line("Wissensbasis-Eintrag #{$eintrag->id}: neutral umgestellt.");
            if ($schreiben) {
                $eintrag->forceFill($neu)->save();
            }
            $geaendert++;
        }

        if ($schreiben && $sicherung !== []) {
            $pfad = 'backups/leistungsseiten-vor-tippgeber-'.now()->format('Ymd-His').'.json';
            Storage::disk('local')->put($pfad, json_encode($sicherung, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info("Sicherung der alten Texte: storage/app/private/{$pfad}");
        }

        // 3) Rest-Funde (z.B. selbst geschriebene Texte) melden
        $reste = 0;
        foreach (ServicePage::orderBy('id')->get() as $seite) {
            $texte = array_map(fn ($s) => (string) $seite->{$s}, self::SPALTEN);
            $faqTexte = (array) $seite->faq;
            array_walk_recursive($faqTexte, function ($v) use (&$texte) {
                $texte[] = (string) $v;
            });
            $funde = ServiceSeitenTexte::funde(implode("\n", $texte));
            if ($funde !== []) {
                $reste++;
                $this->warn("Bitte im Admin pruefen: '{$seite->slug}' enthaelt noch: ".implode(', ', $funde));
            }
        }

        foreach (AiKnowledgeEntry::where('active', true)->orderBy('id')->get() as $eintrag) {
            $funde = ServiceSeitenTexte::funde($eintrag->title."\n".$eintrag->content);
            if ($funde !== []) {
                $reste++;
                $this->warn("Bitte unter /admin/ki-wissensbasis pruefen: Eintrag #{$eintrag->id} enthaelt noch: ".implode(', ', $funde));
            }
        }

        $this->info(($schreiben ? 'Geaendert' : 'Wuerde aendern').": {$geaendert} Seite(n). Rest-Funde: {$reste}.");
        if (! $schreiben) {
            $this->comment('Probelauf - nichts gespeichert. Mit --schreiben ausfuehren.');
        }

        return self::SUCCESS;
    }
}
