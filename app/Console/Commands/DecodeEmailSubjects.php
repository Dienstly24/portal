<?php
namespace App\Console\Commands;

use App\Models\EmailMessage;
use App\Support\MimeHeaderDecoder;
use Illuminate\Console\Command;

/**
 * Einmalige Bereinigung: bereits gespeicherte E-Mails, deren Betreff/
 * Absendername noch als MIME-"encoded-word" ("=?utf-8?Q?...") in der
 * Datenbank stehen, werden nachtraeglich dekodiert. Betrifft Mails, die
 * VOR der Dekodierung im ImapMailboxProvider eingegangen sind.
 */
class DecodeEmailSubjects extends Command
{
    protected $signature = 'emails:decode-subjects {--dry-run : Nur anzeigen, nichts speichern}';

    protected $description = 'Dekodiert MIME-kodierte Betreffs/Absendernamen bereits gespeicherter E-Mails';

    public function handle(): int
    {
        $query = EmailMessage::where('subject', 'like', '%=?%')
            ->orWhere('from_name', 'like', '%=?%');

        $count = 0;
        foreach ($query->cursor() as $message) {
            $newSubject = MimeHeaderDecoder::decode($message->subject);
            $newFromName = MimeHeaderDecoder::decode($message->from_name);

            if ($newSubject === $message->subject && $newFromName === $message->from_name) {
                continue;
            }

            $count++;
            $this->line(sprintf('  %s -> %s', $message->subject, $newSubject));

            if (!$this->option('dry-run')) {
                $message->forceFill([
                    'subject' => $newSubject,
                    'from_name' => $newFromName,
                ])->save();
            }
        }

        $this->info(sprintf('%d E-Mail(s) %s.', $count, $this->option('dry-run') ? 'wuerden dekodiert' : 'dekodiert'));

        return self::SUCCESS;
    }
}
