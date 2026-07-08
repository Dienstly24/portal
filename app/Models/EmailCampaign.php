<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class EmailCampaign extends Model {
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['created_by','subject','body','target','status','sent_count','sent_at'];
    protected $casts = ['sent_at' => 'datetime'];
    protected static function boot() {
        parent::boot();
        static::creating(fn($m) => $m->id = Str::uuid());
    }
    public function createdBy() { return $this->belongsTo(User::class, 'created_by'); }
}
