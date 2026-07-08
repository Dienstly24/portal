<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
class Task extends Model {
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['assigned_to','created_by','customer_id','title','description','type','status','priority','due_date'];
    protected $casts = ['due_date' => 'date'];
    protected static function boot() {
        parent::boot();
        static::creating(fn($m) => $m->id = Str::uuid());
    }
    public function assignedTo() { return $this->belongsTo(User::class, 'assigned_to'); }
    public function createdBy() { return $this->belongsTo(User::class, 'created_by'); }
    public function customer() { return $this->belongsTo(Customer::class); }
}
