<?php

namespace App\Services\Ai\Assistant;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\SystemSetting;
use App\Support\AiMode;

/**
 * WELCHE KI-Betriebsart gilt fuer DIESE Unterhaltung?
 * (Auftrag Abschnitte 51/63/85/87/89)
 *
 * Die Hierarchie laeuft von SPEZIFISCH nach ALLGEMEIN:
 *
 *   Unterhaltung -> Kunde -> Kanalkonto -> Kanal -> Global
 *
 * DIE EINE REGEL, auf der alles steht: eine Ebene wirkt NUR, wenn sie
 * ausdruecklich gesetzt ist. `null` heisst ERBEN, nicht "aus". Ohne
 * diese Trennung waere ein nie angefasstes Feld nicht von einer
 * bewussten Abschaltung zu unterscheiden - und ein leeres Formularfeld
 * haette beim ersten Speichern still die globale Vorgabe ueberstimmt.
 *
 * Deshalb gibt `explain()` nicht nur den Wert zurueck, sondern auch die
 * EBENE, die ihn gesetzt hat. Eine Hierarchie, die man nicht ablesen
 * kann, wird beim ersten Widerspruch zur Ratearbeit.
 */
class AiSettingsResolver
{
    /** Globale Betriebsart, wenn der Betreiber sie ausdruecklich waehlt. */
    public const GLOBAL_MODE_KEY = 'ai_assistant_default_mode';

    public function __construct(private readonly AssistantSettings $settings) {}

    /** Die geltende Betriebsart - der Wert, nach dem gehandelt wird. */
    public function modeFor(?Conversation $conversation, ?Customer $customer = null): string
    {
        return $this->explain($conversation, $customer)['mode'];
    }

    /**
     * Betriebsart MIT Herkunft.
     *
     * @return array{mode:string,source:string,label:string}
     */
    public function explain(?Conversation $conversation, ?Customer $customer = null): array
    {
        $customer ??= $conversation?->customer;

        // Reihenfolge = Vorrang. Die erste gesetzte Ebene gewinnt.
        $ebenen = [
            ['Unterhaltung', $conversation?->ai_mode],
            ['Kunde', $customer?->ai_mode],
            ['Kanalkonto', $conversation?->channelAccount?->ai_mode],
            ['Kanal', $conversation?->channel?->ai_mode],
        ];

        foreach ($ebenen as [$quelle, $wert]) {
            if (AiMode::valid($wert)) {
                return ['mode' => $wert, 'source' => $quelle, 'label' => AiMode::label($wert)];
            }
        }

        $global = $this->globalMode();

        return ['mode' => $global, 'source' => 'Global', 'label' => AiMode::label($global)];
    }

    /**
     * Die globale Vorgabe.
     *
     * RUECKWAERTSKOMPATIBEL: solange der Betreiber keine Betriebsart
     * ausdruecklich gewaehlt hat, wird sie aus den BESTEHENDEN Schaltern
     * abgeleitet. Damit aendert diese Umstellung am laufenden Betrieb
     * exakt nichts - der Portal-Chat verhaelt sich nach dem Deploy so wie
     * davor. Eine neue Einstellung, die stillschweigend eine andere
     * Vorgabe mitbringt, waere genau die Art Aenderung, die niemand
     * bemerkt und die hinterher niemand erklaeren kann.
     */
    public function globalMode(): string
    {
        $gewaehlt = (string) SystemSetting::get(self::GLOBAL_MODE_KEY, '');
        if (AiMode::valid($gewaehlt)) {
            return $gewaehlt;
        }

        if (! $this->settings->enabled()) {
            return AiMode::OFF;
        }

        // Hauptschalter an, automatische Antworten aus = der Assistent
        // arbeitet dem Mitarbeiter zu, sendet aber nichts.
        return $this->settings->autoReply() ? AiMode::AUTO_REPLY : AiMode::AI_ASSIST;
    }

    /**
     * Darf fuer diese Unterhaltung automatisch geantwortet werden?
     *
     * Der Hauptschalter bleibt die NOTBREMSE und steht ueber der ganzen
     * Hierarchie: ist der Assistent global aus, hilft kein Override auf
     * einer tieferen Ebene. Ein Notaus, den eine Kundeneinstellung
     * aushebeln kann, ist kein Notaus.
     */
    public function mayAutoReply(?Conversation $conversation, ?Customer $customer = null): bool
    {
        if (! $this->settings->enabled()) {
            return false;
        }

        return AiMode::sendsAutomatically($this->modeFor($conversation, $customer));
    }

    /** Wird das Modell befragt - sei es fuer eine Antwort oder einen Vorschlag? */
    public function mayUseModel(?Conversation $conversation, ?Customer $customer = null): bool
    {
        if (! $this->settings->enabled()) {
            return false;
        }

        return AiMode::usesModel($this->modeFor($conversation, $customer));
    }
}
