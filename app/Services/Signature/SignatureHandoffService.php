<?php

namespace App\Services\Signature;

use App\Models\SignatureHandoff;
use App\Models\User;
use App\Support\Unterschriftsbild;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * "Auf dem Handy unterschreiben" (Betreiber-Vorgabe 07.10.2026).
 *
 * Am Rechner ohne Touch erscheint ein QR-Code; gezeichnet wird auf dem
 * Telefon, und die Zeichnung erscheint am Rechner. DREI SCHRANKEN:
 *  - KURZLEBIG: 10 Minuten, danach ist der Link tot (HTTP 410).
 *  - EINMAL: eine Zeichnung je Link; ein zweiter Versuch wird abgelehnt.
 *  - GEBUNDEN: das Ergebnis bekommt nur DIESES Konto in DERSELBEN Sitzung,
 *    die den Link erzeugt hat. Ein abfotografierter QR-Code nuetzt einem
 *    Dritten nichts - er kann zeichnen, aber das Bild landet nie bei ihm,
 *    und am Rechner des Mitarbeiters steht es erst nach dessen Bestaetigung
 *    (Bestaetigungssatz + Zwei-Faktor wie bei jeder internen Unterschrift).
 *
 * Gespeichert wird nur der SHA-256 des Tokens (wie beim Unterzeichner-Link).
 */
class SignatureHandoffService
{
    public function __construct(private readonly SignatureStorage $storage)
    {
    }

    /** @return array{handoff: SignatureHandoff, url: string} */
    public function erstellen(Request $http, User $user, string $kind = 'unterschrift'): array
    {
        $token = Str::random(40);
        $handoff = SignatureHandoff::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'session_hash' => $this->sitzung($http),
            'kind' => $kind,
            'expires_at' => now()->addMinutes(SignatureHandoff::GUELTIG_MINUTEN),
        ]);

        return ['handoff' => $handoff, 'url' => route('signature.handoff.show', $token)];
    }

    /** Der Zugang zu einem Token - oder null (unbekannt). Abgelaufen/benutzt entscheidet der Aufrufer. */
    public function finde(string $token): ?SignatureHandoff
    {
        if (strlen($token) !== 40) {
            return null;
        }

        return SignatureHandoff::where('token_hash', hash('sha256', $token))->first();
    }

    /**
     * Nimmt die Zeichnung vom Telefon entgegen.
     *
     * @throws \RuntimeException
     */
    public function zeichnungAnnehmen(SignatureHandoff $handoff, string $dataUrl, ?string $geraet): void
    {
        if (! $handoff->istOffen()) {
            throw new \RuntimeException('Dieser Link ist abgelaufen oder wurde schon benutzt.');
        }
        $png = Unterschriftsbild::ausZeichnung($dataUrl);
        if ($png === null) {
            throw new \RuntimeException('Die Zeichnung ist leer. Bitte unterschreiben.');
        }
        $png = Unterschriftsbild::zuschneiden($png);
        $pfad = 'mitarbeiter-unterschriften/handy/'.$handoff->id.'.png';
        if ($this->storage->disk()->put($pfad, $png) === false) {
            throw new \RuntimeException('Die Zeichnung konnte nicht gespeichert werden.');
        }
        // EINMAL: used_at wird nur gesetzt, wenn es noch leer ist - zwei
        // Absendungen im selben Moment schreiben nicht beide.
        $getroffen = SignatureHandoff::whereKey($handoff->id)->whereNull('used_at')->update([
            'used_at' => now(),
            'image_path' => $pfad,
            'device' => $geraet === null ? null : mb_substr($geraet, 0, 160),
        ]);
        if ($getroffen === 0) {
            throw new \RuntimeException('Dieser Link wurde schon benutzt.');
        }
    }

    /**
     * Das Ergebnis fuer den RECHNER - nur fuer das Konto und die Sitzung,
     * die den Link erzeugt haben. Null, solange nichts gezeichnet ist (oder
     * wenn es nicht diesem Konto gehoert - von aussen nicht unterscheidbar).
     */
    public function ergebnis(User $user, string $id, ?Request $http = null): ?string
    {
        $http ??= request();
        $handoff = SignatureHandoff::whereKey($id)->where('user_id', $user->id)->first();
        if ($handoff === null || $handoff->image_path === null || ! hash_equals($handoff->session_hash, $this->sitzung($http))) {
            return null;
        }
        // Ein Ergebnis wird nur kurz nach dem Zeichnen angenommen.
        if ($handoff->used_at === null || $handoff->used_at->lt(now()->subMinutes(SignatureHandoff::GUELTIG_MINUTEN * 3))) {
            return null;
        }

        return $this->storage->read($handoff->image_path);
    }

    /**
     * Die Bindung an die SITZUNG: ein Zufallswert, der nur in der Sitzung
     * des Rechners liegt (nicht die Sitzungs-ID - die wechselt bei jeder
     * Anmeldung und wuerde nichts zusaetzlich schuetzen). Gespeichert wird
     * nur sein Hash.
     */
    private function sitzung(Request $http): string
    {
        if (! $http->hasSession()) {
            return hash('sha256', 'ohne-sitzung|'.Str::random(40));
        }
        $wert = $http->session()->get('sig_handoff_bindung');
        if (! is_string($wert) || strlen($wert) !== 40) {
            $wert = Str::random(40);
            $http->session()->put('sig_handoff_bindung', $wert);
        }

        return hash('sha256', $wert);
    }
}
