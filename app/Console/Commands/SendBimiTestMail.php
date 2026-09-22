<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Echte Testmail fuer den DMARC-Nachweis (Betreiber-Vorgabe 22.09.2026,
 * Punkte 7 und 8: "nicht nur DNS, sondern eine ECHTE Nachricht").
 *
 * WARUM DAS NICHT AUS DEM DNS ABLESBAR IST: der DNS sagt nur, WAS erlaubt
 * ist. Ob die tatsaechlich versandte Nachricht auch besteht, haengt am
 * Versandweg - ob der SMTP-Server wirklich DKIM signiert, ob die Signatur
 * unterwegs bricht, und vor allem ob die signierende Domain zur Domain im
 * sichtbaren Von-Feld passt (AUSRICHTUNG). Genau das entscheidet ueber BIMI,
 * und genau das beweist nur eine echte Mail.
 *
 * Der Befehl verschickt GENAU EINE Nachricht an die angegebene Adresse und
 * aendert sonst nichts. Er ist bewusst kein Massenversand und hat keinen
 * Standardempfaenger - die Adresse muss jedes Mal genannt werden.
 */
class SendBimiTestMail extends Command
{
    protected $signature = 'bimi:testmail {adresse : Zieladresse, fuer den Nachweis am besten ein echtes Gmail-Konto}';

    protected $description = 'Eine echte Testmail versenden, um SPF/DKIM/DMARC am Empfaenger nachzuweisen';

    public function handle(): int
    {
        $adresse = (string) $this->argument('adresse');

        if (! filter_var($adresse, FILTER_VALIDATE_EMAIL)) {
            $this->error('Das ist keine gueltige E-Mail-Adresse.');

            return self::FAILURE;
        }

        $von = (string) config('mail.from.address');
        $domain = str_contains($von, '@') ? explode('@', $von)[1] : '(unbekannt)';
        $kennung = 'BIMI-Test '.now()->format('d.m.Y H:i:s');

        $this->line('');
        $this->line('Versandweg:      '.config('mail.default'));
        $this->line('Absender:        '.$von);
        $this->line('Empfaenger:      '.$adresse);

        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            $this->warn('Der Versandweg steht auf "'.config('mail.default').'" - es geht KEINE echte Mail raus.');

            return self::FAILURE;
        }

        try {
            Mail::raw(
                "Dies ist eine technische Testnachricht zur Pruefung von SPF, DKIM und DMARC.\n"
                .'Kennung: '.$kennung."\n\n"
                ."Bitte im Postfach oeffnen und 'Original anzeigen' waehlen.",
                fn ($m) => $m->to($adresse)->subject($kennung)
            );
        } catch (\Throwable $e) {
            $this->error('Versand fehlgeschlagen: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Nachricht abgeschickt.');
        $this->line('');
        $this->line('So wird sie ausgewertet (in Gmail: Nachricht oeffnen -> Menue -> "Original anzeigen"):');
        $this->line('');
        $this->line('  1. SPF:   muss "PASS" sein UND die Domain '.$domain.' nennen.');
        $this->line('  2. DKIM:  muss "PASS" sein UND als Domain (d=) '.$domain.' nennen -');
        $this->line('            eine fremde d=-Domain besteht zwar, ist aber NICHT ausgerichtet.');
        $this->line('  3. DMARC: muss "PASS" sein. Das ist die eigentliche Aussage:');
        $this->line('            DMARC besteht nur, wenn SPF ODER DKIM ausgerichtet ist.');
        $this->line('');
        $this->line('  In der Kopfzeile "Authentication-Results" steht alles drei nebeneinander.');
        $this->line('  Nur wenn dort dmarc=pass steht, kann BIMI ueberhaupt greifen.');
        $this->line('');
        $this->line('  Aus jeder weiteren Absenderadresse, die der Betrieb benutzt, eine eigene senden.');

        return self::SUCCESS;
    }
}
