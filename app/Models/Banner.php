<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Banner extends Model
{
    protected $fillable = [
        'title', 'media_path', 'media_type', 'link_url', 'link_target',
        'dismiss_days', 'is_active', 'is_draft', 'sort_order',
        'start_date', 'end_date', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_draft' => 'boolean',
        'start_date' => 'date',
        'end_date' => 'date',
        'last_shown_at' => 'datetime',
    ];

    public function dailyStats()
    {
        return $this->hasMany(BannerDailyStat::class);
    }

    public function userViews()
    {
        return $this->hasMany(BannerUserView::class);
    }

    /** Ausspielbare Banner: aktiv, kein Entwurf, im geplanten Zeitfenster. */
    public function scopeCurrent(Builder $q): Builder
    {
        $today = now()->toDateString();
        return $q->where('is_active', true)
            ->where('is_draft', false)
            ->where(fn ($w) => $w->whereNull('start_date')->orWhere('start_date', '<=', $today))
            ->where(fn ($w) => $w->whereNull('end_date')->orWhere('end_date', '>=', $today))
            ->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Abgeleiteter Status inkl. Anzeige-Farben:
     * draft -> disabled -> scheduled -> expired -> active.
     * Zeitsteuerung ist damit automatisch: erreicht das Startdatum den
     * heutigen Tag, wird der Banner ausgespielt; nach dem Enddatum nicht mehr.
     */
    public function statusInfo(): array
    {
        $today = now()->startOfDay();

        if ($this->is_draft) {
            return ['key' => 'draft', 'label' => 'Entwurf', 'color' => '#5F5E5A', 'bg' => '#F1EFE8'];
        }
        if (!$this->is_active) {
            return ['key' => 'disabled', 'label' => 'Deaktiviert', 'color' => '#A32D2D', 'bg' => '#F9E3E3'];
        }
        if ($this->start_date && $this->start_date->gt($today)) {
            return ['key' => 'scheduled', 'label' => 'Geplant', 'color' => '#185FA5', 'bg' => '#E6F1FB'];
        }
        if ($this->end_date && $this->end_date->lt($today)) {
            return ['key' => 'expired', 'label' => 'Abgelaufen', 'color' => '#92400E', 'bg' => '#FEF3C7'];
        }
        return ['key' => 'active', 'label' => 'Aktiv', 'color' => '#3B7A57', 'bg' => '#E4F0E7'];
    }

    /** Ausspielung an einen Kunden zählen (Gesamt + Tageswert + Betrachter). */
    public function recordImpression(?string $userId = null): void
    {
        $this->increment('total_impressions');
        $this->forceFill(['last_shown_at' => now()])->saveQuietly();

        $day = BannerDailyStat::firstOrCreate(['banner_id' => $this->id, 'date' => now()->toDateString()]);
        $day->increment('impressions');

        if ($userId) {
            $view = BannerUserView::firstOrCreate(['banner_id' => $this->id, 'user_id' => $userId]);
            $view->increment('views');
            $view->forceFill(['last_seen_at' => now()])->saveQuietly();
        }
    }

    /** Klick zählen. */
    public function recordClick(?string $userId = null): void
    {
        $this->increment('total_clicks');
        $day = BannerDailyStat::firstOrCreate(['banner_id' => $this->id, 'date' => now()->toDateString()]);
        $day->increment('clicks');
    }

    /** Klickrate in Prozent (0 wenn noch keine Ausspielungen). */
    public function ctr(): float
    {
        return $this->total_impressions > 0
            ? round($this->total_clicks / $this->total_impressions * 100, 1)
            : 0.0;
    }

    /** Impressions in einem Zeitfenster (heute = 1, Woche = 7, Monat = 30). */
    public function impressionsSince(int $days): int
    {
        return (int) $this->dailyStats()
            ->where('date', '>=', now()->subDays($days - 1)->toDateString())
            ->sum('impressions');
    }

    /** Anzahl eindeutiger Kunden, die den Banner gesehen haben. */
    public function uniqueViewers(): int
    {
        return $this->userViews()->where('views', '>', 0)->count();
    }

    /** Statistiken auf null zurücksetzen (Gesamt-, Tages- und Betrachterwerte). */
    public function resetStats(): void
    {
        $this->forceFill(['total_impressions' => 0, 'total_clicks' => 0, 'last_shown_at' => null])->save();
        $this->dailyStats()->delete();
        $this->userViews()->update(['views' => 0, 'last_seen_at' => null]);
    }
}
