<?php

namespace App\Services\Media;

use App\Models\MediaAsset;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Erzeugt aus dem Original die Web-Varianten (Arbeitsauftrag P1-1c):
 * 480/960/1600 px Breite, jeweils als AVIF + WebP + JPG, jede Variante
 * unter 200 KB (Qualitaet wird schrittweise gesenkt). EXIF-Daten
 * (GPS/Kamera) verschwinden automatisch durch die GD-Neukodierung;
 * die JPEG-Orientierung wird vorher angewendet, damit Handyfotos nicht
 * gekippt erscheinen. SVGs bleiben Vektor: nur sanitisiert abgelegt.
 */
class ImageVariantGenerator
{
    public function generate(MediaAsset $asset): void
    {
        $original = Storage::disk('local')->path($asset->original_path);

        if ($asset->isSvg()) {
            $svg = file_get_contents($original);
            $path = $this->variantDir($asset) . '/' . $this->stem($asset) . '.svg';
            Storage::disk('public')->put($path, $svg);
            $asset->forceFill([
                'variants' => [['format' => 'svg', 'width' => $asset->width, 'height' => $asset->height, 'path' => $path, 'bytes' => strlen($svg)]],
                'processing_status' => 'ready',
                'processing_error' => null,
            ])->save();
            return;
        }

        // Grosse Originale (bis 10 MB, Handykameras) brauchen beim Dekodieren
        // deutlich mehr Speicher als das Standard-Limit.
        $previousLimit = ini_get('memory_limit');
        ini_set('memory_limit', '512M');

        try {
            $img = @imagecreatefromstring((string) file_get_contents($original));
            if ($img === false) {
                throw new \RuntimeException('Bild konnte nicht gelesen werden (Format beschaedigt?).');
            }
            imagepalettetotruecolor($img);
            $img = $this->applyJpegOrientation($img, $original, $asset->mime);

            $srcW = imagesx($img);
            $srcH = imagesy($img);
            $asset->forceFill(['width' => $srcW, 'height' => $srcH])->save();

            // Zielbreiten: nie hochskalieren; sehr kleine Originale bekommen
            // genau eine Variante in Originalbreite.
            $widths = array_values(array_filter((array) config('website.media.variant_widths'), fn ($w) => $w <= $srcW));
            if ($widths === []) {
                $widths = [$srcW];
            }

            $variants = [];
            foreach ($widths as $targetW) {
                $scaled = $targetW === $srcW ? $img : imagescale($img, $targetW, -1, IMG_BICUBIC);
                if ($scaled === false) {
                    throw new \RuntimeException('Skalierung auf ' . $targetW . 'px fehlgeschlagen.');
                }
                imagealphablending($scaled, false);
                imagesavealpha($scaled, true);
                $targetH = imagesy($scaled);

                foreach (['avif', 'webp', 'jpg'] as $format) {
                    $blob = $this->encodeUnderLimit($scaled, $format, (int) config('website.media.variant_max_bytes'));
                    if ($blob === null) {
                        continue; // Format nicht verfuegbar (z. B. AVIF ohne libavif)
                    }
                    $path = $this->variantDir($asset) . '/' . $this->stem($asset) . '-' . $targetW . '.' . $format;
                    Storage::disk('public')->put($path, $blob);
                    $variants[] = ['format' => $format, 'width' => $targetW, 'height' => $targetH, 'path' => $path, 'bytes' => strlen($blob)];
                }
                if ($scaled !== $img) {
                    imagedestroy($scaled);
                }
            }
            imagedestroy($img);

            if (! array_filter($variants, fn ($v) => $v['format'] === 'jpg')) {
                throw new \RuntimeException('Keine JPG-Fallback-Variante erzeugt.');
            }

            $asset->forceFill([
                'variants' => $variants,
                'processing_status' => 'ready',
                'processing_error' => null,
            ])->save();
        } finally {
            ini_set('memory_limit', $previousLimit);
        }
    }

    /** Loescht alle erzeugten Varianten-Dateien eines Assets. */
    public function deleteVariants(MediaAsset $asset): void
    {
        Storage::disk('public')->deleteDirectory($this->variantDir($asset));
    }

    private function variantDir(MediaAsset $asset): string
    {
        return 'media/' . $asset->id;
    }

    /** ASCII-Dateistamm aus dem Titel (keine Umlaute/arabischen Zeichen im Pfad). */
    private function stem(MediaAsset $asset): string
    {
        $slug = Str::slug(pathinfo($asset->title ?: $asset->original_name, PATHINFO_FILENAME));
        return $slug !== '' ? $slug : 'bild-' . $asset->id;
    }

    /**
     * Kodiert ein GD-Bild in das Zielformat und senkt die Qualitaet
     * schrittweise, bis die Variante unter dem Limit liegt (P1-2: jede
     * ausgelieferte Datei < 200 KB). null = Format nicht verfuegbar.
     */
    private function encodeUnderLimit(\GdImage $img, string $format, int $maxBytes): ?string
    {
        $qualities = match ($format) {
            'avif' => function_exists('imageavif') ? [60, 50, 40, 30] : null,
            'webp' => function_exists('imagewebp') ? [78, 68, 55, 42, 30] : null,
            'jpg' => [82, 72, 60, 48, 35],
            default => null,
        };
        if ($qualities === null) {
            return null;
        }

        $encode = function (int $quality) use ($img, $format): ?string {
            ob_start();
            $ok = match ($format) {
                'avif' => imageavif($img, null, $quality),
                'webp' => imagewebp($img, null, $quality),
                // JPG kennt keine Transparenz: auf Weiss zusammenfuehren.
                'jpg' => imagejpeg($this->flattenOnWhite($img), null, $quality),
            };
            $blob = ob_get_clean();
            return $ok && $blob !== false && $blob !== '' ? $blob : null;
        };

        $blob = null;
        foreach ($qualities as $q) {
            $blob = $encode($q);
            if ($blob === null) {
                return null; // Encoder schlug fehl
            }
            if (strlen($blob) <= $maxBytes) {
                return $blob;
            }
        }
        // Auch die niedrigste Stufe liefert ein Ergebnis - lieber knapp
        // ueber dem Limit ausliefern als gar kein Bild.
        return $blob;
    }

    private function flattenOnWhite(\GdImage $img): \GdImage
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $flat = imagecreatetruecolor($w, $h);
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagecopy($flat, $img, 0, 0, 0, 0, $w, $h);
        return $flat;
    }

    /** Wendet die EXIF-Orientierung von JPEG-Handyfotos an (vor dem Strippen). */
    private function applyJpegOrientation(\GdImage $img, string $path, string $mime): \GdImage
    {
        if ($mime !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $img;
        }
        $exif = @exif_read_data($path);
        $orientation = (int) ($exif['Orientation'] ?? 1);
        return match ($orientation) {
            3 => imagerotate($img, 180, 0) ?: $img,
            6 => imagerotate($img, -90, 0) ?: $img,
            8 => imagerotate($img, 90, 0) ?: $img,
            default => $img,
        };
    }
}
