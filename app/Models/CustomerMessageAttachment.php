<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CustomerMessageAttachment extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['message_id', 'uploaded_by', 'file_name', 'file_path', 'disk'];

    protected static function boot() {
        parent::boot();
        static::creating(fn($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function message() { return $this->belongsTo(CustomerMessage::class, 'message_id'); }
    public function uploader() { return $this->belongsTo(User::class, 'uploaded_by'); }

    /** Bild-Anhaenge koennen im Chat als Vorschau gerendert werden. */
    public function isImage(): bool {
        return in_array(strtolower(pathinfo($this->file_name, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp'], true);
    }
}
