<?php

namespace App\Services\Messaging\Inbox;

use App\Models\Conversation;
use Illuminate\Http\Request;

/**
 * Die Auswahl des Postfachs als OBJEKT.
 *
 * Dieselbe Lehre wie beim Auswertungs-Dashboard (06.09.2026): der Stand
 * einer Liste gehoert in die Adresse, nicht in einen Browser-Zustand -
 * dann ist er teilbar, zurueck-tauglich und als Lesezeichen brauchbar.
 * Unbekannte Werte werden VERWORFEN, nie durchgereicht.
 *
 * WICHTIG: hier steht kein Kanalname. `channel` traegt einen Schluessel
 * aus der Datenbank; ob der `whatsapp` oder `telegram` heisst, weiss
 * diese Klasse nicht - genau deshalb kostet ein neuer Kanal hier nichts.
 */
final class InboxFilters
{
    /** Die drei Sichten der Kopfzeile. */
    public const VIEW_ALL = 'alle';
    public const VIEW_UNREAD = 'ungelesen';
    public const VIEW_MINE = 'meine';

    public const VIEWS = [
        self::VIEW_ALL => 'Alle',
        self::VIEW_UNREAD => 'Ungelesen',
        self::VIEW_MINE => 'Meine',
    ];

    public function __construct(
        public readonly string $view = self::VIEW_ALL,
        public readonly ?string $channel = null,
        public readonly ?int $assignee = null,
        public readonly ?int $betreuer = null,
        public readonly ?string $status = null,
        public readonly ?string $customer = null,
        public readonly ?int $account = null,
        public readonly ?string $from = null,
        public readonly ?string $to = null,
        public readonly string $search = '',
    ) {}

    /**
     * Aus der Anfrage lesen. Jeder Wert wird geprueft: ein erfundener
     * Kanal oder Status faellt weg, statt eine leere Liste zu erzeugen,
     * die wie "nichts da" aussieht.
     *
     * @param array<int,string> $kanalSchluessel gueltige Kanaele aus der Datenbank
     */
    public static function fromRequest(Request $request, array $kanalSchluessel): self
    {
        $view = (string) $request->query('sicht', self::VIEW_ALL);
        $status = (string) $request->query('status', '');
        $kanal = (string) $request->query('kanal', '');

        return new self(
            view: isset(self::VIEWS[$view]) ? $view : self::VIEW_ALL,
            channel: in_array($kanal, $kanalSchluessel, true) ? $kanal : null,
            assignee: self::zahl($request->query('zustaendig')),
            betreuer: self::zahl($request->query('betreuer')),
            status: isset(Conversation::STATUSES[$status]) ? $status : null,
            customer: ((string) $request->query('kunde', '')) ?: null,
            account: self::zahl($request->query('konto')),
            from: self::datum($request->query('von')),
            to: self::datum($request->query('bis')),
            search: trim((string) $request->query('q', '')),
        );
    }

    /** Ob ueberhaupt etwas eingeschraenkt ist - fuer "Filter zuruecksetzen". */
    public function isFiltered(): bool
    {
        return $this->view !== self::VIEW_ALL
            || $this->channel !== null || $this->assignee !== null
            || $this->betreuer !== null || $this->status !== null
            || $this->customer !== null || $this->account !== null
            || $this->from !== null || $this->to !== null
            || $this->search !== '';
    }

    /**
     * Dieselbe Auswahl mit einem geaenderten Wert - fuer die Links der
     * Kopfzeile und der Kanalliste.
     *
     * @return array<string,string>
     */
    public function urlParams(array $ueberschreiben = []): array
    {
        $params = array_filter([
            'sicht' => $this->view === self::VIEW_ALL ? null : $this->view,
            'kanal' => $this->channel,
            'zustaendig' => $this->assignee,
            'betreuer' => $this->betreuer,
            'status' => $this->status,
            'kunde' => $this->customer,
            'konto' => $this->account,
            'von' => $this->from,
            'bis' => $this->to,
            'q' => $this->search ?: null,
        ], fn ($v) => $v !== null && $v !== '');

        foreach ($ueberschreiben as $schluessel => $wert) {
            if ($wert === null || $wert === '') {
                unset($params[$schluessel]);
            } else {
                $params[$schluessel] = $wert;
            }
        }

        return array_map('strval', $params);
    }

    private static function zahl(mixed $wert): ?int
    {
        return is_numeric($wert) && (int) $wert > 0 ? (int) $wert : null;
    }

    /** Nur ein echtes ISO-Datum zaehlt - alles andere waere geraten. */
    private static function datum(mixed $wert): ?string
    {
        $text = trim((string) $wert);
        if ($text === '') {
            return null;
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) ? $text : null;
    }
}
