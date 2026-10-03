<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ScopesCustomerAccess;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerRelationship;
use App\Services\Matching\CustomerMatchingService;
use App\Services\Matching\CustomerMergeService;
use App\Services\Matching\DuplicateDetectionService;
use App\Services\Relationships\CustomerRelationshipService;
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
        $counts = ['familie' => 0, 'name' => 0, 'address' => 0, 'email' => 0, 'phone' => 0, 'iban' => 0, 'contract' => 0, 'birthdate' => 0];
        $pairs = array_map(function ($p) use (&$counts) {
            $cats = [];
            if ($p['klasse'] === DuplicateDetectionService::KLASSE_FAMILIE) {
                $cats['familie'] = true;
            }
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

        // Dieselbe Klasse wie im Merge-All-Pfad - die Button-Zahl entspricht
        // damit genau der Aktion (KI-062).
        $strongCount = count(array_filter($pairs, fn ($p) => $p['klasse'] === DuplicateDetectionService::KLASSE_SICHER));

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
    public function dismissDuplicate(Request $request, CustomerRelationshipService $relations) {
        $data = $request->validate([
            'customer_a' => 'required|string',
            'customer_b' => 'required|string|different:customer_a',
            'note' => 'nullable|string|max:255|required_if:type,sonstiges',
            'type' => 'nullable|in:'.implode(',', CustomerRelationship::TYPES),
            'parent_customer_id' => 'nullable|string|required_if:type,elternteil_kind',
        ], [
            'note.required_if' => 'Bitte beschreiben Sie die Beziehung bei „Sonstiges".',
            'parent_customer_id.required_if' => 'Bitte wählen Sie, wer der Elternteil ist.',
        ]);
        $this->authorizeCustomerAccess($data['customer_a']);
        $this->authorizeCustomerAccess($data['customer_b']);
        $a = Customer::findOrFail($data['customer_a']);
        $b = Customer::findOrFail($data['customer_b']);

        $type = $data['type'] ?? 'not_duplicate';
        if ($type === 'not_duplicate') {
            // Hat das Paar schon eine Beziehung, bleibt sie unveraendert -
            // "Kein Duplikat" wuerde sie sonst verallgemeinern.
            $relations->markNotDuplicate((string) $a->id, (string) $b->id, auth()->id());

            return back()->with('success', 'Als „kein Duplikat" markiert – das Paar erscheint jetzt unter „Verwandte Kunden".');
        }

        try {
            $relations->set($a, $b, $type, $data['parent_customer_id'] ?? null, $data['note'] ?? null, auth()->id());
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Als „'.CustomerRelationship::typeLabel($type)
            .'" verknüpft – beide Kunden bleiben mit allen Verträgen erhalten und erscheinen unter „Verwandte Kunden".');
    }

    /**
     * Sammel-Aktion: mehrere ausgewaehlte Paare auf einmal als "kein Duplikat"
     * markieren (schnelles Aufraeumen, z. B. alle Adress-Treffer eines
     * Haushalts). Reihenfolge-unabhaengig, dedupliziert.
     */
    public function dismissBulk(Request $request, CustomerRelationshipService $relations) {
        $data = $request->validate([
            'pairs' => 'required|array|min:1|max:500',
            'pairs.*' => 'string',
            // Ohne Elternteil-Kind: die Richtung ist je Paar zu waehlen.
            'type' => 'nullable|in:'.implode(',', CustomerRelationship::BULK_TYPES),
            'note' => 'nullable|string|max:255|required_if:type,sonstiges',
        ], [
            'type.in' => '„Elternteil – Kind" lässt sich nur einzeln festlegen – dort wird gewählt, wer der Elternteil ist.',
            'note.required_if' => 'Bitte beschreiben Sie die Beziehung bei „Sonstiges".',
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
            if ($type === 'not_duplicate') {
                $marked += $relations->markNotDuplicate($a, $b, auth()->id()) ? 1 : 0;
                continue;
            }
            $relations->set(Customer::findOrFail($a), Customer::findOrFail($b), $type, null, $data['note'] ?? null, auth()->id());
            $marked++;
        }
        app(DuplicateDetectionService::class)->forgetCount();

        $label = CustomerRelationship::typeLabel($type);
        $msg = $type === 'not_duplicate'
            ? $marked.' Paar(e) als „kein Duplikat" markiert – jetzt unter „Verwandte Kunden".'
            : $marked.' Paar(e) als „'.$label.'" verknüpft – beide Kunden bleiben erhalten, jetzt unter „Verwandte Kunden".';

        return redirect()->route('admin.customers.duplicates')->with('success', $msg);
    }

    /**
     * "Verwandte Kunden": alle Beziehungen (eine Zeile je Paar UND Art).
     * Nur Paare, deren BEIDE Kunden im Portfolio des Mitarbeiters liegen.
     * Filter: Art oder "ehepaar_unbestaetigt" (Altbestand ohne Familienrolle,
     * zur manuellen Pruefung - die frueheren Ehepaar-Markierungen).
     */
    public function relationships(Request $request, DuplicateDetectionService $detection, CustomerRelationshipService $service) {
        $ids = $this->visibleCustomerIds();
        $filter = (string) $request->query('filter', '');
        if ($filter !== 'ehepaar_unbestaetigt' && ! in_array($filter, CustomerRelationship::TYPES, true)) {
            $filter = '';
        }

        $query = CustomerRelationship::with(['customerA.user', 'customerB.user', 'customerA.contracts:id,customer_id,contract_number', 'customerB.contracts:id,customer_id,contract_number'])->latest();
        if ($ids !== null) {
            $query->whereIn('customer_a_id', $ids)->whereIn('customer_b_id', $ids);
        }
        $unconfirmedCount = $service->whereUnconfirmed((clone $query)->where('customer_relationships.type', 'ehepartner'))->count();
        if ($filter === 'ehepaar_unbestaetigt') {
            $service->whereUnconfirmed($query->where('customer_relationships.type', 'ehepartner'));
        } elseif ($filter !== '') {
            $query->where('customer_relationships.type', $filter);
        }
        $unconfirmedIds = $service->whereUnconfirmed(CustomerRelationship::query())->pluck('id')->map(fn ($i) => (string) $i)->all();

        $relations = $query->limit(500)->get()
            ->filter(fn ($r) => $r->customerA && $r->customerB)
            ->map(function ($r) use ($detection, $unconfirmedIds) {
                $r->signals = $detection->pairSignals($r->customerA, $r->customerB);
                $r->unbestaetigt = in_array((string) $r->id, $unconfirmedIds, true);
                $r->vorschlagElternteil = CustomerRelationship::suggestParent($r->customerA, $r->customerB);
                return $r;
            })->values();

        return view('admin.customer_relationships', [
            'relations' => $relations,
            'filter' => $filter,
            'unconfirmedCount' => $unconfirmedCount,
        ]);
    }

    /**
     * Beziehung aus der Kundenakte anlegen ("Verknuepfte Kunden" -> Kunde
     * suchen). Beide Kunden muessen im Portfolio liegen.
     */
    public function relationshipStore(Request $request, string $id, CustomerRelationshipService $relations) {
        $data = $request->validate([
            'related_customer_id' => 'required|string|different:'.$id,
            'type' => 'required|in:'.implode(',', CustomerRelationship::RELATION_TYPES),
            'parent' => 'nullable|required_if:type,elternteil_kind|in:self,other',
            'note' => 'nullable|string|max:255|required_if:type,sonstiges',
        ], [
            'related_customer_id.required' => 'Bitte wählen Sie zuerst einen Kunden aus.',
            'related_customer_id.different' => 'Ein Kunde kann nicht mit sich selbst verknüpft werden.',
            'parent.required_if' => 'Bitte wählen Sie, wer der Elternteil ist.',
            'note.required_if' => 'Bitte beschreiben Sie die Beziehung bei „Sonstiges".',
        ]);
        $this->authorizeCustomerAccess($id);
        $this->authorizeCustomerAccess($data['related_customer_id']);
        $customer = Customer::findOrFail($id);
        $other = Customer::findOrFail($data['related_customer_id']);

        $parentId = match ($data['parent'] ?? null) {
            'self' => (string) $customer->id,
            'other' => (string) $other->id,
            default => null,
        };
        try {
            $relations->set($customer, $other, $data['type'], $parentId, $data['note'] ?? null, auth()->id());
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Beziehung „'.CustomerRelationship::typeLabel($data['type']).'" mit '
            .($other->user?->name ?: 'Kunde').' gespeichert.');
    }

    /** Beziehung entfernen (samt Familienrolle) -> Paar kann wieder als moegliche Dublette erscheinen. */
    public function relationshipDelete($id, CustomerRelationshipService $relations) {
        $rel = CustomerRelationship::findOrFail($id);
        $this->authorizeCustomerAccess($rel->customer_a_id);
        $this->authorizeCustomerAccess($rel->customer_b_id);
        $relations->delete($rel, auth()->id());

        return back()->with('success', 'Beziehung entfernt – das Paar kann wieder als mögliche Dublette erscheinen.');
    }

    /**
     * Art, Richtung oder Notiz einer bestehenden Beziehung aendern. Aendert
     * NICHTS an den Kundenakten - nur die Kennzeichnung (und die
     * Familienrolle im Gleichlauf).
     */
    public function relationshipSetType(Request $request, $id, CustomerRelationshipService $relations) {
        $data = $request->validate([
            'type' => 'required|in:'.implode(',', CustomerRelationship::TYPES),
            'parent_customer_id' => 'nullable|string|required_if:type,elternteil_kind',
            'note' => 'nullable|string|max:255|required_if:type,sonstiges',
        ], [
            'parent_customer_id.required_if' => 'Bitte wählen Sie, wer der Elternteil ist.',
            'note.required_if' => 'Bitte beschreiben Sie die Beziehung bei „Sonstiges".',
        ]);
        $rel = CustomerRelationship::findOrFail($id);
        $this->authorizeCustomerAccess($rel->customer_a_id);
        $this->authorizeCustomerAccess($rel->customer_b_id);

        try {
            $relations->update($rel, $data['type'], $data['parent_customer_id'] ?? null,
                array_key_exists('note', $data) ? $data['note'] : $rel->note, auth()->id());
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Beziehung als „'.CustomerRelationship::typeLabel($data['type']).'" gekennzeichnet.');
    }

    /** Unbestaetigte Familienart (Altbestand) bestaetigen: die Familienrolle wird angelegt. */
    public function relationshipConfirm($id, CustomerRelationshipService $relations) {
        $rel = CustomerRelationship::findOrFail($id);
        $this->authorizeCustomerAccess($rel->customer_a_id);
        $this->authorizeCustomerAccess($rel->customer_b_id);
        $relations->confirm($rel, auth()->id());

        return back()->with('success', '„'.CustomerRelationship::typeLabel($rel->type).'" bestätigt – die Familienrolle ist jetzt in beiden Akten eingetragen.');
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

        // Verschiedene Personen (abweichendes Geburtsdatum/Vorname) werden
        // NIE ueber die Sammelauswahl zusammengefuehrt - "Alle auswaehlen"
        // erfasste sonst Vater und Sohn mit (KI-062). Geprueft wird JEDES
        // Paar einer Gruppe, nicht nur die ausgewaehlten Kanten: ueber eine
        // Akte ohne Geburtsdatum koennten sonst zwei Personen in dieselbe
        // Gruppe rutschen.
        [$clusters, $blocked] = $this->withoutConflictingClusters($clusters, app(DuplicateDetectionService::class));

        $res = $this->mergeClusters($clusters, $merge);
        $message = $this->mergeSummary($res, false);
        if ($blocked > 0) {
            $message .= ' '.$blocked.' Auswahl(en) NICHT zusammengeführt: widersprechende Identitätsmerkmale (z. B. abweichendes Geburtsdatum oder Vorname) – das sind verschiedene Personen. Bitte „Beziehung festlegen".';
        }
        return redirect()->route('admin.customers.duplicates')->with('success', $message);
    }

    /**
     * Ein-Klick-Zusammenfuehrung ALLER "sicheren" Treffer (Score >=
     * AUTO_MERGE_MIN_SCORE, Betreiber-Vorgabe 40 %). Schwaechere Treffer
     * (z. B. nur gleicher Name) bleiben bewusst der manuellen Pruefung
     * vorbehalten. Aus Zeitgruenden pro Lauf gedeckelt - der Hinweis fordert
     * bei Bedarf zum erneuten Klick auf, bis alles bereinigt ist.
     */
    public function duplicatesMergeAll(DuplicateDetectionService $detection, CustomerMergeService $merge) {
        // Frischer Scan (nie auf veraltete Seiten-Daten verlassen).
        $result = $detection->scan($this->visibleCustomerIds());
        // NUR die Klasse "sicher" (gleicher Name UND gleiches Geburtsdatum,
        // kein Widerspruch). Gemeinsame E-Mail/Telefon/Anschrift/IBAN allein
        // reichen nie - genau die teilen Familien (KI-062, Audit MERGE-1).
        $strong = array_values(array_filter(
            $result['pairs'],
            fn ($p) => $p['klasse'] === DuplicateDetectionService::KLASSE_SICHER
        ));

        if ($strong === []) {
            return redirect()->route('admin.customers.duplicates')
                ->with('success', 'Keine sicheren Dubletten (gleicher Name und gleiches Geburtsdatum) zum automatischen Zusammenführen gefunden. '
                    .'Alle übrigen Verdachtsfälle bitte einzeln prüfen.');
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
        [$clusters] = $this->withoutConflictingClusters($this->clusterPairs($edges, $ids), $detection);
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
        $message = "{$res['merged']} sichere Zusammenführung(en) durchgeführt.";
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
        $konflikte = [];
        if ($suggested) {
            $preview = $merge->preview($suggested);
            $konflikte = app(DuplicateDetectionService::class)->identityConflicts($customer, $suggested);
        }

        return view('admin.customer_merge', compact('customer', 'suggested', 'preview', 'konflikte'));
    }

    public function mergeCustomers(Request $request, $id, CustomerMergeService $merge) {
        $this->authorizeCustomerAccess($id);
        $request->validate(['duplicate_id' => 'required|different:id']);
        $this->authorizeCustomerAccess($request->duplicate_id);
        $primary = Customer::with('user')->findOrFail($id);
        $dup = Customer::with('user')->findOrFail($request->duplicate_id);
        if ((string) $primary->id === (string) $dup->id) return back()->with('success', 'Gleicher Kunde gewählt.');

        // Widerspricht ein Identitaetsmerkmal (abweichendes Geburtsdatum,
        // anderer Vorname), sind es verschiedene Personen. Zusammenfuehren
        // nur, wenn der Admin den Widerspruch ausdruecklich uebersteuert und
        // begruendet - protokolliert (KI-062). Vorher genuegte ein Klick.
        $konflikte = app(DuplicateDetectionService::class)->identityConflicts($primary, $dup);
        if ($konflikte !== []) {
            // Zurueck IMMER auf das Formular MIT diesem Duplikat - nur dort
            // stehen die Widersprueche und die Felder zum Uebersteuern (auch
            // wenn es per Sofort-Suche statt per Vorschlag gewaehlt wurde).
            $pruefung = validator($request->all(), [
                'konflikt_bestaetigt' => 'accepted',
                'konflikt_begruendung' => 'required|string|min:15|max:500',
            ], [
                'konflikt_bestaetigt.accepted' => 'Die beiden Akten widersprechen sich ('.implode('; ', $konflikte).'). Bitte bestätigen Sie ausdrücklich, dass es trotzdem dieselbe Person ist – oder legen Sie eine Beziehung fest.',
                'konflikt_begruendung.required' => 'Bitte begründen Sie, warum es trotz des Widerspruchs dieselbe Person ist.',
                'konflikt_begruendung.min' => 'Die Begründung muss mindestens 15 Zeichen lang sein.',
            ]);
            if ($pruefung->fails()) {
                return redirect()->to(route('admin.customer.merge', $primary->id).'?duplicate='.$dup->id)
                    ->withErrors($pruefung)->withInput();
            }
            ActivityLog::record('customer_merge_override', 'customer', $primary->id, [
                'duplicate_id' => (string) $dup->id,
                'duplicate_number' => $dup->customer_number,
                'konflikte' => $konflikte,
                'begruendung' => (string) $request->input('konflikt_begruendung'),
            ]);
        }

        $moved = $merge->merge($primary, $dup, auth()->id());

        $summary = collect($moved)->sum();
        return redirect()->route('admin.customer', $primary->id)
            ->with('success', "Kunden erfolgreich zusammengeführt. {$summary} verknüpfte Datensätze wurden übertragen, nichts wurde gelöscht.");
    }

    /**
     * Entfernt Gruppen, in denen IRGENDEIN Paar einen Identitaets-Widerspruch
     * hat (verschiedene Personen). Gibt die verbleibenden Gruppen und die
     * Anzahl der verworfenen zurueck.
     *
     * @param array<int, array<int, string>> $clusters
     * @return array{0: array<int, array<int, string>>, 1: int}
     */
    private function withoutConflictingClusters(array $clusters, DuplicateDetectionService $detection): array {
        $allIds = array_merge([], ...array_map('array_values', $clusters));
        $customers = Customer::with('user')->whereIn('id', $allIds)->get()->keyBy(fn ($c) => (string) $c->id);

        $ok = [];
        $blocked = 0;
        foreach ($clusters as $members) {
            $present = array_values(array_filter($members, fn ($id) => $customers->has((string) $id)));
            $conflict = false;
            for ($i = 0; $i < count($present) && ! $conflict; $i++) {
                for ($j = $i + 1; $j < count($present); $j++) {
                    if ($detection->hasIdentityConflict($customers[(string) $present[$i]], $customers[(string) $present[$j]])) {
                        $conflict = true;
                        break;
                    }
                }
            }
            if ($conflict) {
                $blocked++;
                continue;
            }
            $ok[] = $members;
        }

        return [$ok, $blocked];
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
