<?php

namespace App\Services\Messaging;

use App\Models\CustomerMessageAttachment;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Ein Chat-Anhang wird zur Unterlage in der Kundenakte.
 *
 * WARUM NICHT AUTOMATISCH: nicht jedes Bild in einer Unterhaltung ist
 * ein Nachweis. Ein Screenshot, ein Daumen-hoch-Sticker, ein Foto der
 * Katze - alles davon wuerde als "Unterlage" in der Akte stehen und die
 * Dokumentenanalyse (kostenpflichtig ab der KI-Stufe) ausloesen. Die
 * Uebernahme ist deshalb eine bewusste Mitarbeiter-Entscheidung, wie im
 * Dokumenten-Eingang auch.
 *
 * WARUM UEBERHAUPT: ohne diesen Weg war ein per Chat geschickter
 * Versicherungsschein in der Unterhaltung sichtbar und in der Akte
 * unauffindbar. Er lief nie durch Kategorie, Duplikatspruefung oder
 * Analyse - und beim naechsten Vorgang suchte ihn jemand vergeblich.
 *
 * KEINE ZWEITE DATEI: die Datei liegt bereits auf der privaten Platte.
 * Uebernommen wird sie durch KOPIEREN in den Aktenpfad - der Chatverlauf
 * darf seine Datei nie verlieren, auch wenn die Unterlage spaeter
 * geloescht wird. Ist derselbe Inhalt beim SELBEN Kunden schon als
 * Unterlage vorhanden (SHA-256), wird gar nichts kopiert und gar nichts
 * angelegt: der Anhang zeigt dann auf die vorhandene Unterlage.
 */
class AttachmentFilingService
{
    /**
     * Uebernimmt den Anhang in die Akte seines Kunden.
     *
     * Idempotent: ein bereits uebernommener Anhang liefert seine
     * vorhandene Unterlage zurueck, ohne etwas zu schreiben.
     *
     * @throws \RuntimeException wenn die Uebernahme nicht moeglich ist
     */
    public function uebernehmen(CustomerMessageAttachment $anhang, ?User $benutzer = null): Document
    {
        if ($anhang->document_id && $anhang->document) {
            return $anhang->document;
        }

        // Die Nachricht traegt den Kunden meistens selbst; bei einer
        // Nachricht von unbekannter Nummer steht er (nach der Zuordnung)
        // nur an der Unterhaltung.
        $nachricht = $anhang->message;
        $kunde = $nachricht?->customer_id ?: $nachricht?->conversation?->customer_id;
        if (! $kunde) {
            // Genau der Fall, den das Postfach sichtbar macht: eine
            // Nachricht von einer unbekannten Nummer. Erst zuordnen,
            // dann uebernehmen - eine Unterlage ohne Akte gibt es nicht.
            throw new \RuntimeException('Diese Unterhaltung ist noch keinem Kunden zugeordnet.');
        }

        $platte = $anhang->disk ?: 'local';
        if (! $anhang->file_path || ! Storage::disk($platte)->exists($anhang->file_path)) {
            // Bei eingehenden Medien entsteht der Datensatz VOR der
            // Datei (der Webhook muss zuegig quittieren). Wer zu frueh
            // klickt, bekommt eine Erklaerung statt einer leeren Akte.
            throw new \RuntimeException('Die Datei ist noch nicht abgerufen. Bitte in einem Moment erneut versuchen.');
        }

        $hash = Document::hashStoredFile($platte, $anhang->file_path);

        // Inhaltsgleiche Unterlage DESSELBEN Kunden? Dann ist die Arbeit
        // schon getan. Ohne diese Pruefung entstuende bei jedem erneut
        // geschickten Foto eine weitere Kopie auf der Platte - und die
        // Akte fuellte sich mit demselben Schreiben in fuenf Fassungen.
        if ($hash) {
            $vorhanden = Document::where('customer_id', $kunde)
                ->where('content_hash', $hash)
                ->orderBy('created_at')->orderBy('id')
                ->first();

            if ($vorhanden) {
                $anhang->forceFill(['document_id' => $vorhanden->id])->save();

                return $vorhanden;
            }
        }

        $ziel = "customers/$kunde/documents/".Str::random(8).'_'.$this->dateiname($anhang);
        Storage::disk('local')->put($ziel, Storage::disk($platte)->get($anhang->file_path));

        $dokument = Document::create([
            'id' => (string) Str::uuid(),
            'customer_id' => $kunde,
            'category' => 'other',
            'file_name' => $anhang->file_name ?: 'anhang',
            'file_path' => $ziel,
            'disk' => 'local',
            // Sichtbarkeit fuer den Kunden entscheidet immer ein Mensch -
            // dieselbe Regel wie bei den Mail-Anhaengen.
            'visibility' => 'internal',
            'uploaded_by' => $benutzer?->id,
            'file_size' => $anhang->file_size,
            'content_hash' => $hash,
        ]);

        $anhang->forceFill(['document_id' => $dokument->id])->save();

        return $dokument;
    }

    /** Dateiname ohne Pfadanteile und ohne Zeichen, die kein Pfad mag. */
    private function dateiname(CustomerMessageAttachment $anhang): string
    {
        $name = basename((string) ($anhang->file_name ?: 'anhang'));
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?: 'anhang';

        return mb_substr($name, 0, 120);
    }
}
