<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Eine oeffentliche Leistungsseite (z. B. "Kfz-Versicherung"). Texte liegen
 * zweisprachig vor (Feld_de / Feld_ar); der oeffentliche View waehlt das Feld
 * anhand der aktiven Sprache. Vollstaendig ueber die Adminoberflaeche pflegbar.
 */
class ServicePage extends Model
{
    protected $fillable = [
        'slug', 'category', 'icon',
        'title_de', 'title_ar', 'subtitle_de', 'subtitle_ar',
        'intro_de', 'intro_ar', 'highlights_de', 'highlights_ar', 'faq', 'fields',
        'image_path', 'meta_description_de', 'meta_description_ar',
        'is_active', 'sort_order', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'faq' => 'array',
        'fields' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /** Erlaubte Feldtypen fuer die zusaetzlichen Formularfelder. */
    public const FIELD_TYPES = ['text', 'tel', 'email', 'number', 'select', 'textarea'];

    /** Routen-Binding ueber den Slug statt der ID. */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeOrdered(Builder $q): Builder
    {
        return $q->orderBy('sort_order')->orderBy('id');
    }

    /** Lokalisierten Feldwert holen (ar mit Fallback auf de). */
    public function t(string $field): ?string
    {
        $locale = app()->getLocale();
        if ($locale === 'ar') {
            return $this->{$field . '_ar'} ?: $this->{$field . '_de'};
        }
        return $this->{$field . '_de'};
    }

    /** Kurzinfos als Array (eine pro Zeile), lokalisiert. */
    public function highlightList(): array
    {
        $raw = (string) $this->t('highlights');
        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $raw))));
    }

    /** FAQ lokalisiert als [{q, a}, ...]. */
    public function faqList(): array
    {
        $locale = app()->getLocale();
        $out = [];
        foreach ((array) $this->faq as $item) {
            $q = $locale === 'ar' ? ($item['q_ar'] ?? $item['q_de'] ?? '') : ($item['q_de'] ?? '');
            $a = $locale === 'ar' ? ($item['a_ar'] ?? $item['a_de'] ?? '') : ($item['a_de'] ?? '');
            if ($q !== '' || $a !== '') {
                $out[] = ['q' => $q, 'a' => $a];
            }
        }
        return $out;
    }

    /**
     * Zusaetzliche Formularfelder lokalisiert:
     * [['label'=>, 'type'=>, 'options'=>[], 'required'=>bool], ...].
     */
    public function fieldList(): array
    {
        $locale = app()->getLocale();
        $out = [];
        foreach ((array) $this->fields as $f) {
            $label = $locale === 'ar' ? ($f['label_ar'] ?? $f['label_de'] ?? '') : ($f['label_de'] ?? '');
            $label = $label !== '' ? $label : ($f['label_de'] ?? '');
            if ($label === '') {
                continue;
            }
            $type = in_array($f['type'] ?? 'text', self::FIELD_TYPES, true) ? $f['type'] : 'text';
            $optRaw = $locale === 'ar' ? ($f['options_ar'] ?? $f['options_de'] ?? '') : ($f['options_de'] ?? '');
            $options = array_values(array_filter(array_map('trim', explode(',', (string) $optRaw))));
            $out[] = [
                'label' => $label,
                'type' => $type,
                'options' => $options,
                'required' => (bool) ($f['required'] ?? false),
            ];
        }
        return $out;
    }

    /** Slug aus einem Titel erzeugen (fuer die Adminoberflaeche). */
    public static function slugify(string $value): string
    {
        return Str::slug($value);
    }
}
