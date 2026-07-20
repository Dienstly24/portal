<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Ein "zuletzt geoeffnet"-Eintrag: welcher Mitarbeiter welche Kundenakte
// wann zuletzt aufgerufen hat (siehe Migration create_customer_views_table).
class CustomerView extends Model {
    protected $fillable = ['user_id', 'customer_id', 'viewed_at'];

    protected $casts = ['viewed_at' => 'datetime'];

    public function user()     { return $this->belongsTo(User::class); }
    public function customer() { return $this->belongsTo(Customer::class); }
}
