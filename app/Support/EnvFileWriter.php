<?php

namespace App\Support;

/**
 * Schreibt Schluessel=Wert-Paare sicher in die .env-Datei: vorhandene
 * Zeilen werden ersetzt, fehlende angehaengt, alle anderen Zeilen bleiben
 * unangetastet. Genutzt vom Meta-Einrichtungs-Assistenten
 * (php artisan meta:einrichten) - Secrets landen so NUR in der Server-.env,
 * nie im Chat oder Repo.
 */
class EnvFileWriter
{
    public function __construct(private ?string $path = null)
    {
        $this->path = $path ?: base_path('.env');
    }

    /** @param array<string,string> $values */
    public function set(array $values): void
    {
        $content = is_file($this->path) ? (string) file_get_contents($this->path) : '';

        foreach ($values as $key => $value) {
            $line = $key.'='.$value;
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
            if (preg_match($pattern, $content)) {
                // Callback: der Ersatz wird woertlich uebernommen (Token
                // koennte sonst als $1/\\-Referenz fehlinterpretiert werden).
                $content = (string) preg_replace_callback($pattern, fn () => $line, $content);
            } else {
                $content = rtrim($content, "\n")."\n".$line."\n";
            }
        }

        file_put_contents($this->path, $content);
    }
}
