<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Konfiguriertes Postfach (info@, kv@, ...). Zugangsdaten liegen
 * ausschließlich verschlüsselt in `credentials` (encrypted:array-Cast,
 * AES via APP_KEY - gleiches Prinzip wie bei Customer's sensiblen
 * Feldern). Nie im Klartext loggen oder in Views ausgeben.
 */
class EmailAccount extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'name', 'email_address', 'provider',
        'imap_host', 'imap_port', 'imap_encryption',
        'smtp_host', 'smtp_port', 'smtp_encryption',
        'username', 'credentials', 'folders', 'is_active', 'is_customer_import', 'created_by',
        'last_synced_at', 'last_error',
    ];

    protected $hidden = ['credentials'];

    public const PROVIDERS = [
        'imap' => 'IMAP/SMTP (generisch)',
        'hostinger_imap' => 'Hostinger',
        'gmail_oauth' => 'Gmail / Google Workspace (OAuth)',
        'microsoft_oauth' => 'Microsoft 365 (OAuth)',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'folders' => 'array',
            'is_active' => 'boolean',
            'is_customer_import' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    protected static function boot()
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function messages()
    {
        return $this->hasMany(EmailMessage::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isOAuth(): bool
    {
        return in_array($this->provider, ['gmail_oauth', 'microsoft_oauth'], true);
    }

    public function watchedFolders(): array
    {
        return $this->folders ?: ['INBOX'];
    }
}
