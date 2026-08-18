<?php
namespace App\Services\Ai\Assistant;

use App\Models\AiKnowledgeEntry;
use Illuminate\Support\Collection;

/**
 * Suche in der freigegebenen Wissensbasis (Spezifikation Abschnitt 19).
 *
 * Bewusst eine EINFACHE Stichwortsuche (keine Vektor-Datenbank): der
 * Bestand ist klein und wird von Menschen gepflegt; eine nachvollziehbare
 * Suche passt zur Grundregel "nichts erfinden" besser als eine
 * Aehnlichkeit, die niemand pruefen kann. Findet sie nichts, gibt es
 * keinen Treffer - und der Assistent uebergibt an das Team.
 *
 * Der komplette aktive Bestand geht NICHT in den Prompt (Kosten und
 * Datenminimierung); das Modell fragt gezielt ueber das Tool nach.
 */
class KnowledgeBase
{
    /**
     * @return Collection<int,AiKnowledgeEntry> nach Trefferqualitaet sortiert
     */
    public function search(string $query, ?string $language = null, int $limit = 4, bool $publicOnly = false): Collection
    {
        $terms = $this->terms($query);
        if ($terms === []) {
            return collect();
        }

        $entries = AiKnowledgeEntry::active()
            // Website-Besucher sehen nur oeffentlich geeignete Kategorien -
            // interne Ablaeufe und Leitfaeden bleiben im Haus.
            ->when($publicOnly, fn ($q) => $q->whereIn('category', AiKnowledgeEntry::PUBLIC_CATEGORIES))
            // Sprachneutrale Eintraege gelten immer, sprachgebundene nur
            // fuer ihre Sprache.
            ->when($language, fn ($q) => $q->where(function ($q) use ($language) {
                $q->whereNull('language')->orWhere('language', $language);
            }))
            ->get();

        return $entries
            ->map(fn (AiKnowledgeEntry $e) => ['entry' => $e, 'score' => $this->score($e, $terms)])
            ->filter(fn ($row) => $row['score'] > 0)
            ->sortByDesc('score')
            ->take($limit)
            ->pluck('entry')
            ->values();
    }

    /**
     * Suchbegriffe: Woerter ab 4 Zeichen (kuerzere treffen zu breit),
     * umlaut-normalisiert. Arabische Begriffe bleiben unveraendert.
     *
     * @return list<string>
     */
    private function terms(string $query): array
    {
        $normalized = $this->normalize($query);
        $words = preg_split('/[^\p{L}\p{N}]+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter(
            $words,
            fn ($w) => mb_strlen($w) >= 4
        )));
    }

    /**
     * Treffer zaehlen: Titel und Stichwoerter wiegen schwerer als der
     * Fliesstext - der Titel beschreibt das Thema, der Text erwaehnt es
     * vielleicht nur beilaeufig.
     *
     * @param list<string> $terms
     */
    private function score(AiKnowledgeEntry $entry, array $terms): int
    {
        $title = $this->normalize((string) $entry->title);
        $keywords = $this->normalize((string) $entry->keywords);
        $content = $this->normalize((string) $entry->content);

        $score = 0;
        foreach ($terms as $term) {
            if (str_contains($title, $term)) {
                $score += 5;
            }
            if (str_contains($keywords, $term)) {
                $score += 4;
            }
            if (str_contains($content, $term)) {
                $score += 1;
            }
        }

        return $score;
    }

    private function normalize(string $value): string
    {
        return str_replace(
            ['ä', 'ö', 'ü', 'ß'],
            ['ae', 'oe', 'ue', 'ss'],
            mb_strtolower($value)
        );
    }
}
