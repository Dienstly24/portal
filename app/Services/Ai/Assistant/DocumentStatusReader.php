<?php
namespace App\Services\Ai\Assistant;

use App\Models\Customer;
use App\Models\DocumentRequest;

/**
 * EINE Quelle fuer die Dokumentenlage eines Kunden (Spezifikation
 * Abschnitt 10): benoetigt / vorhanden / fehlt / in Pruefung / abgelehnt.
 *
 * Gelesen wird die bestehende Tabelle `document_requests` - dieselbe, die
 * die Mitarbeiter pflegen und die dem Kunden im Portal den Upload-Bereich
 * zeigt. Damit sagt der Assistent nie etwas anderes, als der Kunde auf
 * seiner Dokumentenseite sieht.
 *
 * Zuordnung der Stati (DocumentRequest::STATUSES):
 *   open      -> fehlt (Kunde muss hochladen)
 *   rejected  -> fehlt erneut (Nachweis war nicht verwendbar)
 *   uploaded  -> in Pruefung (eingegangen, Team prueft)
 *   approved  -> vorhanden/abgeschlossen
 */
class DocumentStatusReader
{
    /** @return array<string,mixed> */
    public function overview(Customer $customer): array
    {
        $requests = DocumentRequest::where('customer_id', $customer->id)
            ->with('contract')
            ->orderBy('deadline')
            ->orderBy('created_at')
            ->get();

        $map = fn ($collection) => $collection->map(fn (DocumentRequest $r) => array_filter([
            'titel' => $r->title,
            'hinweis' => $r->description,
            'status' => $r->statusLabel(),
            'frist' => $r->deadline?->format('d.m.Y'),
            'vertrag' => $r->contract
                ? trim($r->contract->typeLabel() . ' ' . ($r->contract->insurer ?? ''))
                : null,
            'grund_ablehnung' => $r->status === 'rejected' ? $r->rejection_note : null,
            'eingegangen' => $r->uploaded_at?->format('d.m.Y'),
        ], fn ($v) => $v !== null && $v !== ''))->values()->all();

        $missing = $requests->whereIn('status', ['open', 'rejected']);
        $inReview = $requests->where('status', 'uploaded');
        $done = $requests->where('status', 'approved');

        return [
            'benoetigt_gesamt' => $requests->count(),
            'fehlt' => $map($missing),
            'in_pruefung' => $map($inReview),
            'vorhanden' => $map($done),
            'alles_vollstaendig' => $missing->isEmpty() && $requests->isNotEmpty(),
            // Ehrliche Aussage, wenn es ueberhaupt keine Anforderung gibt:
            // "nichts angefordert" ist NICHT dasselbe wie "alles da".
            'keine_anforderungen' => $requests->isEmpty(),
        ];
    }

    /**
     * Ist ein Dokument mit diesem Titel bereits angefordert? Verhindert
     * doppelte Anforderungen desselben Nachweises (Abschnitt 24 sinngemaess
     * fuer Dokumente).
     */
    public function findOpenRequestByTitle(Customer $customer, string $title): ?DocumentRequest
    {
        $needle = $this->normalize($title);
        if ($needle === '') {
            return null;
        }

        return DocumentRequest::where('customer_id', $customer->id)
            ->whereIn('status', ['open', 'rejected', 'uploaded'])
            ->get()
            ->first(function (DocumentRequest $r) use ($needle) {
                $existing = $this->normalize((string) $r->title);

                return $existing !== '' && (
                    $existing === $needle
                    || str_contains($existing, $needle)
                    || str_contains($needle, $existing)
                );
            });
    }

    /** Kleinschreibung ohne Umlaute/Sonderzeichen fuer den Titelvergleich. */
    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], $value);

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '');
    }
}
