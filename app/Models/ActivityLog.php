<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ActivityLog extends Model {
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['user_id','action','entity_type','entity_id','meta'];
    protected $casts = ['meta' => 'array'];
    protected static function boot() {
        parent::boot();
        static::creating(fn($m) => $m->id = Str::uuid());
    }
    public function user() { return $this->belongsTo(User::class); }
}
