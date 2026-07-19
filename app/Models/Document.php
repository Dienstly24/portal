<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Document extends Model {
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['customer_id','contract_id','intake_batch','category','file_name','file_path','disk','visibility','color','uploaded_by','updated_by','file_size','content_hash','duplicate_of',
        'ai_status','ai_type','ai_confidence','ai_source','ai_summary','ai_extracted','ai_error','ai_processed_at','page_count'];

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
        'beratungsprotokoll'   => ['label' => 'Beratungsprotokoll',   'category' => 'contract'],
        'fahrzeugschein'       => ['label' => 'Fahrzeugschein',       'category' => 'other'],
        'fahrzeugbrief'        => ['label' => 'Fahrzeugbrief',        'category' => 'other'],
        'gesundheitskarte'     => ['label' => 'Gesundheitskarte',     'category' => 'identity'],
        'geburtsurkunde'       => ['label' => 'Geburtsurkunde',       'category' => 'identity'],
        'familienbescheinigung'=> ['label' => 'Familienbescheinigung','category' => 'identity'],
        'gehaltsabrechnung'    => ['label' => 'Gehaltsabrechnung',    'category' => 'other'],
        'personalausweis'      => ['label' => 'Personalausweis',      'category' => 'identity'],
        'reisepass'            => ['label' => 'Reisepass',            'category' => 'identity'],
        'fuehrerschein'        => ['label' => 'Führerschein',         'category' => 'identity'],
        'rechnung'             => ['label' => 'Rechnung',             'category' => 'invoice'],
        'energieauftrag'       => ['label' => 'Energie-Auftrag',      'category' => 'contract'],
        'zaehlerfoto'          => ['label' => 'Zaehlerfoto',          'category' => 'other'],
        'sepa_mandat'          => ['label' => 'SEPA-Mandat',          'category' => 'other'],
        'schadenmeldung'       => ['label' => 'Schadenmeldung',       'category' => 'claim'],
        'sonstiges'            => ['label' => 'Sonstiges Dokument',   'category' => 'other'],
    ];

    protected function casts(): array {
        return [
            // Verschluesselt at rest: kann IBAN/Versichertennummern enthalten
            // (gleiche Schutzstufe wie die SafeEncrypted-Kundenfelder).
            'ai_extracted' => 'encrypted:array',
            // Die KI-Zusammenfassung ist reiner Fliesstext, kann aber trotz
            // Prompt-Regel Namen/Fragmente enthalten - defensiv ebenfalls
            // verschluesselt statt sich allein auf die Modell-Anweisung zu
            // verlassen.
            'ai_summary' => 'encrypted',
            'ai_processed_at' => 'datetime',
        ];
    }

    public function uploader() { return $this->belongsTo(User::class, 'uploaded_by'); }
    public function scopeCustomerVisible($q) { return $q->where('visibility', 'customer'); }
    /** Dokumenten-Eingang: hochgeladen ohne Kundenzuordnung (nur Mitarbeiter). */
    public function scopeInbox($q) { return $q->whereNull('customer_id'); }
    protected static function boot() {
        parent::boot();
        static::creating(function ($m) {
            $m->id = $m->id ?: (string) Str::uuid();
            // Inhalts-Bestimmung (SHA-256) einmalig beim Anlegen aus der bereits
            // gespeicherten Datei berechnen - zentral hier, damit JEDER
            // Upload-Weg (Eingang, Kundenakte, Portal, E-Mail-Anhang) erfasst
            // ist. Streamend gehasht (kein Laden der ganzen Datei in den RAM).
            if ($m->content_hash === null && $m->file_path && $m->disk) {
                $m->content_hash = self::hashStoredFile($m->disk, $m->file_path);
            }
            // Inhaltsgleiches, zuerst hochgeladenes Dokument merken (Duplikat).
            if ($m->content_hash !== null && $m->duplicate_of === null) {
                $original = static::where('content_hash', $m->content_hash)
                    ->orderBy('created_at')->orderBy('id')->first();
                $m->duplicate_of = $original?->id;
            }
        });
    }

    /** SHA-256 des gespeicherten Dateiinhalts (streamend) oder null. */
    public static function hashStoredFile(string $disk, string $path): ?string {
        try {
            $storage = \Illuminate\Support\Facades\Storage::disk($disk);
            if (!$storage->exists($path)) {
                return null;
            }
            $stream = $storage->readStream($path);
            if (!is_resource($stream)) {
                return null;
            }
            $ctx = hash_init('sha256');
            hash_update_stream($ctx, $stream);
            fclose($stream);
            return hash_final($ctx);
        } catch (\Throwable $e) {
            return null; // Hash ist optional - Upload darf daran nie scheitern.
        }
    }

    public function customer() { return $this->belongsTo(Customer::class); }
    public function contract() { return $this->belongsTo(Contract::class); }
    public function aiDecisions() { return $this->hasMany(AiDecision::class); }
    /** Das zuerst hochgeladene, inhaltsgleiche Dokument (bei Duplikaten). */
    public function duplicateOriginal() { return $this->belongsTo(Document::class, 'duplicate_of'); }

    /** Deutsches Label des erkannten Dokumenttyps (z.B. "Gesundheitskarte"). */
    public function aiTypeLabel(): ?string {
        return $this->ai_type ? (self::AI_TYPES[$this->ai_type]['label'] ?? null) : null;
    }

    /** Laeuft die Analyse noch? (Anzeige "Dokument wird analysiert...") */
    public function aiInProgress(): bool {
        return in_array($this->ai_status, ['pending', 'processing'], true);
    }
}
