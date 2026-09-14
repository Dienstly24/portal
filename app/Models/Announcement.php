<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Announcement extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['created_by', 'title', 'body', 'priority', 'expires_at'];
    protected $casts = ['expires_at' => 'datetime'];
    protected static function boot() {
        parent::boot();
        static::creating(fn ($m) => $m->id = Str::uuid());
    }
    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
