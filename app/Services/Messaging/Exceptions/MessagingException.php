<?php

namespace App\Services\Messaging\Exceptions;

/**
 * Wurzel aller Kanal-Fehler.
 *
 * ZWECK: der Conversation Engine darf sich NIE auf die Fehlerformate
 * von Meta, TikTok oder Telegram stuetzen. Der Adapter uebersetzt den
 * Fremdfehler in eine dieser Klassen; erst danach faellt die
 * Entscheidung "erneut versuchen oder aufgeben".
 */
/*
 * NUR ZWEI KLASSEN - und das ist Absicht (Audit 15.09.2026).
 *
 * Hier lagen bis zum Audit fuenf weitere Ausnahmen
 * (ChannelAuthentication, ChannelMessageRejected, ChannelRateLimit,
 * ChannelUnavailable, ChannelMedia). Sie waren sauber entworfen und
 * kommentiert - und wurden NIE geworfen und NIE gefangen. Der einzige
 * echte Kanal (WhatsApp) faengt `\Throwable` und uebersetzt den
 * HTTP-Status selbst in eine Meldung (WhatsAppAdapter::classify()).
 *
 * Warum sie NICHT nachtraeglich verdrahtet wurden: eine eigene
 * Ausnahme je Fehlerart lohnt sich erst, wenn der AUFRUFER auf sie
 * unterschiedlich reagiert - etwa "bei Rate-Limit spaeter erneut
 * senden". Genau das tut der Versand bewusst nicht:
 * SendOutboundMessageJob hat `tries = 1`, weil ein zweiter Versuch eine
 * bereits zugestellte Nachricht doppelt senden koennte. Die Klassen
 * haetten also weiter nur dagestanden.
 *
 * Und Kommentar-Architektur ist nicht harmlos: sie sieht aus wie eine
 * Zusicherung ("Fehler werden unterschieden") und ist keine. Wer den
 * Versand erweitert, haette sich darauf verlassen.
 *
 * Kommt spaeter ein Kanal mit echter Wiederholungslogik dazu, ist eine
 * Ausnahme schnell wieder angelegt - dann aber mit einem Aufrufer, der
 * sie auswertet.
 */
class MessagingException extends \RuntimeException
{}
