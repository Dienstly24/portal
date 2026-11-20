<?php

namespace App\Support;

use App\Models\SystemSetting;

/**
 * Die EINE Stelle fuer die Frage "ist das Kundenportal offen?" und fuer
 * die Kontaktwege, die solange an seine Stelle treten
 * (Betreiber-Vorgabe 20.11.2026, keine Erlaubnis nach Paragraph 34d GewO).
 *
 * Jede Vorlage, jede Mail und die Sperre selbst lesen von hier - sonst
 * zeigt eine vergessene Vorlage weiter einen Portal-Link, waehrend der
 * Server ihn abweist.
 */
final class Kundenportal
{
    public static function aktiv(): bool
    {
        return (bool) config('portal.enabled', false);
    }

    public static function gesperrt(): bool
    {
        return ! self::aktiv();
    }

    /** WhatsApp-Nummer nur aus Ziffern (wa.me verlangt das so). */
    public static function whatsappNummer(): string
    {
        $roh = (string) (config('portal.whatsapp') ?: config('website.whatsapp', ''));

        return preg_replace('/\D+/', '', $roh) ?? '';
    }

    public static function whatsappLink(?string $text = null): ?string
    {
        $nummer = self::whatsappNummer();
        if ($nummer === '') {
            return null;
        }

        return 'https://wa.me/'.$nummer.($text !== null && $text !== '' ? '?text='.rawurlencode($text) : '');
    }

    public static function telefonAnzeige(): string
    {
        return (string) config('website.phone_display', '');
    }

    public static function telefonLink(): string
    {
        return 'tel:'.(string) config('website.phone_e164', '');
    }

    public static function email(): string
    {
        return (string) config('website.email', '');
    }

    /**
     * Absoluter Chat-Link fuer E-Mails. Optional mit signiertem Bezug
     * (ChatReferenz) - der verraet dem Besucher nichts und oeffnet nichts.
     */
    public static function chatUrl(?string $ref = null): string
    {
        $basis = rtrim((string) (config('portal.chat_base_url')
            ?: SystemSetting::get('portal_url', 'https://portal.dienstly24.de')), '/');

        return $basis.'/chat'.($ref !== null ? '?ref='.rawurlencode($ref) : '');
    }
}
