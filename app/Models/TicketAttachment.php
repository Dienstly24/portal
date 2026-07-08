<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TicketAttachment extends Model
{
    protected $table = 'ticket_attachments';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];

    public function ticket() { return $this->belongsTo(Ticket::class); }
}
