<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Banner extends Model
{
    protected $fillable = ['title', 'media_path', 'media_type', 'is_active', 'sort_order', 'start_date', 'end_date'];

    protected $casts = ['is_active' => 'boolean', 'start_date' => 'date', 'end_date' => 'date'];

    /** Aktive Banner im geplanten Zeitfenster, sortiert. */
    public function scopeCurrent(Builder $q): Builder {
        $today = now()->toDateString();
        return $q->where('is_active', true)
            ->where(fn($w) => $w->whereNull('start_date')->orWhere('start_date', '<=', $today))
            ->where(fn($w) => $w->whereNull('end_date')->orWhere('end_date', '>=', $today))
            ->orderBy('sort_order')->orderBy('id');
    }
}
