<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Document extends Model {
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['customer_id','contract_id','category','file_name','file_path','disk','visibility','uploaded_by','updated_by','file_size'];

    public const CATEGORIES = ['contract' => 'Verträge', 'police' => 'Policen', 'invoice' => 'Rechnungen', 'identity' => 'Identität', 'claim' => 'Schaden', 'other' => 'Sonstige'];

    public function uploader() { return $this->belongsTo(User::class, 'uploaded_by'); }
    public function scopeCustomerVisible($q) { return $q->where('visibility', 'customer'); }
    protected static function boot() {
        parent::boot();
        static::creating(fn($m) => $m->id = Str::uuid());
    }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function contract() { return $this->belongsTo(Contract::class); }
}
