<?php
namespace App\Services\Social;

use App\Models\Banner;
use Illuminate\Support\Facades\Storage;

/**
 * Erzeugt aus dem Banner-Medium die passenden Bildformate fuer die
 * Social-Media-Plattformen (Facebook/Instagram/TikTok):
 *   quadrat 1080x1080 (Feed-Post), story 1080x1920 (Story/Reel),
 *   quer 1200x630 (Link-Vorschau).
 *
 * Liegt das Seitenverhaeltnis der Quelle nahe am Zielformat (±20 %), wird
 * mittig zugeschnitten (cover). Sonst wird das Motiv VOLLSTAENDIG auf dem
 * dunklen Markenhintergrund eingepasst (contain, Gruen-Graphit #131A17 aus
 * dem Farbschema "Smaragd & Gold") - so wird ein breiter Text-Banner nie
 * zerschnitten. Ausgabe als JPG (wird von allen Plattformen akzeptiert).
 *
 * Videos werden nicht umgerechnet (kein ffmpeg auf dem VPS) - die
 * Oberflaeche bietet dafuer die Originaldatei an. GIF nutzt das 1. Bild.
 */
class SocialFormatGenerator
{
    /** format => [Breite, Hoehe, Label fuer die Oberflaeche] */
    public const FORMATS = [
        'quadrat' => [1080, 1080, 'Feed-Post (1:1)'],
        'story'   => [1080, 1920, 'Story / Reel (9:16)'],
        'quer'    => [1200, 630, 'Link-Vorschau (1200x630)'],
    ];

    /** Dunkler Markenhintergrund fuer eingepasste Motive (Gruen-Graphit). */
    private const BG = [0x13, 0x1A, 0x17];

    /** Erzeugt alle Formate; Rueckgabe format => relativer Pfad (public disk). */
    public function generate(Banner $banner): array
    {
        if ($banner->media_type !== 'image') {
            return [];
        }
        $src = $this->loadSource($banner->media_path);
        if (!$src) {
            return [];
        }

        $out = [];
        foreach (self::FORMATS as $key => [$w, $h]) {
            $img = $this->render($src, $w, $h);
            ob_start();
            imagejpeg($img, null, 88);
            $jpg = ob_get_clean();
            imagedestroy($img);
            if ($jpg === false || $jpg === '') {
                continue;
            }
            $path = self::path($banner, $key);
            Storage::disk('public')->put($path, $jpg);
            $out[$key] = $path;
        }
        imagedestroy($src);

        return $out;
    }

    /** Loescht alle erzeugten Formate eines Banners (bei Banner-Loeschung). */
    public function delete(Banner $banner): void
    {
        try {
            Storage::disk('public')->deleteDirectory('banners/social/' . $banner->id);
        } catch (\Throwable $e) {
        }
    }

    /** Fester, vorhersagbarer Ablageort je Format. */
    public static function path(Banner $banner, string $format): string
    {
        return 'banners/social/' . $banner->id . '/' . $format . '.jpg';
    }

    /** Bereits vorhandene Formate (format => Pfad) ohne Neuberechnung. */
    public static function existing(Banner $banner): array
    {
        $out = [];
        foreach (self::FORMATS as $key => $spec) {
            $path = self::path($banner, $key);
            if (Storage::disk('public')->exists($path)) {
                $out[$key] = $path;
            }
        }

        return $out;
    }

    /** Quelle laden (WebP/JPG/PNG/GIF); null wenn nicht lesbar. */
    private function loadSource(string $relPath)
    {
        $disk = Storage::disk('public');
        if (!$disk->exists($relPath)) {
            return null;
        }
        $file = $disk->path($relPath);
        $ext = strtolower(pathinfo($relPath, PATHINFO_EXTENSION));
        try {
            $img = match (true) {
                $ext === 'webp' && function_exists('imagecreatefromwebp') => @imagecreatefromwebp($file),
                in_array($ext, ['jpg', 'jpeg']) => @imagecreatefromjpeg($file),
                $ext === 'png' => @imagecreatefrompng($file),
                $ext === 'gif' => @imagecreatefromgif($file), // 1. Frame
                default => false,
            };

            return $img ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Ein Zielformat rendern: Seitenverhaeltnis nahe am Ziel -> mittiger
     * Zuschnitt (cover); sonst Motiv komplett zeigen (contain) mit 7 % Rand
     * auf dem Markenhintergrund.
     */
    private function render($src, int $tw, int $th)
    {
        $sw = imagesx($src);
        $sh = imagesy($src);

        $canvas = imagecreatetruecolor($tw, $th);
        $bg = imagecolorallocate($canvas, self::BG[0], self::BG[1], self::BG[2]);
        imagefill($canvas, 0, 0, $bg);

        $srcRatio = $sw / max(1, $sh);
        $dstRatio = $tw / $th;

        if (abs($srcRatio - $dstRatio) / $dstRatio <= 0.2) {
            // Cover: passenden Ausschnitt mittig aus der Quelle schneiden.
            if ($srcRatio > $dstRatio) {
                $ch = $sh;
                $cw = (int) round($sh * $dstRatio);
            } else {
                $cw = $sw;
                $ch = (int) round($sw / $dstRatio);
            }
            $sx = (int) max(0, round(($sw - $cw) / 2));
            $sy = (int) max(0, round(($sh - $ch) / 2));
            imagecopyresampled($canvas, $src, 0, 0, $sx, $sy, $tw, $th, $cw, $ch);
        } else {
            // Contain: komplett einpassen, maessiges Hochskalieren erlaubt.
            $maxW = (int) round($tw * 0.86);
            $maxH = (int) round($th * 0.86);
            $scale = min($maxW / $sw, $maxH / $sh, 2.0);
            $nw = max(1, (int) round($sw * $scale));
            $nh = max(1, (int) round($sh * $scale));
            $dx = (int) round(($tw - $nw) / 2);
            $dy = (int) round(($th - $nh) / 2);
            imagecopyresampled($canvas, $src, $dx, $dy, 0, 0, $nw, $nh, $sw, $sh);
        }

        return $canvas;
    }
}
