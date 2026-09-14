<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Substitution extends Model
{
    protected $guarded = [];
    protected $casts = ['from_date' => 'date', 'to_date' => 'date'];

    /** @return BelongsTo<User, $this> */
    public function absentUser(): BelongsTo { return $this->belongsTo(User::class, 'absent_user_id'); }
    /** @return BelongsTo<User, $this> */
    public function substituteUser(): BelongsTo { return $this->belongsTo(User::class, 'substitute_user_id'); }

    public function scopeActive($query) {
        return $query->whereDate('from_date', '<=', now())->whereDate('to_date', '>=', now());
    }
}
