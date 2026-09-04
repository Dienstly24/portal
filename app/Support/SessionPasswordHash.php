<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Haelt die Sitzung des Nutzers am Leben, der GERADE sein Passwort
 * geaendert hat.
 *
 * Hintergrund: Seit der Haertung laeuft AuthenticateSession in der
 * Web-Gruppe. Die Middleware merkt sich beim ersten Aufruf den
 * Passwort-Hash in der Sitzung und wirft bei jeder Abweichung raus -
 * genau so sterben fremde Sitzungen nach einem Passwortwechsel, und
 * genau das wollen wir.
 *
 * Der Haken: Es trifft auch die EIGENE Sitzung, denn nach dem Wechsel
 * steht in ihr noch der alte Hash. Der Nutzer haette sein Passwort
 * geaendert und waere im selben Moment abgemeldet - er wuerde denken,
 * es habe nicht geklappt, und es noch einmal versuchen.
 *
 * Deshalb wird der Hash hier AUSDRUECKLICH nachgezogen, statt sich auf
 * Interna des Guards zu verlassen. Immer NACH dem Speichern und nach
 * einem eventuellen logoutOtherDevices() aufrufen.
 */
class SessionPasswordHash
{
    public static function refresh(Request $request): void
    {
        if (! $request->hasSession()) {
            return;
        }

        // Beim Login-Ereignis ist der Guard schon gesetzt, $request->user()
        // aber je nach Zeitpunkt noch nicht aufgeloest - deshalb beide
        // Quellen.
        $user = $request->user() ?? Auth::user();
        if (! $user) {
            return;
        }

        $request->session()->put(
            'password_hash_'.Auth::getDefaultDriver(),
            $user->getAuthPassword(),
        );
    }
}
