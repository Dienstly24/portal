<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Freigegebene Wissensbasis des KI-Assistenten (Spezifikation
 * Abschnitt 19). Grundregel: was hier NICHT steht und nicht in den
 * Kundendaten steht, darf die KI nicht behaupten - dann uebernimmt ein
 * Mitarbeiter. Deshalb ist die Pflege eine bewusste Mitarbeiter-Aktion
 * (Beraterwelt), nichts entsteht automatisch.
 */
class AiKnowledgeEntry extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    public const CATEGORIES = [
        'faq' => 'Häufige Fragen',
        'prozess' => 'Interner Prozess / Ablauf',
        'dokumente' => 'Benötigte Unterlagen',
        'produkt' => 'Produkte / Dienstleistungen',
        'eskalation' => 'Eskalationsregel',
        'leitfaden' => 'Gesprächsleitfaden (Stil & Ablauf)',
    ];

    /**
     * Was ein NICHT ANGEMELDETER Website-Besucher zu sehen bekommen darf
     * (Spezifikation Abschnitte 19 und 22).
     *
     * Interne Ablaeufe, Eskalationsregeln und Gespraechsleitfaeden sind
     * Arbeitsanweisungen fuer das Team - sie gehoeren nicht auf die
     * oeffentliche Website, auch nicht sinngemaess durch eine KI
     * wiedergegeben.
     */
    public const PUBLIC_CATEGORIES = ['faq', 'dokumente', 'produkt'];

    public const LANGUAGES = ['de' => 'Deutsch', 'ar' => 'Arabisch', 'en' => 'Englisch'];

    protected $fillable = [
        'title', 'category', 'content', 'language', 'keywords', 'active',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    protected static function boot()
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function editor() { return $this->belongsTo(User::class, 'updated_by'); }

    public function scopeActive($q) { return $q->where('active', true); }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }

    public function languageLabel(): string
    {
        return $this->language ? (self::LANGUAGES[$this->language] ?? $this->language) : 'Alle Sprachen';
    }
}
