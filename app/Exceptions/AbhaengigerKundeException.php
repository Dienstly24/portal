<?php

namespace App\Exceptions;

/**
 * Ein Vertrag (oder ein anderes Geschaeft) soll an die Akte eines
 * abhaengigen Kindes - das ist nicht erlaubt (KI-094, 07.10.2026). Die
 * Meldung ist fuer Menschen geschrieben und darf angezeigt werden.
 */
class AbhaengigerKundeException extends \DomainException
{}
