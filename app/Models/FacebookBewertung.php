<?php

namespace App\Models;

use App\Support\PersonenName;
use App\Support\Tippgeber\ServiceSeitenTexte;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eine Empfehlung von der eigenen Facebook-Seite.
 *
 * REGELN (Betreiber-Wunsch 21.11.2026):
 * - Gezeigt wird NUR, was ein Mensch freigegeben hat (`status =
 *   freigegeben`), was positiv ist, Text hat und bei Facebook noch steht.
 * - Ein Kundentext wird NIE umformuliert oder gekuerzt. Passt er nicht,
 *   wird er nicht freigegeben.
 * - Tippgeber-Rolle: "Beratung" bei Strom/Gas/Internet ist echt und darf
 *   stehen. Spricht der Text von Beratung zu einer VERSICHERUNG, ist die
 *   Freigabe gesperrt (`freigabeGesperrt()`) - sonst stuende auf unserer
 *   Seite, dass wir Versicherungen beraten.
 * - Aendert der Verfasser seinen Text bei Facebook, faellt die Freigabe
 *   zurueck auf "neu": freigegeben wurde der alte Text, nicht der neue.
 */
class FacebookBewertung extends Model
{
    protected $table = 'facebook_bewertungen';

    public const STATUS = ['neu', 'freigegeben', 'abgelehnt'];

    /** Hoechstens so viele Empfehlungen stehen auf der Startseite. */
    public const MAX_ANZEIGE = 3;

    private const VERSICHERUNG = '/versicher|police|kfz|haftpflicht|hausrat|rechtsschutz|krankenkasse|krankenversicher|تأمين|بوليصة/iu';

    protected $fillable = [
        'fb_kennung', 'name_kurz', 'text', 'empfehlung', 'sterne', 'verfasst_at',
        'status', 'geprueft_von', 'geprueft_at', 'entfernt_at',
    ];

    protected function casts(): array
    {
        return [
            'verfasst_at' => 'datetime',
            'geprueft_at' => 'datetime',
            'entfernt_at' => 'datetime',
            'sterne' => 'integer',
        ];
    }

    public function pruefer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'geprueft_von');
    }

    /** Was die Website zeigen darf - die EINE Bedingung. */
    public function scopeSichtbar(Builder $q): Builder
    {
        return $q->where('status', 'freigegeben')
            ->whereNull('entfernt_at')
            ->whereNotNull('text')->where('text', '!=', '')
            ->where(fn (Builder $w) => $w->where('empfehlung', 'positive')->orWhere('sterne', '>=', 4));
    }

    public function istPositiv(): bool
    {
        return $this->empfehlung === 'positive' || (int) $this->sterne >= 4;
    }

    /**
     * Hinweise fuer die Pruefung im Admin.
     *
     * @return list<array{art: string, text: string}>
     */
    public function hinweise(): array
    {
        $text = (string) $this->text;
        $hinweise = [];

        if (trim($text) === '') {
            $hinweise[] = ['art' => 'sperre', 'text' => 'Kein Text - nur Empfehlungen mit Text werden gezeigt.'];
        }
        if (! $this->istPositiv()) {
            $hinweise[] = ['art' => 'sperre', 'text' => 'Keine positive Empfehlung - wird nicht als Kundenstimme gezeigt.'];
        }

        $beratung = ServiceSeitenTexte::funde($text) !== [];
        if ($beratung && preg_match(self::VERSICHERUNG, $text)) {
            $hinweise[] = ['art' => 'sperre', 'text' => 'Spricht von Beratung zu einer Versicherung - darf nicht auf die Website (keine Erlaubnis nach Paragraph 34d GewO).'];
        } elseif ($beratung) {
            $hinweise[] = ['art' => 'pruefen', 'text' => 'Spricht von Beratung - nur freigeben, wenn es um Strom, Gas oder Internet geht.'];
        } elseif (preg_match(self::VERSICHERUNG, $text)) {
            $hinweise[] = ['art' => 'pruefen', 'text' => 'Erwaehnt eine Versicherung - pruefen, dass der Text nicht nach Vermittlung durch uns klingt.'];
        }

        return $hinweise;
    }

    public function freigabeGesperrt(): ?string
    {
        foreach ($this->hinweise() as $h) {
            if ($h['art'] === 'sperre') {
                return $h['text'];
            }
        }

        return null;
    }

    /** "Ahmad Albhre" -> "Ahmad A." - der volle Name wird nie gespeichert. */
    public static function kurzname(?string $name): ?string
    {
        $teile = preg_split('/\s+/u', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($teile === []) {
            return null;
        }
        if (count($teile) === 1) {
            return mb_substr($teile[0], 0, 40);
        }
        // "Ahmad Al Bhre": der Artikel gehoert zum Nachnamen -> "Ahmad A."
        [$vor, $nach] = PersonenName::teile(implode(' ', array_slice($teile, 0, -1)), (string) end($teile));
        $vorname = mb_substr((string) (preg_split('/\s+/u', (string) $vor)[0] ?? $teile[0]), 0, 40);

        return $vorname.' '.mb_strtoupper(mb_substr((string) $nach, 0, 1)).'.';
    }
}
