<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CustomerAddress extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['customer_id', 'type', 'street', 'house_number', 'house_number_suffix', 'zip', 'city', 'country'];

    public const TYPES = ['main' => 'Hauptadresse', 'billing' => 'Rechnungsadresse', 'postal' => 'Postadresse', 'other' => 'Andere Adresse'];

    protected static function boot() {
        parent::boot();
        static::creating(fn($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function customer() { return $this->belongsTo(Customer::class); }

    public function typeLabel(): string { return self::TYPES[$this->type] ?? $this->type; }
}
