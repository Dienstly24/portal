<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Ein "zuletzt geoeffnet"-Eintrag: welcher Mitarbeiter welche Kundenakte
// wann zuletzt aufgerufen hat (siehe Migration create_customer_views_table).
class CustomerView extends Model
{
    protected $fillable = ['user_id', 'customer_id', 'viewed_at'];

    protected $casts = ['viewed_at' => 'datetime'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
}
