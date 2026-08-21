<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Document extends Model {
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['customer_id','contract_id','intake_batch','category','file_name','file_path','disk','visibility','color','uploaded_by','updated_by','file_size','content_hash','duplicate_of',
        'ai_status','ai_type','ai_confidence','ai_source','ai_summary','ai_extracted','ai_error','ai_processed_at','page_count','vermittler_import_id'];

    public const CATEGORIES = ['contract' => 'Verträge', 'police' => 'Policen', 'invoice' => 'Rechnungen', 'identity' => 'Identität', 'claim' => 'Schaden', 'other' => 'Sonstige'];

    /**
     * Dokumenttypen, die die KI-Analyse erkennen darf (Whitelist wie bei
     * AiEmailClassifier: alles ausserhalb dieser Liste wird verworfen).
     * label = Anzeige, category = Zuordnung zur bestehenden Kategorie.
     */
    public const AI_TYPES = [
        'kfz_vertrag'          => ['label' => 'KFZ-Vertrag',          'category' => 'contract'],
        'escooter_vertrag'     => ['label' => 'E-Scooter-Versicherung', 'category' => 'contract'],
        'versicherungsvertrag' => ['label' => 'Versicherungsvertrag', 'category' => 'contract'],
        'versicherungspolice'  => ['label' => 'Versicherungspolice',  'category' => 'police'],
        'beratungsprotokoll'   => ['label' => 'Beratungsprotokoll',   'category' => 'contract'],
        'beitrittserklaerung'  => ['label' => 'Beitrittserklaerung (Kranken)', 'category' => 'contract'],
        'familienversicherung' => ['label' => 'Familienversicherung (Kranken)', 'category' => 'contract'],
        'fahrzeugschein'       => ['label' => 'Fahrzeugschein',       'category' => 'other'],
        'fahrzeugbrief'        => ['label' => 'Fahrzeugbrief',        'category' => 'other'],
        'gesundheitskarte'     => ['label' => 'Gesundheitskarte',     'category' => 'identity'],
        'geburtsurkunde'       => ['label' => 'Geburtsurkunde',       'category' => 'identity'],
        'familienbescheinigung'=> ['label' => 'Familienbescheinigung','category' => 'identity'],
        'gehaltsabrechnung'    => ['label' => 'Gehaltsabrechnung',    'category' => 'other'],
        'arbeitsvertrag'       => ['label' => 'Arbeitsvertrag',       'category' => 'other'],
        'personalausweis'      => ['label' => 'Personalausweis',      'category' => 'identity'],
        'aufenthaltstitel'     => ['label' => 'Aufenthaltstitel',     'category' => 'identity'],
        'reisepass'            => ['label' => 'Reisepass',            'category' => 'identity'],
        'meldebescheinigung'   => ['label' => 'Meldebescheinigung',   'category' => 'identity'],
        'fuehrerschein'        => ['label' => 'Führerschein',         'category' => 'identity'],
        'rechnung'             => ['label' => 'Rechnung',             'category' => 'invoice'],
        'energieauftrag'       => ['label' => 'Energie-Auftrag',      'category' => 'contract'],
        'internetvertrag'      => ['label' => 'Internet-/DSL-Auftrag', 'category' => 'contract'],
        'zaehlerfoto'          => ['label' => 'Zaehlerfoto',          'category' => 'other'],
        'sepa_mandat'          => ['label' => 'SEPA-Mandat',          'category' => 'other'],
        'kontaktdaten'         => ['label' => 'Kontaktdaten',         'category' => 'identity'],
        'schadenmeldung'       => ['label' => 'Schadenmeldung',       'category' => 'claim'],
        // Liste MEHRERER Vorgaenge des Vermittlers - kein Kundendokument:
        // sie gehoert zu keinem einzelnen Kunden und wird unter
        // Vermittler-Abrechnung eingelesen, nicht hier zugeordnet.
        'vermittler_vorgangsliste' => ['label' => 'Vermittler-Vorgangsliste', 'category' => 'other'],
        'sonstiges'            => ['label' => 'Sonstiges Dokument',   'category' => 'other'],
    ];

    /**
     * Dokumenttypen, die NEUES Geschaeft bedeuten (ein - ggf. zweiter - Vertrag
     * ist anzulegen). Solche Dokumente werden auch bei einem eindeutigen
     * Kunden-Treffer NICHT automatisch zugeordnet: sie bleiben im
     * Dokumenten-Eingang mit dem Kunden-VORSCHLAG stehen, damit der Mitarbeiter
     * sie sieht und den Vertrag anlegt. Sonst "verschwindet" z.B. das Kfz-
     * Beratungsprotokoll fuer den ZWEITEN Wagen still in die Kundenakte, ohne
     * dass der zweite Vertrag entsteht.
     */
    public const NEW_BUSINESS_TYPES = [
        'kfz_vertrag', 'escooter_vertrag', 'versicherungsvertrag', 'beratungsprotokoll',
        'beitrittserklaerung', 'familienversicherung', 'energieauftrag', 'internetvertrag',
    ];

    /** Bedeutet dieser Dokumenttyp neues Geschaeft (Vertrag anzulegen)? */
    public function impliesNewContract(): bool {
        return in_array($this->ai_type, self::NEW_BUSINESS_TYPES, true);
    }

    /**
     * Dokumenttypen, die ein ANTRAG/AUFTRAG sind (Vertrag noch nicht
     * bestaetigt): der Kunde hat beauftragt, die Gesellschaft hat noch nicht
     * bestaetigt. Solche Dokumente tragen typischerweise noch keine
     * Vertragsnummer.
     */
    public const APPLICATION_AI_TYPES = [
        'energieauftrag', 'internetvertrag', 'beratungsprotokoll',
        'beitrittserklaerung', 'familienversicherung', 'versicherungsvertrag',
    ];

    /**
     * Dokumenttypen, die den ABSCHLUSS belegen (Police, Versicherungsschein,
     * Vertragsbestaetigung) - sie bringen die endgueltigen Daten.
     */
    public const CONFIRMATION_AI_TYPES = [
        'versicherungspolice', 'kfz_vertrag', 'escooter_vertrag',
    ];

    /**
     * Vertrags-Stufe, die dieses Dokument belegt (Contract::STAGE_*) oder null,
     * wenn sich das nicht sicher sagen laesst.
     *
     * Reihenfolge (Betreiber-Vorgabe 29.07.2026):
     *  1. Ausdrueckliche Angabe der Extraktion (versicherung.document_stage) -
     *     die Vorlagen-Parser und die KI kennen ihr Dokument am besten.
     *  2. Eindeutige Dokumenttypen (Police/Versicherungsschein = Vertrag).
     *  3. Antrags-Typen: MIT Vertragsnummer ist es die Bestaetigung (z.B. die
     *     EWE-Vertragsbestaetigung, die denselben Typ 'energieauftrag' traegt
     *     wie der Auftrag), OHNE Nummer der Antrag.
     *  4. Sonst: eine Vertragsnummer belegt einen bestaetigten Vertrag,
     *     ansonsten bleibt die Stufe offen (null = Automatik haelt sich raus).
     *
     * @param array<string,mixed> $extracted validiertes Analyse-Ergebnis
     */
    public static function contractStageFor(?string $aiType, array $extracted): ?string {
        $ins = $extracted['versicherung'] ?? [];
        $explicit = $ins['document_stage'] ?? null;
        if (in_array($explicit, [Contract::STAGE_ANTRAG, Contract::STAGE_VERTRAG], true)) {
            return $explicit;
        }

        $hasNumber = !blank($ins['contract_number'] ?? null);

        if (in_array($aiType, self::CONFIRMATION_AI_TYPES, true)) {
            return Contract::STAGE_VERTRAG;
        }
        if (in_array($aiType, self::APPLICATION_AI_TYPES, true)) {
            return $hasNumber ? Contract::STAGE_VERTRAG : Contract::STAGE_ANTRAG;
        }
        return $hasNumber ? Contract::STAGE_VERTRAG : null;
    }

    /** Vertrags-Stufe dieses Dokuments aus seinem eigenen Analyse-Ergebnis. */
    public function contractStage(): ?string {
        return self::contractStageFor($this->ai_type, $this->ai_extracted ?? []);
    }

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

    /**
     * Bereits als Vermittler-Vorgangsliste eingelesen? Solche Dokumente
     * gehoeren zu keinem Kunden und sind trotzdem erledigt - sie stehen
     * deshalb nicht mehr unter "Nicht zugeordnet".
     */
    public function isVermittlerListeVerarbeitet(): bool { return $this->vermittler_import_id !== null; }

    public function vermittlerImport() { return $this->belongsTo(VermittlerImport::class, 'vermittler_import_id'); }
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

    /** Kleingeschriebene Dateiendung (z.B. "pdf") oder leerer String. */
    public function extension(): string {
        return strtolower(pathinfo((string) $this->file_name, PATHINFO_EXTENSION));
    }

    /** Bild-Dokumente koennen als Vorschau gerendert werden. */
    public function isImage(): bool {
        return in_array($this->extension(), ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);
    }

    public function isPdf(): bool {
        return $this->extension() === 'pdf';
    }

    /**
     * Bilder und PDFs kann der Browser direkt inline anzeigen
     * (Content-Disposition: inline via ?view=1) - also ohne Download in der
     * Schnellvorschau/im Vorschau-Fenster darstellbar. Office-Dateien
     * (doc/xls) muessen weiterhin heruntergeladen werden.
     */
    public function isViewable(): bool {
        return $this->isImage() || $this->isPdf();
    }
}
