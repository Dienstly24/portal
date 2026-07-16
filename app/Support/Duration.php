<?php
namespace App\Support;

/** Sekunden menschenlesbar formatieren (fuer Berichte/Exporte). */
class Duration
{
    /** z. B. 9330 -> "2 Std. 35 Min."; unter 1 Minute -> "0 Min." */
    public static function human(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        if ($hours > 0) {
            return $hours . ' Std. ' . $minutes . ' Min.';
        }
        return $minutes . ' Min.';
    }

    /** z. B. 9330 -> "02:35" (fuer CSV-Export). */
    public static function hhmm(int $seconds): string
    {
        $seconds = max(0, $seconds);
        return sprintf('%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }
}
