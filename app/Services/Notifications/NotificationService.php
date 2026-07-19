<?php

namespace App\Services\Notifications;

use App\Models\InternalNotification;
use Illuminate\Support\Str;

/**
 * Zentraler Einstiegspunkt fuer ALLE internen Benachrichtigungen (Glocke).
 *
 * Warum diese Schicht (Notification-System-Audit, Juli 2026):
 *  - EINE Stelle, an der Benachrichtigungen entstehen -> konsistente Regeln
 *    statt 15 verstreuter `InternalNotification::create([...])`-Aufrufe.
 *  - Automatisches, sicheres Kuerzen von `title`/`body` auf die Spalten-
 *    laenge. Frueher fuehrten lange Inhalte in Produktion (MySQL strict)
 *    zu SQLSTATE[22001] "Data too long" -> die Benachrichtigung ging
 *    verloren, obwohl die ausloesende Aktion erfolgreich war.
 *  - Duplikat-Vermeidung ueber `dedup_key`: gleiche Ereignisse fallen zu
 *    EINEM ungelesenen Eintrag zusammen (Doppel-Submit, wiederholte
 *    Antwort auf dasselbe Ticket), statt die Glocke zu fluten.
 *  - Ein sauberer Ort, um kuenftig weitere Kanaele (E-Mail/Push/SMS) an
 *    dieselbe Struktur anzudocken.
 *
 * Benutzung (statisch, passend zum bestehenden Notifier-Stil):
 *   Notify::to($userId, [
 *       'type'  => Notify::TYPE_TICKET,
 *       'title' => '...',
 *       'body'  => '...',
 *       'link'  => route(...),
 *       'dedup_key' => 'ticket-reply-' . $ticket->id, // optional
 *   ]);
 *   Notify::toMany($userIds, [...]);            // fixe Attribute
 *   Notify::toMany($userIds, fn($id) => [...]);  // je Empfaenger
 */
class NotificationService
{
    // Kategorien (Grundlage fuer Filter/Priorisierung/weitere Kanaele).
    public const TYPE_TICKET = 'ticket';
    public const TYPE_MESSAGE = 'message';
    public const TYPE_MENTION = 'mention';
    public const TYPE_CHANGE_REQUEST = 'change_request';
    public const TYPE_DOCUMENT = 'document';
    public const TYPE_IMPORT = 'import';
    public const TYPE_SYSTEM = 'system';

    // Spaltenlaengen aus der Migration (Single Source of Truth zum Kuerzen).
    private const TITLE_MAX = 255;
    private const BODY_MAX = 500;

    /**
     * Erstellt (oder aktualisiert bei vorhandenem dedup_key) EINE
     * Benachrichtigung fuer einen Empfaenger.
     *
     * @param array<string,mixed> $attrs title, body, link, type, dedup_key,
     *                                    message_id, change_request_id
     */
    public function push(int $userId, array $attrs): ?InternalNotification
    {
        $attrs = $this->normalize($attrs);
        $dedupKey = $attrs['dedup_key'] ?? null;

        // Duplikat-Vermeidung: existiert bereits ein UNGELESENER Eintrag mit
        // gleichem dedup_key fuer diesen Empfaenger, wird er aufgefrischt
        // (Inhalt + Zeitpunkt) statt ein Duplikat anzulegen. Gelesene
        // Eintraege werden nicht angefasst -> ein neues Ereignis nach dem
        // Lesen erzeugt korrekt wieder eine sichtbare Benachrichtigung.
        if ($dedupKey !== null && $dedupKey !== '') {
            $existing = InternalNotification::where('user_id', $userId)
                ->where('dedup_key', $dedupKey)
                ->whereNull('read_at')
                ->latest('id')
                ->first();

            if ($existing) {
                $existing->fill($attrs);
                $existing->created_at = now(); // Eintrag wandert nach oben
                $existing->save();
                return $existing;
            }
        }

        return InternalNotification::create(['user_id' => $userId] + $attrs);
    }

    /**
     * Fan-out an mehrere Empfaenger. `$attrs` kann ein Array (fuer alle
     * gleich) oder ein Callback fn(int $userId): array sein (pro Empfaenger).
     * Doppelte IDs werden entfernt (ein Betreuer, der zugleich Admin ist,
     * erhaelt genau EINE Benachrichtigung).
     *
     * @param iterable<int|string> $userIds
     * @param array<string,mixed>|callable $attrs
     * @return int Anzahl tatsaechlich zugestellter Empfaenger
     */
    public function pushMany(iterable $userIds, array|callable $attrs): int
    {
        $ids = collect($userIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        $count = 0;
        foreach ($ids as $userId) {
            $payload = is_callable($attrs) ? $attrs($userId) : $attrs;
            if ($payload === null) {
                continue; // Callback kann einzelne Empfaenger ueberspringen
            }
            $this->push($userId, $payload);
            $count++;
        }
        return $count;
    }

    /**
     * Kuerzt Freitextfelder verlustarm auf die Spaltenlaenge und entfernt
     * ausschliesslich hier bekannte Schluessel (Schutz vor Tippfehlern in
     * den Aufrufern -> nur erlaubte Attribute erreichen die DB).
     *
     * @param array<string,mixed> $attrs
     * @return array<string,mixed>
     */
    private function normalize(array $attrs): array
    {
        $out = array_intersect_key($attrs, array_flip([
            'type', 'title', 'body', 'link', 'dedup_key',
            'message_id', 'change_request_id',
        ]));

        if (isset($out['title']) && $out['title'] !== null) {
            $out['title'] = Str::limit((string) $out['title'], self::TITLE_MAX - 1, '…');
        }
        if (isset($out['body']) && $out['body'] !== null) {
            $out['body'] = Str::limit((string) $out['body'], self::BODY_MAX - 1, '…');
        }

        return $out;
    }
}
