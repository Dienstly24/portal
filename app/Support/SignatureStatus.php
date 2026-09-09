<?php

namespace App\Support;

/**
 * DIE EINE Definition der Zustaende einer Signaturanfrage.
 *
 * Sie steht hier und nicht als Zeichenketten-Vergleich in Controllern und
 * Views, weil sonst genau das passiert, was dieses Projekt schon einmal
 * teuer bezahlt hat (Vertragsstatus, 17.08.2026): zwei Stellen zaehlen
 * "offen" verschieden, und der Zaehler im Menue widerspricht der Liste.
 *
 * Der Zustand geht nur VORWAERTS, mit genau drei Ausnahmen: abgebrochen,
 * abgelehnt und abgelaufen koennen aus jedem laufenden Zustand eintreten.
 * Ein "zurueck von abgeschlossen" gibt es nicht - ein unterschriebenes
 * Dokument wird nicht wieder unfertig.
 */
final class SignatureStatus
{
    public const DRAFT = 'draft';

    public const SENT = 'sent';

    public const VIEWED = 'viewed';

    public const PARTIALLY_SIGNED = 'partially_signed';

    public const COMPLETED = 'completed';

    public const EXPIRED = 'expired';

    public const CANCELLED = 'cancelled';

    public const DECLINED = 'declined';

    public const LABELS = [
        self::DRAFT => 'Entwurf',
        self::SENT => 'Gesendet',
        self::VIEWED => 'Geöffnet',
        self::PARTIALLY_SIGNED => 'Teilweise unterschrieben',
        self::COMPLETED => 'Abgeschlossen',
        self::EXPIRED => 'Abgelaufen',
        self::CANCELLED => 'Abgebrochen',
        self::DECLINED => 'Abgelehnt',
    ];

    /** Zustaende, in denen noch unterschrieben werden kann. */
    public const OPEN = [self::SENT, self::VIEWED, self::PARTIALLY_SIGNED];

    /** Endzustaende - hier passiert nichts mehr von selbst. */
    public const FINAL = [self::COMPLETED, self::EXPIRED, self::CANCELLED, self::DECLINED];

    /**
     * Die Reiter der Uebersicht. "In Bearbeitung" fasst zusammen, was
     * unterwegs ist: gesendet, geoeffnet, teilweise unterschrieben. Fuer den
     * Mitarbeiter ist das EIN Zustand ("wartet auf den Kunden"), auch wenn
     * das System drei unterscheidet.
     *
     * @var array<string, array{label: string, statuses: list<string>}>
     */
    public const TABS = [
        'alle' => ['label' => 'Alle', 'statuses' => []],
        'entwuerfe' => ['label' => 'Entwürfe', 'statuses' => [self::DRAFT]],
        'gesendet' => ['label' => 'Gesendet', 'statuses' => [self::SENT]],
        'in-bearbeitung' => ['label' => 'In Bearbeitung', 'statuses' => [self::VIEWED, self::PARTIALLY_SIGNED]],
        'abgeschlossen' => ['label' => 'Abgeschlossen', 'statuses' => [self::COMPLETED]],
        'abgelaufen' => ['label' => 'Abgelaufen', 'statuses' => [self::EXPIRED]],
        'abgebrochen' => ['label' => 'Abgebrochen', 'statuses' => [self::CANCELLED, self::DECLINED]],
    ];

    public static function label(?string $status): string
    {
        return self::LABELS[$status] ?? '—';
    }

    /** Farbton fuer das Abzeichen - Ampel, nicht Markenfarbe. */
    public static function tone(string $status): string
    {
        return match ($status) {
            self::COMPLETED => 'badge-success',
            self::CANCELLED, self::DECLINED, self::EXPIRED => 'badge-danger',
            self::DRAFT => 'badge-muted',
            default => 'badge-pending',
        };
    }

    public static function isOpen(string $status): bool
    {
        return in_array($status, self::OPEN, true);
    }

    public static function isFinal(string $status): bool
    {
        return in_array($status, self::FINAL, true);
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::LABELS);
    }
}
