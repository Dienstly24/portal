<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CustomerContact extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['customer_id', 'type', 'label', 'value'];

    public const LABELS = ['privat' => 'Privat', 'geschaeftlich' => 'Geschäftlich', 'sonstige' => 'Sonstige'];

    protected static function boot() {
        parent::boot();
        static::creating(fn($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function customer() { return $this->belongsTo(Customer::class); }
}
