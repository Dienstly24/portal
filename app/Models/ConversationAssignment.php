<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Historie der Zustaendigkeit. Ohne sie ist nach einer Uebernahme nicht
 * mehr belegbar, wer wann verantwortlich war und wer das geaendert hat -
 * genau die Frage, die bei einer Beschwerde gestellt wird.
 *
 * Kein `updated_at`: ein Historieneintrag wird nie geaendert.
 */
class ConversationAssignment extends Model
{
    public const UPDATED_AT = null;

    public const ACTION_AUTO_BETREUER = 'auto_betreuer';
    public const ACTION_TAKEOVER = 'takeover';
    public const ACTION_REASSIGN = 'reassign';
    public const ACTION_UNASSIGN = 'unassign';

    public const ACTION_LABELS = [
        self::ACTION_AUTO_BETREUER => 'Automatisch an den Betreuer',
        self::ACTION_TAKEOVER => 'Uebernommen',
        self::ACTION_REASSIGN => 'Weitergegeben',
        self::ACTION_UNASSIGN => 'Zustaendigkeit entfernt',
    ];

    protected $fillable = [
        'conversation_id', 'from_employee_id', 'to_employee_id',
        'changed_by_employee_id', 'action', 'reason',
    ];

    public function conversation() { return $this->belongsTo(Conversation::class); }
    public function fromEmployee() { return $this->belongsTo(User::class, 'from_employee_id'); }
    public function toEmployee() { return $this->belongsTo(User::class, 'to_employee_id'); }
    public function changedBy() { return $this->belongsTo(User::class, 'changed_by_employee_id'); }

    public function actionLabel(): string
    {
        return self::ACTION_LABELS[$this->action] ?? $this->action;
    }
}
