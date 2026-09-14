<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CustomerTimeline extends Model
{
    protected $table = 'customer_timeline';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['customer_id', 'user_id', 'type', 'title', 'description', 'meta'];
    protected $casts = ['meta' => 'array'];
    protected static function boot() {
        parent::boot();
        static::creating(fn ($m) => $m->id = Str::uuid());
    }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
}
