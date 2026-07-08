<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
class TarifrechnerLink extends Model {
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['category','title','url','description','sort_order'];
    protected static function boot() {
        parent::boot();
        static::creating(fn($m) => $m->id = Str::uuid());
    }
}
