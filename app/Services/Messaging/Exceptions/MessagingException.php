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
class MessagingException extends \RuntimeException
{}
