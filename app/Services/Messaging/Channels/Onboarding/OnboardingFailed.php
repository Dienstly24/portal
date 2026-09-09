<?php

namespace App\Services\Messaging\Channels\Onboarding;

/**
 * Die Anbindung ist gescheitert - mit einem Satz, den ein Admin lesen
 * kann. Fremde Fehlermeldungen und Zugangsdaten gehen NIE in diese
 * Ausnahme: sie landet in der Oberflaeche.
 */
class OnboardingFailed extends \RuntimeException
{}
