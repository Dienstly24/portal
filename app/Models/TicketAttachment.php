<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketAttachment extends Model
{
    protected $table = 'ticket_attachments';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];

    public function ticket(): BelongsTo { return $this->belongsTo(Ticket::class); }
}
