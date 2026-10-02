<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Begruendung einer SF-Sondereinstufung je Sparte (Haftpflicht/Vollkasko).
 *
 * Bei Zweitwagen, Drittwagen und Uebernahme innerhalb der Familie steht hier
 * das BEZUGSFAHRZEUG (Erstwagen) - entweder ein Vertrag im System (intern)
 * oder ein Vertrag bei einem anderen Versicherer (extern). Bei den uebrigen
 * Gruenden nur die Angabe, die zum Grund gehoert (Fuehrerscheindatum,
 * Aktionsname, Freitext).
 *
 * Geschrieben wird AUSSCHLIESSLICH ueber App\Services\Kfz\SfReferenceService
 * (Pruefung auf Selbst-/Kreisbezug, Protokoll).
 */
class VehicleSfReference extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    public const TYPE_INTERNAL = 'internal';
    public const TYPE_EXTERNAL = 'external';

    public const BRANCHES = ['haftpflicht' => 'Haftpflicht', 'vollkasko' => 'Vollkasko'];

    /** Wem gehoert der Bezugsvertrag? */
    public const HOLDER_RELATIONS = [
        'kunde' => 'Kunde selbst',
        'partner' => 'Ehe-/Lebenspartner',
        'familie' => 'Familienmitglied',
        'sonstige' => 'Sonstige',
    ];

    /** Kurztext je Grund fuer die Anzeige ("Zweitwagen zu ADAC ..."). */
    public const REASON_SHORT = [
        'zweitwagen' => 'Zweitwagen',
        'drittwagen' => 'Drittwagen',
        'familie' => 'Übernahme Familie',
    ];

    protected $fillable = [
        'contract_vehicle_detail_id', 'branch',
        'reference_type', 'reference_contract_id', 'reference_label',
        'ext_insurer', 'ext_contract_number', 'ext_license_plate', 'ext_sf_class',
        'holder_relation', 'holder_name', 'snapshot_sf_class', 'snapshot_date',
        'proof_document_id', 'verified', 'verified_by', 'verified_at',
        'license_date', 'campaign_name', 'note',
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'license_date' => 'date',
        'verified' => 'boolean',
        'verified_at' => 'datetime',
    ];

    protected static function boot() {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    /** Gruende, die ein Bezugsfahrzeug brauchen (eine Quelle: config/kfz_rules.php). */
    public static function referenceReasons(): array {
        return (array) config('kfz_rules.rules.SF-BEZUG-GRUENDE.werte.gruende', ['zweitwagen', 'drittwagen', 'familie']);
    }

    public static function reasonNeedsReference(?string $reason): bool {
        return $reason !== null && in_array($reason, self::referenceReasons(), true);
    }

    /** @return BelongsTo<ContractVehicleDetail, $this> */
    public function vehicleDetail(): BelongsTo { return $this->belongsTo(ContractVehicleDetail::class, 'contract_vehicle_detail_id'); }
    /** @return BelongsTo<Contract, $this> */
    public function referenceContract(): BelongsTo { return $this->belongsTo(Contract::class, 'reference_contract_id'); }
    /** @return BelongsTo<Document, $this> */
    public function proofDocument(): BelongsTo { return $this->belongsTo(Document::class, 'proof_document_id'); }
    /** @return BelongsTo<User, $this> */
    public function verifier(): BelongsTo { return $this->belongsTo(User::class, 'verified_by'); }

    public function hasReference(): bool {
        return $this->reference_type === self::TYPE_INTERNAL
            ? ($this->reference_contract_id !== null || $this->reference_label !== null)
            : ($this->reference_type === self::TYPE_EXTERNAL && ($this->ext_insurer || $this->ext_contract_number));
    }

    /** "Versicherer Vertragsnummer" eines Vertrags - auch Grundlage von reference_label. */
    public static function labelFor(Contract $contract): string {
        return trim($contract->insurer.' '.($contract->contract_number ?: ($contract->reference_number ? 'Ref. '.$contract->reference_number : '')));
    }

    /**
     * Aktuelle SF-Klasse des Erstwagens in dieser Sparte: intern LIVE aus dem
     * Bezugsvertrag, extern die erfasste Angabe. Null = unbekannt.
     */
    public function currentReferenceSf(): ?string {
        if ($this->reference_type === self::TYPE_INTERNAL && $this->referenceContract) {
            $veh = $this->referenceContract->vehicleDetail;
            if (! $veh) return null;
            return $this->branch === 'vollkasko' ? $veh->sf_comprehensive_class : $veh->sf_liability_class;
        }
        return $this->reference_type === self::TYPE_EXTERNAL ? $this->ext_sf_class : null;
    }

    /** Anzeige des Bezugs: "ADAC AD-5406305005" (intern: live, sonst Kopie). */
    public function referenceText(): ?string {
        if ($this->reference_type === self::TYPE_INTERNAL) {
            return $this->referenceContract ? self::labelFor($this->referenceContract) : ($this->reference_label ? $this->reference_label.' (gelöscht)' : null);
        }
        if ($this->reference_type === self::TYPE_EXTERNAL) {
            $text = trim(($this->ext_insurer ?? '').' '.($this->ext_contract_number ?? ''));
            return $text !== '' ? $text : null;
        }
        return null;
    }

    /**
     * Ein-Zeilen-Begruendung fuer Vertragszeile und SF-Verlauf, z. B.
     * "Zweitwagen zu ADAC AD-5406305005 (SF 5)".
     */
    public function summary(?string $reason): ?string {
        if (self::reasonNeedsReference($reason)) {
            $ref = $this->referenceText();
            if (! $ref) return null;
            $sf = ContractVehicleDetail::sfLabel($this->currentReferenceSf());
            return (self::REASON_SHORT[$reason] ?? 'Bezug').' zu '.$ref.($sf ? ' ('.$sf.')' : '');
        }
        if ($this->license_date) return 'Führerschein seit '.$this->license_date->format('d.m.Y');
        if ($this->campaign_name) return 'Aktion: '.$this->campaign_name;
        if ($this->note) return Str::limit($this->note, 80);
        return null;
    }

    public function holderRelationLabel(): ?string {
        return self::HOLDER_RELATIONS[$this->holder_relation] ?? null;
    }
}
