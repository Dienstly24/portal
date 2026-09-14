<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Appointment extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['customer_id', 'assigned_to', 'title', 'notes', 'starts_at', 'ends_at', 'status'];
    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    protected static function boot() {
        parent::boot();
        static::creating(fn ($m) => $m->id = Str::uuid());
    }
    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    /** @return BelongsTo<User, $this> */
    public function assignedTo(): BelongsTo { return $this->belongsTo(User::class, 'assigned_to'); }
}
