<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Substitution extends Model
{
    protected $guarded = [];
    protected $casts = ['from_date' => 'date', 'to_date' => 'date'];

    public function absentUser() { return $this->belongsTo(User::class, 'absent_user_id'); }
    public function substituteUser() { return $this->belongsTo(User::class, 'substitute_user_id'); }

    public function scopeActive($query) {
        return $query->whereDate('from_date', '<=', now())->whereDate('to_date', '>=', now());
    }
}
