<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Die Bruecke REFERENZ-NR. <-> POOL-ID (Betreiber-Vorgabe 02.09.2026, §15).
 *
 * Beim Abschluss kennen wir nur unsere Referenz-Nr. („REF-12345“). Der Pool
 * vergibt spaeter eine eigene Id („987654“). Kommt eine Datei, die BEIDES
 * fuehrt, wird das Paar hier festgehalten. Ab dann genuegt in jeder weiteren
 * Datei die Id allein - der Weg zurueck zum Vertrag steht gespeichert.
 *
 * WARUM EINE EIGENE TABELLE UND NICHT NUR EIN FELD AM VERTRAG: Ein Vertrag
 * kann Kennungen aus mehreren Pools tragen (derselbe Kunde, zwei Quellen),
 * und eine Zuordnung kann sich als falsch herausstellen. Als eigene Zeile
 * bleibt sie nachvollziehbar und einzeln korrigierbar; als Spalte am Vertrag
 * waere sie beim naechsten Import still ueberschrieben.
 */
class CommissionReferenceLink extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'pool', 'reference_key', 'external_key', 'reference_number',
        'external_id', 'contract_id', 'source',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }
}
