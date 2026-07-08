<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
class CustomerFamily extends Model {
    protected $table = 'customer_family';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['customer_id','name','relation','birth_date','krankenversicherung_nr','steuer_nr'];
    protected static function boot() {
        parent::boot();
        static::creating(fn($m) => $m->id = Str::uuid());
    }
}
