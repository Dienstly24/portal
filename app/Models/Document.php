<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Document extends Model {
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['customer_id','contract_id','category','file_name','file_path','disk','visibility','color','uploaded_by','updated_by','file_size',
        'ai_status','ai_type','ai_confidence','ai_summary','ai_extracted','ai_error','ai_processed_at','page_count'];

    public const CATEGORIES = ['contract' => 'Verträge', 'police' => 'Policen', 'invoice' => 'Rechnungen', 'identity' => 'Identität', 'claim' => 'Schaden', 'other' => 'Sonstige'];

    /**
     * Dokumenttypen, die die KI-Analyse erkennen darf (Whitelist wie bei
     * AiEmailClassifier: alles ausserhalb dieser Liste wird verworfen).
     * label = Anzeige, category = Zuordnung zur bestehenden Kategorie.
     */
    public const AI_TYPES = [
        'kfz_vertrag'          => ['label' => 'KFZ-Vertrag',          'category' => 'contract'],
        'versicherungsvertrag' => ['label' => 'Versicherungsvertrag', 'category' => 'contract'],
        'versicherungspolice'  => ['label' => 'Versicherungspolice',  'category' => 'police'],
        'fahrzeugschein'       => ['label' => 'Fahrzeugschein',       'category' => 'other'],
        'fahrzeugbrief'        => ['label' => 'Fahrzeugbrief',        'category' => 'other'],
        'gesundheitskarte'     => ['label' => 'Gesundheitskarte',     'category' => 'identity'],
        'personalausweis'      => ['label' => 'Personalausweis',      'category' => 'identity'],
        'reisepass'            => ['label' => 'Reisepass',            'category' => 'identity'],
        'fuehrerschein'        => ['label' => 'Führerschein',         'category' => 'identity'],
        'rechnung'             => ['label' => 'Rechnung',             'category' => 'invoice'],
        'sepa_mandat'          => ['label' => 'SEPA-Mandat',          'category' => 'other'],
        'schadenmeldung'       => ['label' => 'Schadenmeldung',       'category' => 'claim'],
        'sonstiges'            => ['label' => 'Sonstiges Dokument',   'category' => 'other'],
    ];

    protected function casts(): array {
        return [
            'ai_extracted' => 'array',
            'ai_processed_at' => 'datetime',
        ];
    }

    public function uploader() { return $this->belongsTo(User::class, 'uploaded_by'); }
    public function scopeCustomerVisible($q) { return $q->where('visibility', 'customer'); }
    /** Dokumenten-Eingang: hochgeladen ohne Kundenzuordnung (nur Mitarbeiter). */
    public function scopeInbox($q) { return $q->whereNull('customer_id'); }
    protected static function boot() {
        parent::boot();
        static::creating(fn($m) => $m->id = $m->id ?: (string) Str::uuid());
    }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function contract() { return $this->belongsTo(Contract::class); }
    public function aiDecisions() { return $this->hasMany(AiDecision::class); }

    /** Deutsches Label des erkannten Dokumenttyps (z.B. "Gesundheitskarte"). */
    public function aiTypeLabel(): ?string {
        return $this->ai_type ? (self::AI_TYPES[$this->ai_type]['label'] ?? null) : null;
    }

    /** Laeuft die Analyse noch? (Anzeige "Dokument wird analysiert...") */
    public function aiInProgress(): bool {
        return in_array($this->ai_status, ['pending', 'processing'], true);
    }
}
