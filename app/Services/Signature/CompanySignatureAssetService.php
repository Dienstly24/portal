<?php

namespace App\Services\Signature;

use App\Models\CompanySignatureAsset;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Anlegen, ersetzen und entfernen der Firmenbilder.
 *
 * DREI REGELN:
 *  1. Das Bild wird NEU GERENDERT, nie durchgereicht. Eine hochgeladene
 *     Datei ist Fremdmaterial, und dieses hier landet in Dokumenten, die
 *     der Betrieb aus der Hand gibt. Nach dem Rendern ist es garantiert ein
 *     PNG und nichts anderes.
 *  2. Die Transparenz bleibt erhalten. Ein weiss hinterlegter Stempel ist
 *     ein weisser Kasten ueber dem Vertragstext - dieselbe Lehre wie bei
 *     den Marken-Slots der Medienverwaltung.
 *  3. Nichts wird geloescht, was in einem fertigen Dokument steckt: ein
 *     benutztes Bild wird auf inaktiv gesetzt. Die Datei bleibt, weil sie
 *     Teil eines unterschriebenen PDF ist.
 */
class CompanySignatureAssetService
{
    /** Genug fuer den Druck, wenig fuers Netz - dieselbe Grenze wie bei der Handschrift. */
    private const MAX_PX = 1600;

    private const DISK = SignatureStorage::DISK;

    private const DIR = 'signaturen/firma';

    public function store(UploadedFile $file, array $data, User $user): CompanySignatureAsset
    {
        $type = in_array($data['type'] ?? '', CompanySignatureAsset::typeKeys(), true)
            ? $data['type']
            : CompanySignatureAsset::UNTERSCHRIFT;

        $png = $this->render($file);
        $info = getimagesizefromstring($png);
        $id = (string) Str::uuid();
        $path = self::DIR.'/'.$id.'.png';

        if (Storage::disk(self::DISK)->put($path, $png) === false) {
            throw new \RuntimeException('Das Bild konnte nicht gespeichert werden.');
        }

        $asset = CompanySignatureAsset::create([
            'id' => $id,
            'name' => mb_substr(trim((string) ($data['name'] ?? '')) ?: CompanySignatureAsset::TYPES[$type], 0, 120),
            'type' => $type,
            'path' => $path,
            'width' => (int) ($info[0] ?? 0),
            'height' => (int) ($info[1] ?? 0),
            'bytes' => strlen($png),
            'hash' => hash('sha256', $png),
            'is_default' => (bool) ($data['is_default'] ?? false),
            'active' => true,
            'created_by' => $user->id,
        ]);

        if ($asset->is_default) {
            $this->makeDefault($asset);
        }

        return $asset;
    }

    /** Je Art genau EIN Standard - sonst waere "der Stempel" nicht bestimmt. */
    public function makeDefault(CompanySignatureAsset $asset): void
    {
        CompanySignatureAsset::where('type', $asset->type)
            ->whereKeyNot($asset->id)
            ->update(['is_default' => false]);
        $asset->forceFill(['is_default' => true, 'active' => true])->save();
    }

    /**
     * Entfernen - aber nur, wenn das Bild in keinem Dokument steckt.
     * Andernfalls wird es stillgelegt: die Datei ist Teil eines fertigen
     * PDF, und ein Beleg, dem nachtraeglich das Bild fehlt, ist kein Beleg.
     */
    public function remove(CompanySignatureAsset $asset): string
    {
        if ($asset->fields()->exists()) {
            $asset->forceFill(['active' => false, 'is_default' => false])->save();

            return 'stillgelegt';
        }

        Storage::disk(self::DISK)->delete($asset->path);
        $asset->delete();

        return 'geloescht';
    }

    public function read(CompanySignatureAsset $asset): ?string
    {
        return Storage::disk(self::DISK)->exists($asset->path)
            ? Storage::disk(self::DISK)->get($asset->path)
            : null;
    }

    /**
     * Liest die hochgeladene Datei mit GD und schreibt sie als PNG neu.
     * Was GD nicht oeffnen kann, ist kein Bild - egal, was die Endung sagt.
     */
    private function render(UploadedFile $file): string
    {
        $binary = (string) file_get_contents($file->getRealPath());
        $info = @getimagesizefromstring($binary);
        if ($info === false || ! in_array($info['mime'], ['image/png', 'image/jpeg', 'image/webp'], true)) {
            throw new \RuntimeException('Die Datei ist kein PNG, JPG oder WebP.');
        }

        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            throw new \RuntimeException('Das Bild konnte nicht gelesen werden.');
        }

        $w = imagesx($image);
        $h = imagesy($image);
        $max = max($w, $h);
        if ($max > self::MAX_PX) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            $faktor = self::MAX_PX / $max;
            $klein = @imagescale($image, max(8, (int) round($w * $faktor)), max(8, (int) round($h * $faktor)));
            if ($klein !== false) {
                imagedestroy($image);
                $image = $klein;
            }
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);
        ob_start();
        imagepng($image, null, 8);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        if ($png === '') {
            throw new \RuntimeException('Das Bild konnte nicht umgewandelt werden.');
        }

        return $png;
    }
}
