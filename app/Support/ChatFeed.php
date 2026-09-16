<?php

namespace App\Support;

use App\Models\CustomerMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Der Chat-Feed - EINE Stelle fuer Beraterwelt UND Kundenportal
 * (Audit 15.09.2026).
 *
 * WARUM (gemessener Befund): beide Feeds holten bei JEDEM Abruf den
 * KOMPLETTEN Verlauf des Kunden - ohne Grenze, mit allen Anhaengen und
 * Absendern. Der Feed ist aber ein POLLING-Endpunkt: alle 10 Sekunden,
 * mit einer Drossel von 120 Abrufen je Minute. Eine Unterhaltung, die
 * ueber Monate waechst (und der KI-Assistent antwortet mit), wird damit
 * hundertfach am Tag vollstaendig gelesen, serialisiert und uebertragen.
 * Heute faellt das nicht auf; es ist genau die Art Last, die mit dem
 * Bestand waechst und dann auf einmal da ist.
 *
 * NEU: der Client schickt seinen Stand mit (`?seit=`), und es kommt nur
 * zurueck, was sich seither geaendert hat.
 *
 * WARUM `updated_at` UND NICHT `created_at`: der Feed muss zwei Dinge
 * melden - NEUE Nachrichten und den Lesehaken an EIGENEN, bereits
 * gesendeten Nachrichten. Der Lesehaken aendert `read_at` und damit
 * `updated_at`, nicht `created_at`. Wer nur nach neuen Nachrichten
 * fragt, bekommt den Haken nie zu sehen.
 *
 * UEBERLAPPUNG: der Stand wird um ein paar Sekunden zurueckdatiert. Eine
 * Nachricht, die in derselben Sekunde entsteht, in der die Antwort
 * rausgeht, ginge sonst verloren. Doppelt gelieferte Nachrichten sind
 * dagegen harmlos - die Oberflaeche erkennt sie an ihrer ID und haengt
 * sie kein zweites Mal an.
 */
class ChatFeed
{
    /** So viele Nachrichten zeigt der erste Aufbau der Seite. */
    public const SEITENGROESSE = 50;

    /** Sekunden Ueberlappung gegen Nachrichten in derselben Sekunde. */
    private const UEBERLAPPUNG = 5;

    /**
     * Der Stand, den der Client mitgeschickt hat - oder null fuer
     * "alles" (erster Abruf).
     */
    public static function stand(Request $request): ?Carbon
    {
        $roh = trim((string) $request->query('seit', ''));

        if ($roh === '') {
            return null;
        }

        try {
            $zeit = Carbon::parse($roh);
        } catch (\Throwable) {
            return null;
        }

        // Ein Stand aus der Zukunft wuerde alles ausblenden. Eine
        // manipulierte oder falsch gestellte Uhr darf den Chat nicht
        // leeren - im Zweifel lieber alles ausliefern.
        return $zeit->isFuture() ? null : $zeit;
    }

    /**
     * Der neue Stand fuer die naechste Abfrage.
     */
    public static function neuerStand(): string
    {
        return now()->subSeconds(self::UEBERLAPPUNG)->toIso8601String();
    }

    /**
     * Die Nachrichten einer Unterhaltung.
     *
     * @param  Carbon|null  $seit  null = die letzte Seite (erster Aufbau)
     * @return Collection<int, CustomerMessage>
     */
    public static function nachrichten(string $customerId, ?Carbon $seit, bool $allesLaden = false): Collection
    {
        $query = self::basis($customerId);

        if ($seit !== null) {
            // Nur was sich seither geaendert hat - neu ODER gelesen.
            return $query->where('customer_messages.updated_at', '>=', $seit)
                ->orderBy('created_at')->orderBy('id')
                ->get();
        }

        if ($allesLaden) {
            return $query->orderBy('created_at')->orderBy('id')->get();
        }

        // Erster Aufbau: die JUENGSTEN N - und danach wieder in die
        // richtige Reihenfolge bringen. `latest()` + `reverse()` statt
        // eines Offsets: der Verlauf waechst am ENDE, ein Offset vom
        // Anfang waere bei jeder neuen Nachricht ein anderer.
        return $query->orderByDesc('created_at')->orderByDesc('id')
            ->limit(self::SEITENGROESSE)
            ->get()
            ->sortBy([['created_at', 'asc'], ['id', 'asc']])
            ->values();
    }

    /**
     * Aeltere Nachrichten VOR einer bekannten ("Mehr laden").
     *
     * @return Collection<int, CustomerMessage>
     */
    public static function aeltere(string $customerId, string $vorId): Collection
    {
        $anker = CustomerMessage::where('customer_id', $customerId)->find($vorId);

        if (! $anker) {
            return collect();
        }

        return self::basis($customerId)
            ->where(function (Builder $w) use ($anker) {
                $w->where('created_at', '<', $anker->created_at)
                    ->orWhere(function (Builder $g) use ($anker) {
                        $g->where('created_at', $anker->created_at)
                            ->where('id', '<', $anker->id);
                    });
            })
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit(self::SEITENGROESSE)
            ->get()
            ->sortBy([['created_at', 'asc'], ['id', 'asc']])
            ->values();
    }

    /** Gibt es VOR dieser Nachricht noch aeltere? */
    public static function hatAeltere(string $customerId, ?CustomerMessage $aelteste): bool
    {
        if (! $aelteste) {
            return false;
        }

        return CustomerMessage::where('customer_id', $customerId)
            ->where(function (Builder $w) use ($aelteste) {
                $w->where('created_at', '<', $aelteste->created_at)
                    ->orWhere(function (Builder $g) use ($aelteste) {
                        $g->where('created_at', $aelteste->created_at)
                            ->where('id', '<', $aelteste->id);
                    });
            })
            ->exists();
    }

    /**
     * Ungelesene zaehlen - als COUNT, nicht durch Laden.
     *
     * Vorher wurde dafuer die gesamte geladene Sammlung gefiltert. Das
     * geht nicht mehr, sobald nur noch ein Ausschnitt geladen wird - und
     * war auch vorher die teurere Art, eine Zahl zu bekommen.
     */
    public static function ungelesen(string $customerId, bool $staffView): int
    {
        return CustomerMessage::where('customer_id', $customerId)
            ->where('from_staff', $staffView ? false : true)
            ->whereNull('read_at')
            ->count();
    }

    /** @return Builder<CustomerMessage> */
    private static function basis(string $customerId): Builder
    {
        return CustomerMessage::where('customer_id', $customerId)
            ->with(['sender', 'attachments', 'customer.user']);
    }
}
