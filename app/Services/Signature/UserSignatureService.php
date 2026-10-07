<?php

namespace App\Services\Signature;

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\UserSignature;
use App\Services\Notifications\NotificationService;
use App\Support\Bildfreistellung;
use App\Support\Unterschriftsbild;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * "Meine Unterschrift": Anlegen, Ersetzen und Lesen der hinterlegten
 * Unterschrift eines Mitarbeiters (Betreiber-Vorgabe 07.10.2026).
 *
 * REGELN:
 *  - Nur fuer sich SELBST und nur mit dem Recht "Darf fuer das Unternehmen
 *    unterschreiben" - geprueft im Controller UND hier.
 *  - Gezeichnet ODER hochgeladen, beides gleichwertig. Ein hochgeladenes
 *    Bild wird freigestellt, zugeschnitten und auf Kontrast geprueft; ein
 *    zu blasses Bild wird abgelehnt, bevor es in einem Vertrag landet.
 *  - Ersetzen ARCHIVIERT die alte Fassung, nie loeschen: ein frueheres
 *    Protokoll nennt ihren Hash.
 *  - JEDE Aenderung meldet die Glocke an die Administratoren und steht im
 *    ActivityLog - eine hinterlegte Unterschrift ist ein Schluessel, und
 *    der Betrieb soll wissen, wann einer neu gemacht wird.
 */
class UserSignatureService
{
    /** Mindest-Kontrast Papier/Tinte (0-255) fuer ein hochgeladenes Bild. */
    public const MIN_KONTRAST = 60;

    public function __construct(
        private readonly SignatureStorage $storage,
        private readonly NotificationService $notifications,
    ) {
    }

    public function aktive(User $user, string $kind = UserSignature::UNTERSCHRIFT): ?UserSignature
    {
        return UserSignature::query()
            ->where('user_id', $user->id)->where('kind', $kind)->where('active', true)
            ->latest('created_at')->first();
    }

    public function lesen(UserSignature $signature): ?string
    {
        return $this->storage->read($signature->path);
    }

    /**
     * Eine gezeichnete Unterschrift (PNG als data:-URL) hinterlegen.
     *
     * @throws \RuntimeException mit einer Meldung fuer den Mitarbeiter
     */
    public function speichereZeichnung(User $user, string $dataUrl, string $kind, ?string $geraet, string $methode = UserSignature::GEZEICHNET): UserSignature
    {
        $png = Unterschriftsbild::ausZeichnung($dataUrl);
        if ($png === null) {
            throw new \RuntimeException('Die Zeichnung ist leer oder nicht lesbar. Bitte erneut zeichnen.');
        }

        return $this->ablegen($user, Unterschriftsbild::zuschneiden($png), $kind, $methode, $geraet);
    }

    /**
     * Ein hochgeladenes Bild (PNG/JPG/HEIC) freistellen, zuschneiden,
     * pruefen und hinterlegen.
     *
     * @throws \RuntimeException mit einer Meldung fuer den Mitarbeiter
     */
    public function speichereUpload(User $user, UploadedFile $datei, string $kind, ?string $geraet): UserSignature
    {
        return $this->ablegen($user, $this->aufbereiten((string) file_get_contents($datei->getRealPath())), $kind, UserSignature::HOCHGELADEN, $geraet);
    }

    /**
     * Macht aus einem Foto/Scan ein freigestelltes, zugeschnittenes PNG.
     * Oeffentlich, damit die Vorschau VOR dem Speichern dasselbe zeigt.
     *
     * @throws \RuntimeException
     */
    public function aufbereiten(string $binary): string
    {
        if ($this->istHeic($binary)) {
            $binary = $this->heicNachJpeg($binary);
        }
        $info = @getimagesizefromstring($binary);
        if ($info === false || ! in_array($info['mime'], ['image/png', 'image/jpeg', 'image/webp'], true)) {
            throw new \RuntimeException('Die Datei ist kein Bild (erlaubt: PNG, JPG, HEIC).');
        }
        $bild = @imagecreatefromstring($binary);
        if ($bild === false) {
            throw new \RuntimeException('Das Bild konnte nicht gelesen werden.');
        }
        $bild = Unterschriftsbild::verkleinereZeichnung($bild);
        $ergebnis = Bildfreistellung::tinteFreistellen($bild);
        $bild = $ergebnis['bild'];
        if ($ergebnis['kontrast'] < self::MIN_KONTRAST || Bildfreistellung::sichtbarerAnteil($bild) < 0.003) {
            imagedestroy($bild);
            throw new \RuntimeException('Das Bild ist zu hell oder zu kontrastarm - die Unterschrift wäre im Dokument kaum zu sehen. '
                .'Bitte mit dunklem Stift auf weißem Papier und bei gutem Licht erneut fotografieren.');
        }
        imagealphablending($bild, false);
        imagesavealpha($bild, true);
        ob_start();
        imagepng($bild, null, 8);
        $png = (string) ob_get_clean();
        imagedestroy($bild);

        return Unterschriftsbild::zuschneiden($png);
    }

    /** Ein PNG hinterlegen, die bisherige Fassung archivieren. */
    public function ablegen(User $user, string $png, string $kind, string $methode, ?string $geraet): UserSignature
    {
        if (! $user->darfFuerFirmaUnterschreiben()) {
            throw new \RuntimeException('Ihnen fehlt das Recht „Darf für das Unternehmen unterschreiben".');
        }
        $kind = array_key_exists($kind, UserSignature::KINDS) ? $kind : UserSignature::UNTERSCHRIFT;
        $pfad = 'mitarbeiter-unterschriften/'.$user->id.'/'.Str::uuid().'.png';
        if ($this->storage->disk()->put($pfad, $png) === false) {
            throw new \RuntimeException('Die Unterschrift konnte nicht gespeichert werden.');
        }

        $neu = DB::transaction(function () use ($user, $kind, $methode, $geraet, $pfad, $png) {
            UserSignature::query()->where('user_id', $user->id)->where('kind', $kind)->where('active', true)
                ->update(['active' => false, 'archived_at' => now()]);

            return UserSignature::create([
                'user_id' => $user->id,
                'kind' => $kind,
                'path' => $pfad,
                'hash' => hash('sha256', $png),
                'method' => $methode,
                'device' => $geraet === null ? null : mb_substr($geraet, 0, 160),
                'active' => true,
            ]);
        });

        ActivityLog::record('user_signature_changed', 'user', (string) $user->id, [
            'art' => $kind, 'weg' => $methode, 'sha256' => $neu->hash,
        ], $user->id);
        $this->meldeAdmins($user, $neu);

        return $neu;
    }

    private function meldeAdmins(User $user, UserSignature $signatur): void
    {
        try {
            $this->notifications->pushMany(
                User::query()->where('role', 'admin')->where('is_active', true)->pluck('id'),
                [
                    'type' => 'signature',
                    'title' => 'Hinterlegte '.UserSignature::KINDS[$signatur->kind].' geändert',
                    'body' => $user->name.' hat eine neue '.UserSignature::KINDS[$signatur->kind].' hinterlegt ('
                        .$signatur->methodLabel().', SHA-256 '.substr($signatur->hash, 0, 12).'…).',
                    'link' => route('admin.meine_unterschrift'),
                    'dedup_key' => 'user-signature:'.$signatur->id,
                ],
            );
        } catch (\Throwable) {
            // Die Meldung darf das Speichern nie scheitern lassen.
        }
    }

    private function istHeic(string $binary): bool
    {
        return (bool) preg_match('/^.{4}ftyp(heic|heix|hevc|hevx|mif1|msf1)/s', substr($binary, 0, 16));
    }

    /**
     * HEIC (iPhone) kann GD nicht lesen. Gelesen wird ueber ein
     * Systemprogramm (`heif-convert` aus libheif oder ImageMagick), falls
     * vorhanden - sonst eine klare Meldung statt eines kaputten Bildes.
     */
    private function heicNachJpeg(string $binary): string
    {
        $dir = sys_get_temp_dir().'/heic-'.bin2hex(random_bytes(6));
        @mkdir($dir, 0700);
        try {
            file_put_contents($dir.'/ein.heic', $binary);
            foreach ([['heif-convert', $dir.'/ein.heic', $dir.'/aus.jpg'], ['convert', $dir.'/ein.heic', $dir.'/aus.jpg']] as $befehl) {
                try {
                    $p = new Process($befehl);
                    $p->setTimeout(30);
                    $p->run();
                } catch (\Throwable) {
                    continue;
                }
                if ($p->isSuccessful() && is_file($dir.'/aus.jpg')) {
                    return (string) file_get_contents($dir.'/aus.jpg');
                }
            }
        } finally {
            foreach (glob($dir.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
        throw new \RuntimeException('HEIC-Bilder können auf diesem Server nicht gelesen werden. '
            .'Bitte als JPG oder PNG hochladen (iPhone: Einstellungen → Kamera → Formate → „Maximale Kompatibilität").');
    }
}
