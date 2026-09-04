<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ScopesCustomerAccess;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerRelationship;
use App\Services\Matching\CustomerMatchingService;
use App\Services\Matching\CustomerMergeService;
use App\Services\Matching\DuplicateDetectionService;
use Illuminate\Http\Request;

/**
 * Dubletten, Beziehungen und Zusammenfuehren (ARCH-5, aus AdminController
 * herausgeloest).
 *
 * Zusammengehoerig, weil alle Methoden auf demselben Modell arbeiten: ein
 * Paar aus zwei Kundenakten, das entweder eine Dublette ist (zusammenfuehren)
 * oder eine echte Beziehung (markieren). Rein mechanisch verschoben -
 * Routen, Berechtigungen und Verhalten sind unveraendert.
 */
class DuplicateController extends Controller
{
    use ScopesCustomerAccess;

    /** Signal-Text -> Filterkategorie (Schnellfilter auf der Dubletten-Seite). */
    private const SIGNAL_CATEGORIES = [
        'Gleicher Name' => 'name',
        'Sehr aehnlicher Name' => 'name',
        'Gleiche Anschrift' => 'address',
        'Gleiche E-Mail-Adresse' => 'email',
        'Gleiche Telefonnummer' => 'phone',
        'Gleiche Bankverbindung (IBAN)' => 'iban',
        'Gleiche Vertragsnummer' => 'contract',
        'Gleiches Geburtsdatum' => 'birthdate',
    ];

    /** Deckel gegen versehentliche Massen-Merges pro manueller Aktion. */
    private const MANUAL_MERGE_CAP = 100;

    /** Deckel je Ein-Klick-Auto-Merge-Lauf (Rest per erneutem Klick). */
    private const AUTO_MERGE_CAP = 200;

    public function duplicates(DuplicateDetectionService $detection) {
        $result = $detection->scan($this->visibleCustomerIds());
        $autoMin = DuplicateDetectionService::AUTO_MERGE_MIN_SCORE;

        // Jedes Paar mit Filterkategorien versehen + Kategorie-Zaehler fuer die
        // Schnellfilter-Buttons (Namen / Adressen / E-Mails / Telefon / IBAN ...).
        $counts = ['name' => 0, 'address' => 0, 'email' => 0, 'phone' => 0, 'iban' => 0, 'contract' => 0, 'birthdate' => 0];
        $pairs = array_map(function ($p) use (&$counts) {
            $cats = [];
            foreach ($p['signals'] as $s) {
                if (isset(self::SIGNAL_CATEGORIES[$s])) {
                    $cats[self::SIGNAL_CATEGORIES[$s]] = true;
                }
            }
            $p['categories'] = array_keys($cats);
            foreach ($p['categories'] as $c) {
                $counts[$c]++;
            }
            return $p;
        }, $result['pairs']);

        // Wie im Merge-All-Pfad: Paare mit widersprechendem Identitaetsmerkmal
        // zaehlen NICHT als "sicher" (Audit MERGE-1), damit die Button-Zahl der
        // tatsaechlichen Aktion entspricht.
        $strongCount = count(array_filter(
            $pairs,
            fn ($p) => $p['score'] >= $autoMin && ! $detection->hasIdentityConflict($p['primary'], $p['duplicate'])
        ));

        return view('admin.customer_duplicates', [
            'pairs' => $pairs,
            'scanned' => $result['scanned'],
            'capped' => $result['capped'],
            'autoMin' => $autoMin,
            'strongCount' => $strongCount,
            'catCounts' => $counts,
            'relationCount' => CustomerRelationship::count(),
        ]);
    }

    /**
     * Markiert ein Paar als "kein Duplikat" -> es verschwindet aus der
     * Dubletten-Liste und erscheint stattdessen als Beziehung unter
     * "Verwandte Kunden". Reversibel (Beziehung entfernen).
     */
    public function dismissDuplicate(Request $request) {
        $data = $request->validate([
            'customer_a' => 'required|string',
            'customer_b' => 'required|string|different:customer_a',
            'note' => 'nullable|string|max:255',
            'type' => 'nullable|in:'.implode(',', CustomerRelationship::TYPES),
        ]);
        $this->authorizeCustomerAccess($data['customer_a']);
        $this->authorizeCustomerAccess($data['customer_b']);
        Customer::findOrFail($data['customer_a']);
        Customer::findOrFail($data['customer_b']);

        $type = $data['type'] ?? 'not_duplicate';
        [$a, $b] = CustomerRelationship::pairKey($data['customer_a'], $data['customer_b']);
        // updateOrCreate: ein bereits als "verwandt" markiertes Paar kann so
        // nachtraeglich praeziser als Ehepaar/Familie gekennzeichnet werden.
        CustomerRelationship::updateOrCreate(
            ['customer_a_id' => $a, 'customer_b_id' => $b],
            ['type' => $type, 'note' => $data['note'] ?? null, 'created_by' => auth()->id()]
        );
        app(DuplicateDetectionService::class)->forgetCount();

        $label = CustomerRelationship::typeLabel($type);
        $msg = $type === 'not_duplicate'
            ? 'Als „kein Duplikat" markiert – das Paar erscheint jetzt unter „Verwandte Kunden".'
            : 'Als „'.$label.'" verknüpft – beide Kunden bleiben mit allen Verträgen erhalten und erscheinen unter „Verwandte Kunden".';

        return back()->with('success', $msg);
    }

    /**
     * Sammel-Aktion: mehrere ausgewaehlte Paare auf einmal als "kein Duplikat"
     * markieren (schnelles Aufraeumen, z. B. alle Adress-Treffer eines
     * Haushalts). Reihenfolge-unabhaengig, dedupliziert.
     */
    public function dismissBulk(Request $request) {
        $data = $request->validate([
            'pairs' => 'required|array|min:1|max:500',
            'pairs.*' => 'string',
            'type' => 'nullable|in:'.implode(',', CustomerRelationship::TYPES),
        ]);
        $type = $data['type'] ?? 'not_duplicate';
        [$edges, $ids] = $this->pairsToEdges($data['pairs']);
        if ($ids === []) {
            return back()->with('error', 'Keine gültige Auswahl.');
        }
        foreach ($ids as $id) {
            $this->authorizeCustomerAccess($id);
        }
        $existing = Customer::whereIn('id', $ids)->pluck('id')->map(fn ($i) => (string) $i)->all();

        $marked = 0;
        foreach ($edges as [$a, $b]) {
            if (! in_array($a, $existing, true) || ! in_array($b, $existing, true)) {
                continue;
            }
            [$x, $y] = CustomerRelationship::pairKey($a, $b);
            $rel = CustomerRelationship::updateOrCreate(
                ['customer_a_id' => $x, 'customer_b_id' => $y],
                ['type' => $type, 'created_by' => auth()->id()]
            );
            if ($rel->wasRecentlyCreated) {
                $marked++;
            }
        }
        app(DuplicateDetectionService::class)->forgetCount();

        $label = CustomerRelationship::typeLabel($type);
        $msg = $type === 'not_duplicate'
            ? $marked.' Paar(e) als „kein Duplikat" markiert – jetzt unter „Verwandte Kunden".'
            : $marked.' Paar(e) als „'.$label.'" verknüpft – beide Kunden bleiben erhalten, jetzt unter „Verwandte Kunden".';

        return redirect()->route('admin.customers.duplicates')->with('success', $msg);
    }

    /**
     * "Verwandte Kunden": alle als Beziehung markierten Paare (kein Duplikat).
     * Nur Paare, deren BEIDE Kunden im Portfolio des Mitarbeiters liegen.
     */
    public function relationships(DuplicateDetectionService $detection) {
        $ids = $this->visibleCustomerIds();
        $query = CustomerRelationship::with(['customerA.user', 'customerB.user', 'customerA.contracts:id,customer_id,contract_number', 'customerB.contracts:id,customer_id,contract_number'])
            ->latest();
        if ($ids !== null) {
            $query->whereIn('customer_a_id', $ids)->whereIn('customer_b_id', $ids);
        }
        $relations = $query->limit(500)->get()
            ->filter(fn ($r) => $r->customerA && $r->customerB)
            ->map(function ($r) use ($detection) {
                $r->signals = $detection->pairSignals($r->customerA, $r->customerB);
                return $r;
            })->values();

        return view('admin.customer_relationships', ['relations' => $relations]);
    }

    /** Beziehung entfernen -> Paar kann wieder als moegliche Dublette erscheinen. */
    public function relationshipDelete($id) {
        $rel = CustomerRelationship::findOrFail($id);
        $this->authorizeCustomerAccess($rel->customer_a_id);
        $this->authorizeCustomerAccess($rel->customer_b_id);
        $rel->delete();
        app(DuplicateDetectionService::class)->forgetCount();

        return back()->with('success', 'Beziehung entfernt – das Paar kann wieder als mögliche Dublette erscheinen.');
    }

    /**
     * Art einer bestehenden Beziehung aendern (z. B. von "verwandt" zu
     * "Ehepaar"). Aendert NICHTS an den Kundenakten - nur die Kennzeichnung.
     */
    public function relationshipSetType(Request $request, $id) {
        $data = $request->validate([
            'type' => 'required|in:'.implode(',', CustomerRelationship::TYPES),
        ]);
        $rel = CustomerRelationship::findOrFail($id);
        $this->authorizeCustomerAccess($rel->customer_a_id);
        $this->authorizeCustomerAccess($rel->customer_b_id);
        $rel->update(['type' => $data['type']]);

        return back()->with('success', 'Beziehung als „'.CustomerRelationship::typeLabel($data['type']).'" gekennzeichnet.');
    }

    /**
     * Sammel-Zusammenfuehrung der VOM NUTZER AUSGEWAEHLTEN Dubletten-Paare.
     * Ueberlappende Paare (z. B. fuenf Datensaetze derselben Person) werden
     * ueber eine Union-Find-Gruppierung zu EINEM Cluster zusammengefasst und
     * in den jeweils aeltesten Datensatz vereint.
     */
    public function duplicatesMerge(Request $request, CustomerMergeService $merge) {
        $data = $request->validate([
            'pairs' => 'required|array|min:1|max:500',
            'pairs.*' => 'string',
        ]);

        [$edges, $ids] = $this->pairsToEdges($data['pairs']);
        if ($ids === []) {
            return back()->with('error', 'Keine gültige Auswahl.');
        }
        foreach ($ids as $id) {
            $this->authorizeCustomerAccess($id);
        }

        $clusters = $this->clusterPairs($edges, $ids);
        $toRemove = array_sum(array_map(fn ($c) => max(0, count($c) - 1), $clusters));
        if ($toRemove > self::MANUAL_MERGE_CAP) {
            return back()->with('error', 'Zu viele auf einmal: höchstens '.self::MANUAL_MERGE_CAP.' Zusammenführungen pro Aktion. Bitte Auswahl verkleinern oder „Alle sicheren zusammenführen" nutzen.');
        }

        $res = $this->mergeClusters($clusters, $merge);
        return redirect()->route('admin.customers.duplicates')->with('success', $this->mergeSummary($res, false));
    }

    /**
     * Ein-Klick-Zusammenfuehrung ALLER "sicheren" Treffer (Score >=
     * AUTO_MERGE_MIN_SCORE, Betreiber-Vorgabe 40 %). Schwaechere Treffer
     * (z. B. nur gleicher Name) bleiben bewusst der manuellen Pruefung
     * vorbehalten. Aus Zeitgruenden pro Lauf gedeckelt - der Hinweis fordert
     * bei Bedarf zum erneuten Klick auf, bis alles bereinigt ist.
     */
    public function duplicatesMergeAll(DuplicateDetectionService $detection, CustomerMergeService $merge) {
        $min = DuplicateDetectionService::AUTO_MERGE_MIN_SCORE;

        // Frischer Scan (nie auf veraltete Seiten-Daten verlassen).
        $result = $detection->scan($this->visibleCustomerIds());
        // Paare mit widersprechendem Identitaetsmerkmal (verschiedenes
        // Geburtsdatum/kein gemeinsames Namenswort) NIE unbeaufsichtigt
        // zusammenfuehren - gemeinsames Konto/Vertrag hebt den Score sonst auf
        // >= 85, obwohl es zwei Personen sein koennen (Audit MERGE-1). Diese
        // bleiben der manuellen Einzelpruefung vorbehalten.
        $strong = array_values(array_filter(
            $result['pairs'],
            fn ($p) => $p['score'] >= $min && ! $detection->hasIdentityConflict($p['primary'], $p['duplicate'])
        ));

        if ($strong === []) {
            return redirect()->route('admin.customers.duplicates')
                ->with('success', "Keine sicheren Treffer (>= {$min} %) zum automatischen Zusammenführen gefunden. "
                    .'Verdachtsfaelle mit abweichendem Geburtsdatum/Namen bitte einzeln pruefen.');
        }

        $edges = [];
        $ids = [];
        foreach ($strong as $p) {
            $a = (string) $p['primary']->id;
            $b = (string) $p['duplicate']->id;
            $edges[] = [$a, $b];
            $ids[$a] = true;
            $ids[$b] = true;
        }
        $ids = array_keys($ids);
        foreach ($ids as $id) {
            $this->authorizeCustomerAccess($id);
        }

        // Cluster bilden, dann pro Lauf deckeln (Rest beim naechsten Klick).
        $clusters = $this->clusterPairs($edges, $ids);
        $limited = [];
        $removals = 0;
        foreach ($clusters as $cluster) {
            $need = count($cluster) - 1;
            if ($removals + $need > self::AUTO_MERGE_CAP) {
                continue;
            }
            $limited[] = $cluster;
            $removals += $need;
        }

        $res = $this->mergeClusters($limited, $merge);
        $more = count($limited) < count($clusters);
        $message = "{$res['merged']} sichere Zusammenführung(en) (>= {$min} %) durchgeführt.";
        if ($more) {
            $message .= ' Es waren mehr vorhanden – bitte erneut klicken, um die restlichen zu bereinigen.';
        }
        if ($res['skipped'] > 0) {
            $message .= " {$res['skipped']} übersprungen.";
        }
        return redirect()->route('admin.customers.duplicates')->with('success', $message);
    }

    public function mergeForm($id, CustomerMergeService $merge, CustomerMatchingService $matcher) {
        $this->authorizeCustomerAccess($id);
        $customer = Customer::with(['user', 'addresses'])->findOrFail($id);
        // Die Auswahlliste laedt NICHT mehr den gesamten Kundenbestand in ein
        // <select>: das Formular sucht ueber admin.customers.search. Der
        // Vorschlag unten wird weiterhin serverseitig ermittelt - genau der
        // ist der eigentliche Zweck der Seite.

        // Vorauswahl bestimmen: entweder explizit aus der Dubletten-Pruefung
        // (?duplicate=) oder - falls nicht - automatisch der wahrscheinlichste
        // Treffer fuer genau diesen Kunden. So schlaegt das System das Duplikat
        // aktiv vor, statt nur eine leere Auswahlliste zu zeigen.
        $suggested = null;
        $preview = [];
        if ($dupId = request('duplicate')) {
            // Nur innerhalb des eigenen Portfolios und nie der Kunde selbst.
            $suggested = $this->scopeCustomers(Customer::with('user')->where('id', '!=', $id))
                ->where('customers.id', $dupId)->first();
        } else {
            $match = $matcher->matchExisting($customer);
            if ($match->hasMatch() && $match->score >= DuplicateDetectionService::DEFAULT_THRESHOLD) {
                $suggested = $this->scopeCustomers(Customer::with('user')->where('id', '!=', $id))
                    ->where('customers.id', (string) $match->customer->id)->first();
            }
        }
        if ($suggested) {
            $preview = $merge->preview($suggested);
        }

        return view('admin.customer_merge', compact('customer', 'suggested', 'preview'));
    }

    public function mergeCustomers(Request $request, $id, CustomerMergeService $merge) {
        $this->authorizeCustomerAccess($id);
        $request->validate(['duplicate_id' => 'required|different:id']);
        $this->authorizeCustomerAccess($request->duplicate_id);
        $primary = Customer::with('user')->findOrFail($id);
        $dup = Customer::with('user')->findOrFail($request->duplicate_id);
        if ((string) $primary->id === (string) $dup->id) return back()->with('success', 'Gleicher Kunde gewählt.');

        $moved = $merge->merge($primary, $dup, auth()->id());

        $summary = collect($moved)->sum();
        return redirect()->route('admin.customer', $primary->id)
            ->with('success', "Kunden erfolgreich zusammengeführt. {$summary} verknüpfte Datensätze wurden übertragen, nichts wurde gelöscht.");
    }

    /**
     * Paar-Strings ("primaryId|dupId") in Kantenliste + eindeutige ID-Liste.
     * @return array{0: array<int, array{0:string,1:string}>, 1: array<int, string>}
     */
    private function pairsToEdges(array $pairs): array {
        $edges = [];
        $ids = [];
        foreach ($pairs as $pair) {
            $parts = explode('|', (string) $pair, 2);
            if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '' || $parts[0] === $parts[1]) {
                continue;
            }
            $edges[] = $parts;
            $ids[$parts[0]] = true;
            $ids[$parts[1]] = true;
        }
        return [$edges, array_keys($ids)];
    }

    /**
     * Union-Find: verbundene Paare zu Clustern gruppieren (ueberlappende
     * Paare derselben Person werden zu einem Cluster).
     * @return array<int, array<int, string>>
     */
    private function clusterPairs(array $edges, array $ids): array {
        $parent = [];
        foreach ($ids as $id) {
            $parent[$id] = $id;
        }
        $find = function ($x) use (&$parent) {
            $root = $x;
            while ($parent[$root] !== $root) {
                $root = $parent[$root];
            }
            while ($parent[$x] !== $root) {
                [$parent[$x], $x] = [$root, $parent[$x]];
            }
            return $root;
        };
        foreach ($edges as [$a, $b]) {
            $ra = $find($a);
            $rb = $find($b);
            if ($ra !== $rb) {
                $parent[$ra] = $rb;
            }
        }
        $clusters = [];
        foreach ($ids as $id) {
            $clusters[$find($id)][] = $id;
        }
        return array_values($clusters);
    }

    /**
     * Jeden Cluster in den aeltesten Datensatz vereinen (verlustfrei ueber
     * CustomerMergeService). Bereits geloeschte/fehlende IDs werden
     * uebersprungen statt abzubrechen.
     * @return array{merged: int, skipped: int}
     */
    private function mergeClusters(array $clusters, CustomerMergeService $merge): array {
        $allIds = array_merge([], ...array_map('array_values', $clusters));
        $customers = Customer::with('user')->whereIn('id', $allIds)->get()->keyBy('id');

        $merged = 0;
        $skipped = 0;
        foreach ($clusters as $members) {
            $present = array_values(array_filter($members, fn ($id) => $customers->has($id)));
            if (count($present) < 2) {
                continue;
            }
            usort($present, fn ($x, $y) => $customers[$x]->created_at <=> $customers[$y]->created_at);
            $primaryId = array_shift($present);
            foreach ($present as $dupId) {
                $primary = Customer::with('user')->find($primaryId);
                $dup = Customer::with('user')->find($dupId);
                if (! $primary || ! $dup || (string) $primary->id === (string) $dup->id) {
                    $skipped++;
                    continue;
                }
                try {
                    $merge->merge($primary, $dup, auth()->id());
                    $merged++;
                } catch (\Throwable $e) {
                    $skipped++;
                }
            }
        }
        return ['merged' => $merged, 'skipped' => $skipped];
    }

    private function mergeSummary(array $res, bool $auto): string {
        $message = "{$res['merged']} Zusammenführung(en) durchgeführt.";
        if ($res['skipped'] > 0) {
            $message .= " {$res['skipped']} übersprungen (bereits zusammengeführt oder nicht zulässig).";
        }
        return $message;
    }
}
