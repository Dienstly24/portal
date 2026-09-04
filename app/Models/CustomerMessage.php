<?php

namespace App\Models;

use App\Services\Ai\Assistant\AssistantSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Direktnachricht zwischen Beratung und Kunde (Portal-Chat).
 * from_staff=true: vom Team an den Kunden, sonst Kundenantwort.
 * read_at = Lesezeitpunkt der jeweiligen Gegenseite.
 */
class CustomerMessage extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    public const EMAIL_MODES = ['none', 'hint', 'full'];

    protected $fillable = ['customer_id', 'sender_id', 'body', 'from_staff', 'ai_generated', 'read_at', 'email_mode'];
    protected $casts = ['from_staff' => 'boolean', 'ai_generated' => 'boolean', 'read_at' => 'datetime'];

    /** Anzeigename des Assistenten - eine Quelle fuer Portal und Beraterwelt. */
    public const AI_SENDER_NAME = 'Dienstly24 Assistent';

    /**
     * Nachrichtentext OHNE sensible Angaben - nur fuer den Weg zum
     * KI-Modell (SlotExtractor ersetzt IBAN, Geburtsdatum & Co. durch
     * Platzhalter). Der gespeicherte `body` bleibt unveraendert, damit der
     * Kunde im Chat sieht, was er geschrieben hat.
     *
     * BEWUSST ALS ECHTE KLASSEN-EIGENSCHAFT deklariert: eine dynamisch
     * gesetzte Eigenschaft wuerde bei Eloquent als ATTRIBUT landen und
     * beim naechsten save() als Spalte geschrieben werden wollen.
     */
    public ?string $aiSafeBody = null;

    protected static function boot() {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: (string) Str::uuid());
        // Schreibt ein MENSCH an den Kunden, faengt die Ruhefrist der
        // Wiederaufnahme neu an (Betreiber-Vorgabe 20.08.2026): solange am
        // Fall gearbeitet wird, faellt die KI niemandem ins Wort. Hier im
        // Modell und nicht im Controller, damit es fuer JEDEN Schreibweg
        // gilt (Kundenchat, Kundenakte, Aufgaben-Automatik).
        static::created(function ($m) {
            if (! $m->from_staff || $m->ai_generated) {
                return;
            }
            $steuerstand = AiConversation::where('customer_id', $m->customer_id)->first();
            $steuerstand?->postponeResume(
                app(AssistantSettings::class)->resumeQuietHours()
            );
        });
    }

    public function customer() { return $this->belongsTo(Customer::class); }
    public function sender() { return $this->belongsTo(User::class, 'sender_id'); }
    public function attachments() { return $this->hasMany(CustomerMessageAttachment::class, 'message_id'); }

    public function scopeFromStaff($q) { return $q->where('from_staff', true); }
    public function scopeFromCustomer($q) { return $q->where('from_staff', false); }
    public function scopeUnread($q) { return $q->whereNull('read_at'); }

    /**
     * Einheitliche Chat-Struktur fuer Portal-Seite, Portal-Widget und
     * Kunden-Chat der Beraterwelt. $staffView spiegelt die Perspektive:
     * im Portal sind Kundennachrichten "eigene", in der Beraterwelt die
     * Staff-Nachrichten (dort mit Absender-Name, weil mehrere Kollegen
     * schreiben koennen).
     */
    public function toChatPayload(bool $staffView = false): array
    {
        $attachmentRoute = $staffView ? 'admin.messages.attachment' : 'portal.messages.attachment';
        $viewRoute = $staffView ? 'admin.messages.attachment.view' : 'portal.messages.attachment.view';

        return [
            'id' => $this->id,
            'from_staff' => $this->from_staff,
            'own' => $staffView ? $this->from_staff : ! $this->from_staff,
            // Der Kunde MUSS erkennen, dass zunaechst ein Assistent antwortet
            // (Spezifikation Abschnitt 26); der Mitarbeiter sieht dieselbe
            // Kennzeichnung in der Beraterwelt (Abschnitt 27).
            'ai' => (bool) $this->ai_generated,
            'sender' => $this->from_staff
                ? ($this->ai_generated
                    ? __(self::AI_SENDER_NAME)
                    : ($this->sender?->name ?? 'Dienstly24 Team'))
                : ($this->customer?->user?->name ?? __('Kunde')),
            'show_sender' => $this->from_staff,
            'body' => $this->body,
            'day' => $this->created_at->isToday()
                ? __('Heute')
                : ($this->created_at->isYesterday() ? __('Gestern') : $this->created_at->lokal()->format('d.m.Y')),
            'time' => $this->created_at->lokal()->format('H:i'),
            'read' => $this->read_at !== null,
            'attachments' => $this->attachments->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->file_name,
                'kind' => $a->isImage() ? 'image' : ($a->isPdf() ? 'pdf' : 'file'),
                'view_url' => $a->isViewable() ? route($viewRoute, $a->id) : null,
                'download_url' => route($attachmentRoute, $a->id),
            ])->values()->all(),
        ];
    }
}
