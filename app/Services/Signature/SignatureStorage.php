<?php

namespace App\Services\Signature;

use App\Models\SignatureRequest;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Wo die Dateien des Moduls liegen - an EINER Stelle.
 *
 * IMMER auf der PRIVATEN Platte (`local`, storage/app/private). Ein zur
 * Unterschrift versandter Vertrag ist regelmaessig das persoenlichste
 * Dokument, das der Betrieb je in der Hand hat; er darf unter keinen
 * Umstaenden ueber eine ratbare URL im oeffentlichen Verzeichnis liegen.
 * Jeder Zugriff laeuft ueber einen Controller, der vorher prueft, WER
 * fragt - beim Mitarbeiter die Rolle, beim Unterzeichner sein Token.
 */
class SignatureStorage
{
    public const DISK = 'local';

    public function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }

    public function directory(SignatureRequest $request): string
    {
        return 'signaturen/'.$request->id;
    }

    public function originalPath(SignatureRequest $request): string
    {
        return $this->directory($request).'/original.pdf';
    }

    public function signedPath(SignatureRequest $request): string
    {
        return $this->directory($request).'/unterschrieben.pdf';
    }

    public function fieldImagePath(SignatureRequest $request, string $fieldId): string
    {
        return $this->directory($request).'/felder/'.$fieldId.'.png';
    }

    public function pagePreviewPath(SignatureRequest $request, int $page): string
    {
        return $this->directory($request).'/seiten/'.$page.'.png';
    }

    /** Inhalt einer Datei; null, wenn sie fehlt (geloescht, nie erzeugt). */
    public function read(?string $path): ?string
    {
        if ($path === null || ! $this->disk()->exists($path)) {
            return null;
        }
        $content = $this->disk()->get($path);

        return $content === false ? null : $content;
    }

    /** Alles zu einer Anfrage entfernen - nur beim Loeschen des Vorgangs. */
    public function purge(SignatureRequest $request): void
    {
        $this->disk()->deleteDirectory($this->directory($request));
    }
}
