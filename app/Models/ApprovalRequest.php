<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ApprovalRequest extends Model {
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['customer_id','reviewed_by','field_name','old_value','new_value','status','reviewer_note','reviewed_at'];
    protected static function boot() {
        parent::boot();
        static::creating(fn($m) => $m->id = Str::uuid());
    }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function reviewer() { return $this->belongsTo(User::class, 'reviewed_by'); }
}
