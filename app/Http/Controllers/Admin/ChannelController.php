<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Channel;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\SystemSetting;
use App\Services\Ai\Assistant\AiSettingsResolver;
use App\Services\Messaging\Channels\ChannelManager;
use App\Services\Messaging\Dto\ConnectionTest;
use App\Support\AiMode;
use Illuminate\Http\Request;

/**
 * Kanal-Verwaltung (/admin/kanaele) - Auftrag Abschnitte 68-72, 86, 98-99.
 *
 * ZIEL: eine neue Kanal-Anbindung oder ein zweites Geschaeftskonto darf
 * KEINE Codeaenderung mehr kosten. Alles, was den Betrieb betrifft -
 * an/aus, Zugangsdaten, KI-Betriebsart, Verbindungstest - steht hier.
 *
 * ZUGRIFF NUR ADMIN: auf dieser Seite liegen Zugangsdaten. Dieselbe
 * Haltung wie beim 2FA-Reset in der Mitarbeiterakte.
 *
 * DREI REGELN ZU GEHEIMNISSEN (Abschnitt 96), alle als Test gesichert:
 *  1. Ein Zugangswert verlaesst den Server NIE wieder - die Oberflaeche
 *     zeigt ausschliesslich "gesetzt" oder "fehlt".
 *  2. Ein LEER abgeschicktes Feld loescht nichts. Sonst raeumt jedes
 *     Speichern der KI-Betriebsart nebenbei das Token ab - ein Fehler,
 *     der erst auffaellt, wenn die naechste Nachricht nicht rausgeht.
 *  3. Protokolliert werden nur die SCHLUESSEL, nie die Werte.
 */
class ChannelController extends Controller
{
    public function index(ChannelManager $manager, AiSettingsResolver $resolver)
    {
        $channels = Channel::with(['accounts' => fn ($q) => $q->orderBy('name')])
            ->orderBy('sort')->orderBy('name')->get();

        // Zaehler je Kanal: eine Abschaltung ist leichter zu entscheiden,
        // wenn man sieht, wie viel daran haengt.
        $counts = Conversation::selectRaw('channel_id, count(*) as anzahl')
            ->groupBy('channel_id')->pluck('anzahl', 'channel_id');

        return view('admin.channels.index', [
            'channels' => $channels,
            'counts' => $counts,
            'manager' => $manager,
            'globalMode' => $resolver->globalMode(),
            'globalModeExplicit' => (string) SystemSetting::get(AiSettingsResolver::GLOBAL_MODE_KEY, ''),
            'modes' => AiMode::LABELS,
            'modeHints' => AiMode::DESCRIPTIONS,
        ]);
    }

    /** Kanal-Ebene: an/aus und KI-Betriebsart (Abschnitte 72/87/89). */
    public function updateChannel(Request $request, int $id)
    {
        $channel = Channel::findOrFail($id);

        $data = $request->validate([
            'is_active' => ['nullable', 'boolean'],
            'ai_mode' => ['nullable', 'string', 'in:'.implode(',', AiMode::ALL)],
        ]);

        $channel->update([
            'is_active' => (bool) ($data['is_active'] ?? false),
            // Leerer Wert = ERBEN, nicht "aus". Deshalb null und nicht ''.
            // `validate()` liefert nur ANWESENDE Schluessel - ein
            // weggelassenes optionales Feld fehlt im Ergebnis ganz, ein
            // direkter Zugriff waere ein 500er im Alltagsfall.
            'ai_mode' => ($data['ai_mode'] ?? '') ?: null,
        ]);
        cache()->forget('channel_id:'.$channel->key);

        ActivityLog::record('channel_updated', 'channel', $channel->id, [
            'key' => $channel->key,
            'is_active' => $channel->is_active,
            'ai_mode' => $channel->ai_mode,
        ]);

        return back()->with('success', 'Kanal "'.$channel->name.'" gespeichert.');
    }

    /** Neues Kanalkonto (Abschnitt 99). */
    public function storeAccount(Request $request, int $channelId)
    {
        $channel = Channel::findOrFail($channelId);
        $data = $this->validateAccount($request);

        $account = new ChannelAccount([
            'channel_id' => $channel->id,
            'name' => $data['name'],
            'external_account_id' => ($data['external_account_id'] ?? '') ?: null,
            'ai_mode' => ($data['ai_mode'] ?? '') ?: null,
            'is_active' => (bool) ($data['is_active'] ?? false),
        ]);
        $account->credentials = $this->mergeCredentials([], $data['credentials'] ?? []);
        $account->save();

        ActivityLog::record('channel_account_created', 'channel_account', $account->id, [
            'channel' => $channel->key,
            'name' => $account->name,
            'credential_keys' => array_keys($account->credentials ?? []),
        ]);

        return redirect()->route('admin.channels.index')
            ->with('success', 'Konto "'.$account->name.'" angelegt.');
    }

    public function updateAccount(Request $request, int $id)
    {
        $account = ChannelAccount::findOrFail($id);
        $data = $this->validateAccount($request);

        $account->fill([
            'name' => $data['name'],
            'external_account_id' => ($data['external_account_id'] ?? '') ?: null,
            'ai_mode' => ($data['ai_mode'] ?? '') ?: null,
            'is_active' => (bool) ($data['is_active'] ?? false),
        ]);
        // NUR ausgefuellte Felder ueberschreiben - siehe Regel 2 oben.
        $account->credentials = $this->mergeCredentials(
            $account->credentials ?? [],
            $data['credentials'] ?? []
        );
        $account->save();

        ActivityLog::record('channel_account_updated', 'channel_account', $account->id, [
            'name' => $account->name,
            'is_active' => $account->is_active,
            'ai_mode' => $account->ai_mode,
            'credential_keys' => array_keys($account->credentials ?? []),
        ]);

        return back()->with('success', 'Konto "'.$account->name.'" gespeichert.');
    }

    /**
     * Zugang trennen (Abschnitt 98).
     *
     * Loescht die ZUGANGSDATEN und schaltet das Konto ab - aber KEINE
     * Unterhaltung und keine Nachricht. Der Verlauf gehoert dem Kunden
     * und dem Betrieb, nicht der Anbindung; ein versehentliches
     * "Trennen" darf keine Kundenhistorie kosten.
     */
    public function disconnectAccount(int $id)
    {
        $account = ChannelAccount::findOrFail($id);
        $account->forceFill([
            'credentials' => null,
            'token_expires_at' => null,
            'is_active' => false,
        ])->save();

        ActivityLog::record('channel_account_disconnected', 'channel_account', $account->id, [
            'name' => $account->name,
        ]);

        return back()->with('success',
            'Zugang zu "'.$account->name.'" getrennt. Unterhaltungen und Nachrichten bleiben vollstaendig erhalten.');
    }

    /** Verbindungstest (Abschnitte 70/97). */
    public function testAccount(int $id, ChannelManager $manager)
    {
        $account = ChannelAccount::with('channel')->findOrFail($id);

        if (! $manager->has($account->channel->key)) {
            return back()->with('warning', 'Fuer diesen Kanal ist kein Adapter registriert.');
        }

        try {
            $result = $manager->driver($account->channel->key)->testConnection($account);
        } catch (\Throwable $e) {
            // Die Fremdmeldung wird NICHT durchgereicht: sie kann einen
            // Schluessel enthalten. Der Grund steht im Log, nicht auf der Seite.
            report($e);
            $result = ConnectionTest::make(ConnectionTest::UNAVAILABLE);
        }

        ActivityLog::record('channel_account_tested', 'channel_account', $account->id, [
            'name' => $account->name,
            'status' => $result->status,
        ]);

        return back()->with($result->ok() ? 'success' : 'warning',
            'Verbindungstest "'.$account->name.'": '.$result->label().' - '.$result->message);
    }

    /** Globale KI-Betriebsart (Abschnitte 50/86). */
    public function updateGlobalAiMode(Request $request)
    {
        $data = $request->validate([
            'ai_mode' => ['nullable', 'string', 'in:'.implode(',', AiMode::ALL)],
        ]);

        // Leer = zurueck auf die Ableitung aus den bestehenden Schaltern.
        $mode = ($data['ai_mode'] ?? '') ?: '';
        SystemSetting::set(AiSettingsResolver::GLOBAL_MODE_KEY, $mode);

        ActivityLog::record('ai_global_mode_changed', 'setting', null, [
            'ai_mode' => $mode ?: '(abgeleitet)',
        ]);

        return back()->with('success', 'Globale KI-Betriebsart gespeichert.');
    }

    /** @return array<string,mixed> */
    private function validateAccount(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'external_account_id' => ['nullable', 'string', 'max:190'],
            'ai_mode' => ['nullable', 'string', 'in:'.implode(',', AiMode::ALL)],
            'is_active' => ['nullable', 'boolean'],
            'credentials' => ['nullable', 'array'],
            'credentials.*' => ['nullable', 'string', 'max:4000'],
        ]);
    }

    /**
     * Bestand + Neueingaben zusammenfuehren. Ein leeres Feld heisst
     * "unveraendert lassen", nicht "loeschen" - zum Loeschen gibt es
     * bewusst den eigenen Weg "Zugang trennen".
     *
     * @param  array<string,mixed>  $vorhanden
     * @param  array<string,mixed>  $eingabe
     * @return array<string,string>|null
     */
    private function mergeCredentials(array $vorhanden, array $eingabe): ?array
    {
        foreach ($eingabe as $schluessel => $wert) {
            $wert = trim((string) $wert);
            if ($wert !== '') {
                $vorhanden[$schluessel] = $wert;
            }
        }

        return $vorhanden ?: null;
    }
}
