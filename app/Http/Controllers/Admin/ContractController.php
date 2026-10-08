<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ScopesCustomerAccess;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Contract;
use App\Models\ContractEnergyDetail;
use App\Models\ContractInternetDetail;
use App\Models\ContractRevision;
use App\Models\ContractVehicleDetail;
use App\Models\Customer;
use App\Models\Document;
use App\Models\MeterReading;
use App\Models\VehicleClaim;
use App\Models\VehicleSfReference;
use App\Services\CommissionImport\CommissionAuditLogger;
use App\Services\ContractSwitchService;
use App\Services\Energy\MeterReadingService;
use App\Services\Kfz\SfReferenceService;
use App\Services\Kfz\SfReferenceValidator;
use App\Services\VehicleOverlapGuard;
use App\Services\Vermittler\VermittlerLinkService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Vertraege in der Beraterwelt (ARCH-5, aus AdminController herausgeloest).
 *
 * Rein mechanisch verschoben: Routen, Berechtigungen, Validierung,
 * Weiterleitungen und Geschaeftslogik sind unveraendert. Der Block ist mit
 * Abstand der groesste des alten Controllers gewesen - allein
 * validateContract und die vier sync*-Methoden fuer Fahrzeug-, E-Scooter-,
 * Energie- und Internetdetails machen rund 350 Zeilen aus.
 */
class ContractController extends Controller
{
    use ScopesCustomerAccess;

    /** Hinweise aus dem SF-Bezug (z. B. angelegter Fremdvertrag) fuer die Erfolgsmeldung. */
    private array $sfNotes = [];

    /**
     * Vertragsliste. Gruppe (aktiver Bestand / in Bearbeitung / Historie)
     * und Suche laufen in der DATENBANK, nicht im Browser.
     *
     * Vorher lud die Seite ALLE Vertraege und filterte sie per JavaScript
     * ueber die fertigen Tabellenzeilen. Das funktioniert genau so lange,
     * wie der Bestand klein ist - danach waechst jeder Seitenaufruf linear
     * mit der Gesamtzahl, bis die Seite in ein Speicher- oder Zeitlimit
     * laeuft. Und es faellt erst auf, wenn es zu spaet ist.
     *
     * Die Gruppen-Definition bleibt die EINE Quelle: scopeStatusGroup()
     * ist der Query-Spiegel von Contract::statusGroup() - Badge, Zaehler
     * und Filter koennen sich nicht widersprechen.
     */
    public function contracts(Request $request) {
        $ids = $this->visibleCustomerIds();
        $suche = trim((string) $request->query('q', ''));

        // "alle" ist eine bewusste Auswahl, kein Standard: die Liste oeffnet
        // wie bisher auf dem aktiven Bestand.
        $gruppen = [Contract::GROUP_ACTIVE, Contract::GROUP_PENDING, Contract::GROUP_HISTORY, 'alle'];
        $gruppe = in_array($request->query('gruppe'), $gruppen, true)
            ? (string) $request->query('gruppe')
            : Contract::GROUP_ACTIVE;

        // Herkunft (28.09.2026): Standard ist der EIGENBESTAND - sonst
        // zaehlte "Aktiver Bestand" dokumentierte Fremdvertraege mit, fuer
        // die wir weder Mandat noch Courtage haben.
        $herkuenfte = ['eigen', 'fremd', 'alle'];
        $herkunft = in_array($request->query('herkunft'), $herkuenfte, true)
            ? (string) $request->query('herkunft')
            : 'eigen';

        $basis = fn (?string $h = null) => Contract::query()
            ->when($ids !== null, fn ($q) => $q->whereIn('customer_id', $ids))
            ->originFilter($h ?? $herkunft)
            ->search($suche);

        // Zaehler je Gruppe als reine COUNT-Abfragen - es wird keine einzige
        // Zeile geladen, nur gezaehlt. Sie folgen der Suche und der Herkunft,
        // damit die Zahl in den Reitern zum Gezeigten passt.
        $zaehler = [
            Contract::GROUP_ACTIVE => $basis()->statusGroup(Contract::GROUP_ACTIVE)->count(),
            Contract::GROUP_PENDING => $basis()->statusGroup(Contract::GROUP_PENDING)->count(),
            Contract::GROUP_HISTORY => $basis()->statusGroup(Contract::GROUP_HISTORY)->count(),
            'alle' => $basis()->count(),
        ];
        $herkunftZaehler = [];
        foreach ($herkuenfte as $h) {
            $herkunftZaehler[$h] = $basis($h)->statusGroup($gruppe === 'alle' ? null : $gruppe)->count();
        }

        $contracts = $basis()
            ->with(['customer.user', 'successor', 'predecessor'])
            ->statusGroup($gruppe === 'alle' ? null : $gruppe)
            ->latest()
            ->paginate(50)
            ->withQueryString();

        return view('admin.contracts', compact('contracts', 'gruppe', 'suche', 'zaehler', 'herkunft', 'herkunftZaehler'));
    }

    /**
     * Fremdbestand - Uebernahmepotenzial (28.09.2026): LAUFENDE
     * Fremdvertraege ueber alle sichtbaren Kunden. Jeder davon ist ein
     * Anlass fuer eine Bestandsuebertragung oder ein Gegenangebot. Sortiert
     * nach Ablauf: wer zuerst wechseln kann, steht oben; ohne Ablauf am Ende.
     */
    public function contractsFremdbestand(Request $request) {
        $ids = $this->visibleCustomerIds();
        $contracts = Contract::query()
            ->when($ids !== null, fn ($q) => $q->whereIn('customer_id', $ids))
            ->externalOrigin()
            ->currentlyActive()
            ->with(['customer.user', 'successor'])
            ->orderByRaw('CASE WHEN end_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('end_date')
            ->paginate(50)
            ->withQueryString();

        return view('admin.contracts_fremdbestand', compact('contracts'));
    }

    /**
     * Pruefliste "Vertraege mit ungepruefter Herkunft" (28.09.2026). Der
     * Altbestand wurde bei der Einfuehrung als Eigenvertrag angenommen,
     * automatisch angelegte Vertraege (Dokumenten-Eingang) ebenso - beides
     * ist eine ANNAHME, und hier wird sie abgearbeitet.
     */
    public function originReview(Request $request) {
        $ids = $this->visibleCustomerIds();
        $contracts = Contract::query()
            ->when($ids !== null, fn ($q) => $q->whereIn('customer_id', $ids))
            ->where('origin_verified', false)
            ->with('customer.user')
            ->orderBy('created_at')
            ->paginate(50)
            ->withQueryString();
        $darfAendern = in_array(auth()->user()->role, ['admin', 'manager'], true);

        return view('admin.contracts_origin_review', compact('contracts', 'darfAendern'));
    }

    /**
     * Sammelaktion der Pruefliste. "Bestaetigen" laesst die Herkunft wie sie
     * ist (jede Personalrolle); "als Fremdvertrag"/"als Eigenvertrag"
     * AENDERT sie und ist admin/manager vorbehalten - jede Aenderung einzeln
     * protokolliert. "Uebernommen" gibt es hier bewusst nicht: es braucht
     * ein Datum je Vertrag, das gehoert ins Formular.
     */
    public function originReviewStore(Request $request) {
        $data = $request->validate([
            'aktion' => 'required|in:bestaetigen,als_fremd,als_eigen',
            'ids' => 'required|array|min:1|max:200',
            'ids.*' => 'uuid',
        ], ['ids.required' => 'Bitte mindestens einen Vertrag auswählen.']);

        $neu = ['als_fremd' => Contract::ORIGIN_EXTERNAL, 'als_eigen' => Contract::ORIGIN_BROKERED][$data['aktion']] ?? null;
        if ($neu !== null) {
            abort_unless(in_array(auth()->user()->role, ['admin', 'manager'], true), 403);
        }

        $ids = $this->visibleCustomerIds();
        $vertraege = Contract::whereIn('id', $data['ids'])
            ->when($ids !== null, fn ($q) => $q->whereIn('customer_id', $ids))
            ->get();

        foreach ($vertraege as $vertrag) {
            $vorher = $vertrag->origin ?? Contract::ORIGIN_BROKERED;
            $vertrag->origin_verified = true;
            if ($neu !== null) {
                $vertrag->origin = $neu;
                if ($neu === Contract::ORIGIN_BROKERED) {
                    $vertrag->previous_broker = null;
                    $vertrag->origin_note = null;
                    $vertrag->cancellation_submitted_by_us = false;
                }
                $vertrag->transfer_date = null;
            }
            // saveQuietly: eine Herkunftspruefung ist kein Vertragsereignis
            // (keine Provision, kein Storno, keine Wechsel-Automatik).
            $vertrag->saveQuietly();
            $this->recordOriginChange($vertrag, $vorher);
        }

        return back()->with('success', $vertraege->count().' Vertrag/Verträge '
            .($neu === null ? 'bestätigt.' : 'geprüft und als „'.Contract::ORIGIN_LABELS[$neu].'" gespeichert.'));
    }

    public function contractNew() {
        // Die Kundenauswahl laedt NICHT mehr alle Kunden in die Seite: das
        // Formular fragt bei jedem Tastendruck contractCustomerSearch() -
        // derselbe Weg wie im Aufgaben- und E-Mail-Formular. Vorher stand
        // der komplette Kundenbestand als JSON im HTML, was mit jedem
        // Neukunden waechst und irgendwann jeden Aufruf des Formulars
        // ausbremst.
        return view('admin.contract_new');
    }

    public function contractCreate($customerId) {
        $this->authorizeCustomerAccess($customerId);
        $customer = Customer::with('user')->findOrFail($customerId);
        // KI-095: kein Vertrag an der Akte eines abhaengigen Kindes - gar
        // nicht erst ein Formular zeigen, das am Ende scheitert.
        if (($grund = $customer->eigenstaendigkeitsSperre()) !== null) {
            return redirect()->route('admin.customer', $customerId)->with('error', $grund);
        }
        return view('admin.contract_create', compact('customer'));
    }

    public function contractStore(Request $request, $customerId) {
        $this->authorizeCustomerAccess($customerId);
        if (($grund = Customer::findOrFail($customerId)->eigenstaendigkeitsSperre()) !== null) {
            return back()->withErrors(['customer' => $grund])->withInput();
        }
        $this->validateContract($request);
        $herkunft = $this->validateOrigin($request, (string) $customerId);
        $this->precheckSfReferences($request, new Contract(['customer_id' => $customerId, 'type' => $request->type]));

        // Doppelversicherungs-Schutz + Wechsel-Automatik (26.07.2026):
        // Gleiches Fahrzeug, ANDERER Versicherer = Wechsel -> am Altvertrag
        // wird automatisch die Kuendigung erfasst (eingereicht heute, Ablauf
        // = Beginn des neuen Vertrags). Gleicher Versicherer = Duplikat ->
        // Fehler. Ueberschneidung ohne Beginn laesst sich nicht verketten.
        $switchNote = '';
        if ($conflict = $this->findVehicleConflict($request, (string) $customerId)) {
            $guard = app(VehicleOverlapGuard::class);
            $istWechsel = ! Contract::insurersLookAlike($conflict->insurer, $request->insurer);
            if (! $istWechsel) {
                return back()->withErrors(['vehicle_overlap' => $guard->conflictMessage($conflict)])->withInput();
            }
            if (! $request->filled('start_date')) {
                return back()->withErrors(['vehicle_overlap' => 'Versicherer-Wechsel erkannt: Bitte den Beginn des neuen Vertrags angeben – der Altvertrag ('.$conflict->insurer.') wird dann automatisch zu diesem Tag gekündigt.'])->withInput();
            }
            app(ContractSwitchService::class)->recordCancellationForSwitch(
                $conflict, \Illuminate\Support\Carbon::parse($request->start_date), 'system', auth()->id());
            if ($rest = $this->findVehicleConflict($request, (string) $customerId)) {
                return back()->withErrors(['vehicle_overlap' => $guard->conflictMessage($rest)])->withInput();
            }
            $ende = $conflict->fresh()->effectiveCancellationDate();
            $switchNote = ' Wechsel erkannt: '.$conflict->insurer.' wurde automatisch gekündigt zum '.($ende ? $ende->format('d.m.Y') : '—').'.';
        }

        $contract = Contract::create([
            'id' => Str::uuid(),
            'customer_id' => $customerId,
            // Echte Versicherungsnummer wird spaeter nachgetragen -> KEINE
            // automatische Fantasienummer mehr (Betreiber-Feedback).
            'contract_number' => $request->filled('contract_number') ? trim($request->contract_number) : null,
            'internal_contract_number' => $request->filled('internal_contract_number') ? trim($request->internal_contract_number) : null,
            'reference_number' => $request->filled('reference_number') ? trim($request->reference_number) : null,
            'vermittler_id' => $request->filled('vermittler_id') ? trim($request->vermittler_id) : null,
            'type' => $request->type,
            'type_other' => $request->type === 'andere' ? ($request->type_other ?: null) : null,
            'subtype' => Contract::normalizeSubtype($request->type, $request->subtype),
            'insurer' => $request->insurer,
            'status' => $request->status,
            'stage' => $request->filled('stage') ? $request->stage : null,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'cancellation_date' => $request->cancellation_date,
            'notes' => $request->notes,
            'premium_amount' => $request->filled('premium_amount') ? $request->premium_amount : null,
            'premium_interval' => in_array($request->premium_interval, Contract::premiumIntervalKeys(), true) ? $request->premium_interval : 'monthly',
            'added_by' => auth()->user()?->name,
        ] + $herkunft + [
            // Ein Mensch hat die Herkunft gerade aktiv gewaehlt.
            'origin_verified' => true,
        ]);

        $this->syncContractDetails($contract, $request);
        $switchNote .= $this->syncOriginLinks($contract, $request);
        // Bei der Neuanlage erfasste Vermittler-Kennungen gehen sofort in die
        // Historie - und eine bereits importierte, bisher unzugeordnete
        // Abrechnungszeile findet damit ihren Vertrag.
        app(VermittlerLinkService::class)
            ->recordContractEdit($contract, ['reference_number' => null, 'vermittler_id' => null], auth()->id());

        return redirect()->route('admin.customer', $customerId)
            ->with('success', 'Vertrag erfolgreich hinzugefügt.'.$switchNote.$this->sfNoteText());
    }

    public function contractEdit($id) {
        $contract = Contract::with(['vehicleDetail.claims', 'vehicleDetail.mileageReadings', 'vehicleDetail.sfHistory', 'vehicleDetail.sfReferences.referenceContract.vehicleDetail', 'vehicleDetail.sfReferences.proofDocument', 'vehicleDetail.sfReferences.verifier', 'sfDependents.vehicleDetail.contract', 'energyDetail.meterReadings', 'internetDetail', 'customer.user', 'customer.documents', 'revisions.changedBy'])->findOrFail($id);
        $this->authorizeCustomerAccess($contract->customer_id);
        $sfWarnings = $contract->type === 'kfz' ? app(SfReferenceValidator::class)->warnings($contract) : [];
        return view('admin.contract_edit', compact('contract', 'sfWarnings'));
    }

    /**
     * Zaehlerstand eines Energievertrags von Hand erfassen (z.B. telefonische
     * Meldung des Kunden). Jede Ablesung ist eine eigene Zeile - der Verlauf
     * bleibt vollstaendig erhalten und ergibt die Verbrauchshistorie.
     */
    public function contractMeterReadingStore(Request $request, $id, MeterReadingService $meterReadings) {
        $contract = Contract::with('energyDetail')->findOrFail($id);
        $this->authorizeCustomerAccess($contract->customer_id);
        $detail = $contract->energyDetail;
        if (! $detail) {
            return back()->withErrors(['reading' => 'Für diesen Vertrag sind keine Energie-Daten hinterlegt.']);
        }

        $data = $request->validate([
            'reading' => 'required|numeric|min:0|max:99999999',
            'reading_date' => 'nullable|date|before_or_equal:today',
            'register' => 'nullable|in:'.implode(',', array_keys(MeterReading::REGISTERS)),
        ]);

        $entry = $meterReadings->record($detail, (float) $data['reading'], [
            'register' => $data['register'] ?? MeterReading::REGISTER_DEFAULT,
            'reading_date' => $data['reading_date'] ?? now()->toDateString(),
            'source' => 'staff',
            'created_by' => auth()->user()->name,
        ]);

        if (! $entry) {
            return back()->withErrors(['reading' => 'Der Zählerstand konnte nicht gespeichert werden.']);
        }

        return back()->with('success', 'Zählerstand erfasst: '.$entry->formatted()
            .' ('.$entry->reading_date->format('d.m.Y').').');
    }

    /**
     * Fehlerhafte Ablesung entfernen (nur admin/manager). Der Bestandswert
     * "aktueller Zaehlerstand" wird auf die dann juengste Ablesung
     * zurueckgesetzt, damit Anzeige und Historie zusammenpassen.
     */
    public function contractMeterReadingDestroy($id, $readingId) {
        $contract = Contract::with('energyDetail')->findOrFail($id);
        $this->authorizeCustomerAccess($contract->customer_id);
        $detail = $contract->energyDetail;
        abort_unless($detail !== null, 404);

        $reading = MeterReading::where('contract_energy_detail_id', $detail->id)
            ->where('id', $readingId)->firstOrFail();
        $register = $reading->register;
        $reading->delete();

        if ($register === MeterReading::REGISTER_DEFAULT) {
            $newest = MeterReading::where('contract_energy_detail_id', $detail->id)
                ->where('register', $register)
                ->orderByDesc('reading_date')->orderByDesc('created_at')->first();
            $detail->meter_reading = $newest
                ? rtrim(rtrim(number_format((float) $newest->reading, 3, '.', ''), '0'), '.')
                : null;
            $detail->save();
        }

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'meter_reading_deleted',
            'entity_type' => 'contract',
            'entity_id' => $contract->id,
            'meta' => json_encode([
                'reading' => (string) $reading->reading,
                'reading_date' => $reading->reading_date?->toDateString(),
            ], JSON_UNESCAPED_UNICODE),
        ]);

        return back()->with('success', 'Ablesung wurde gelöscht.');
    }

    public function contractUpdate(Request $request, $id) {
        $contract = Contract::with('vehicleDetail.claims')->findOrFail($id);
        $this->authorizeCustomerAccess($contract->customer_id);
        $this->validateContract($request, $contract->id);
        $herkunft = $this->validateOrigin($request, (string) $contract->customer_id, $contract);
        $this->precheckSfReferences($request, $contract);
        $fremdHandlung = $this->guardExternalAction($request, $contract);
        if ($conflictError = $this->vehicleOverlapError($request, (string) $contract->customer_id, $contract->id)) {
            return back()->withErrors(['vehicle_overlap' => $conflictError])->withInput();
        }

        // Vermittler-Kennungen VOR der Aenderung merken: die Historie soll
        // zeigen, wann eine Referenz-Nr./ID von Hand kam (20.08.2026).
        $herkunftBefore = $contract->origin ?? Contract::ORIGIN_BROKERED;
        $vermittlerBefore = [
            'internal_contract_number' => $contract->internal_contract_number,
            'reference_number' => $contract->reference_number,
            'vermittler_id' => $contract->vermittler_id,
        ];

        $contract->update([
            'contract_number' => $request->filled('contract_number') ? trim($request->contract_number) : null,
            'internal_contract_number' => $request->filled('internal_contract_number') ? trim($request->internal_contract_number) : null,
            'reference_number' => $request->filled('reference_number') ? trim($request->reference_number) : null,
            'vermittler_id' => $request->filled('vermittler_id') ? trim($request->vermittler_id) : null,
            'type' => $request->type,
            'type_other' => $request->type === 'andere' ? ($request->type_other ?: null) : null,
            'subtype' => Contract::normalizeSubtype($request->type, $request->subtype),
            'insurer' => $request->insurer,
            'status' => $request->status,
            'stage' => $request->filled('stage') ? $request->stage : null,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'cancellation_date' => $request->cancellation_date,
            'notes' => $request->notes,
            'premium_amount' => $request->filled('premium_amount') ? $request->premium_amount : null,
            'premium_interval' => in_array($request->premium_interval, Contract::premiumIntervalKeys(), true) ? $request->premium_interval : 'monthly',
        ] + $herkunft);

        $this->syncContractDetails($contract, $request);
        $linkNote = $this->syncOriginLinks($contract, $request);
        $this->recordOriginChange($contract, $herkunftBefore);
        if ($fremdHandlung !== null) {
            ActivityLog::record('external_contract_action', 'contract', $contract->id, $fremdHandlung + [
                'customer_id' => (string) $contract->customer_id,
                'insurer' => $contract->insurer,
            ]);
        }
        app(VermittlerLinkService::class)
            ->recordContractEdit($contract, $vermittlerBefore, auth()->id());

        // Die INTERNE Vertragsnummer ist der Schluessel der Provisionsabrechnung.
        // Wird sie von Hand geaendert, gehoert das ins Provisions-Protokoll -
        // sonst laesst sich spaeter nicht mehr erklaeren, warum eine
        // Abrechnung ploetzlich einen anderen Vertrag trifft.
        if (($vermittlerBefore['internal_contract_number'] ?? null) !== $contract->internal_contract_number) {
            app(CommissionAuditLogger::class)->log('vertragsnummer_geaendert', null, [
                'contract_id' => $contract->id,
                'internal_contract_number' => $contract->internal_contract_number,
                'field' => 'internal_contract_number',
                'old_value' => $vermittlerBefore['internal_contract_number'] ?? null,
                'new_value' => $contract->internal_contract_number,
            ]);
        }

        return redirect()->route('admin.customer', $contract->customer_id)->with('success', 'Vertrag aktualisiert.'.$linkNote.$this->sfNoteText());
    }

    public function contractDestroy($id) {
        $contract = Contract::findOrFail($id);
        $this->authorizeCustomerAccess($contract->customer_id);
        $customerId = $contract->customer_id;

        // Dokumente bleiben in der Kundenakte erhalten - nur die
        // Vertragszuordnung wird geloest (keine FK-Cascade auf documents).
        Document::where('contract_id', $contract->id)->update(['contract_id' => null]);

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'contract_deleted',
            'entity_type' => 'contract',
            'entity_id' => $contract->id,
            'meta' => json_encode(['customer_id' => (string) $customerId, 'insurer' => $contract->insurer, 'type' => $contract->type], JSON_UNESCAPED_UNICODE),
        ]);

        // Detail-Datensaetze und Wechsel-Erinnerungen haengen per FK-Cascade.
        $contract->delete();

        return redirect()->route('admin.customer', $customerId)->with('success', 'Vertrag gelöscht.');
    }

    /**
     * Vertragsherkunft pruefen und in Spaltenwerte uebersetzen
     * (Betreiber-Auftrag 28.09.2026). Serverseitig und je Herkunft - das
     * Formular blendet nur ein, es entscheidet nichts.
     *
     * Regeln:
     * - Herkunft ist Pflicht und hat bei der Neuanlage KEINE Voreinstellung.
     * - "Uebernommen" verlangt das Datum der Uebernahme.
     * - Aendern einer bestehenden Herkunft duerfen nur admin/manager, und
     *   nur mit ausdruecklicher Bestaetigung (protokolliert in
     *   recordOriginChange).
     * - Vorgaenger/Nachfolger muessen zum SELBEN Kunden gehoeren und duerfen
     *   nicht schon anderweitig verkettet sein - sonst entstuende eine Kette,
     *   die zwei Nachfolger fuer denselben Altvertrag behauptet.
     *
     * @return array<string,mixed>
     */
    private function validateOrigin(Request $request, string $customerId, ?Contract $existing = null): array {
        $selfId = $existing?->id;
        // Beim BEARBEITEN ist die Herkunft bereits entschieden: fehlt das Feld
        // (anderer Schreibweg, alte Formulare), bleibt sie unveraendert. Bei
        // der Neuanlage gibt es diese Rueckfallebene bewusst NICHT.
        if ($existing && ! $request->filled('origin')) {
            $request->merge(['origin' => $existing->origin ?? Contract::ORIGIN_BROKERED]);
        }
        $vomKunden = fn () => Rule::exists('contracts', 'id')->where('customer_id', $customerId);

        $data = $request->validate([
            'origin' => 'required|in:'.implode(',', Contract::originKeys()),
            'previous_broker' => 'nullable|string|max:150',
            'origin_note' => 'nullable|string|max:2000',
            'transfer_date' => 'nullable|date|before_or_equal:today|required_if:origin,'.Contract::ORIGIN_TRANSFERRED,
            'cancellation_submitted_by_us' => 'nullable|boolean',
            'replaces_mode' => 'nullable|in:none,existing,new',
            'replaces_contract_id' => ['nullable', 'uuid', 'required_if:replaces_mode,existing', $vomKunden(),
                Rule::notIn(array_filter([$selfId]))],
            'replaced_by_contract_id' => ['nullable', 'uuid', $vomKunden(), Rule::notIn(array_filter([$selfId]))],
            'predecessor.insurer' => 'nullable|string|max:255|required_if:replaces_mode,new',
            'predecessor.contract_number' => ['nullable', 'string', 'max:255', Rule::unique('contracts', 'contract_number')],
            'predecessor.previous_broker' => 'nullable|string|max:150',
            'predecessor.premium_amount' => 'nullable|numeric|min:0|max:9999999.99',
            'predecessor.cancellation_submitted_by_us' => 'nullable|boolean',
            'origin_change_confirmed' => 'nullable|boolean',
        ], [
            'origin.required' => 'Bitte die Vertragsherkunft wählen: Eigenvertrag, Fremdvertrag oder Übernommen.',
            'transfer_date.required_if' => 'Bei einem übernommenen Vertrag bitte das Datum der Übernahme angeben.',
            'replaces_contract_id.required_if' => 'Bitte den Vertrag wählen, der ersetzt wird.',
            'predecessor.insurer.required_if' => 'Bitte den Versicherer des Vorvertrags angeben.',
            'replaces_contract_id.not_in' => 'Ein Vertrag kann sich nicht selbst ersetzen.',
            'replaced_by_contract_id.not_in' => 'Ein Vertrag kann sich nicht selbst ersetzen.',
        ]);

        $origin = $data['origin'];
        $errors = [];

        if ($existing) {
            $bisher = $existing->origin ?? Contract::ORIGIN_BROKERED;
            if ($origin !== $bisher) {
                if (! in_array(auth()->user()->role, ['admin', 'manager'], true)) {
                    $errors['origin'] = 'Die Vertragsherkunft kann nur ein Admin oder Manager ändern.';
                } elseif (! $request->boolean('origin_change_confirmed')) {
                    $errors['origin_change_confirmed'] = 'Bitte die Änderung der Vertragsherkunft ausdrücklich bestätigen.';
                }
            }
        }

        $mode = $origin === Contract::ORIGIN_EXTERNAL ? 'none' : ($data['replaces_mode'] ?? null);
        if ($mode === 'new' && $existing) {
            $errors['replaces_mode'] = 'Ein Vorvertrag kann nur bei der Neuanlage gleich mit erfasst werden.';
        }
        if ($mode === 'existing' && ! empty($data['replaces_contract_id'])) {
            $belegt = Contract::where('replaces_contract_id', $data['replaces_contract_id'])
                ->when($selfId, fn ($q) => $q->where('id', '!=', $selfId))->exists();
            if ($belegt) {
                $errors['replaces_contract_id'] = 'Dieser Vertrag ist bereits als Vorvertrag eines anderen Vertrags verknüpft.';
            }
        }
        if ($origin === Contract::ORIGIN_EXTERNAL && ! empty($data['replaced_by_contract_id'])) {
            $nachfolger = Contract::find($data['replaced_by_contract_id']);
            if ($nachfolger?->isExternal()) {
                $errors['replaced_by_contract_id'] = 'Als Nachfolger kommt nur ein Eigenvertrag oder übernommener Vertrag in Frage.';
            } elseif ($nachfolger && $nachfolger->replaces_contract_id && $nachfolger->replaces_contract_id !== $selfId) {
                $errors['replaced_by_contract_id'] = 'Der gewählte Nachfolger ersetzt bereits einen anderen Vertrag.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $clean = fn ($v) => ($v === null || trim((string) $v) === '') ? null : trim((string) $v);
        $werte = [
            'origin' => $origin,
            'previous_broker' => $origin === Contract::ORIGIN_BROKERED ? null : $clean($data['previous_broker'] ?? null),
            'origin_note' => $origin === Contract::ORIGIN_BROKERED ? null : $clean($data['origin_note'] ?? null),
            'transfer_date' => $origin === Contract::ORIGIN_TRANSFERRED ? $data['transfer_date'] : null,
            'cancellation_submitted_by_us' => $origin === Contract::ORIGIN_EXTERNAL && $request->boolean('cancellation_submitted_by_us'),
        ];
        // Die Verkettung zum Vorgaenger nur anfassen, wenn das Formular sie
        // mitschickt - ein Fremdvertrag behaelt eine vorhandene Kette.
        if ($origin !== Contract::ORIGIN_EXTERNAL && $request->has('replaces_mode')) {
            $werte['replaces_contract_id'] = $mode === 'existing' ? $data['replaces_contract_id'] : null;
        }
        // Wer die Herkunft sieht und waehlen darf, hat sie damit geprueft.
        if ($existing && in_array(auth()->user()->role, ['admin', 'manager'], true)) {
            $werte['origin_verified'] = true;
        }
        return $werte;
    }

    /**
     * Vorgaenger/Nachfolger verknuepfen und - bei der Schnellanlage - den
     * Vorvertrag gleich als Fremdvertrag anlegen. Liefert einen Satz fuer
     * die Erfolgsmeldung.
     */
    private function syncOriginLinks(Contract $contract, Request $request): string {
        $note = '';
        $beginn = $contract->start_date ? \Illuminate\Support\Carbon::parse($contract->start_date) : null;

        if (! $contract->isExternal() && $request->input('replaces_mode') === 'new') {
            $p = (array) $request->input('predecessor', []);
            $vorvertrag = Contract::create([
                'customer_id' => $contract->customer_id,
                'type' => $contract->type,
                'type_other' => $contract->type_other,
                'subtype' => $contract->subtype,
                'insurer' => trim((string) $p['insurer']),
                'contract_number' => filled($p['contract_number'] ?? null) ? trim((string) $p['contract_number']) : null,
                // Laeuft bis zum Beginn des neuen Vertrags, danach Historie.
                // Ohne Beginn: sofort gekuendigt - ein Ablauf wird nie geraten.
                'status' => $beginn ? Contract::STATUS_ACTIVE : Contract::STATUS_CANCELLED,
                'cancellation_date' => $beginn ? null : now()->toDateString(),
                'premium_amount' => filled($p['premium_amount'] ?? null) ? $p['premium_amount'] : null,
                'premium_interval' => 'monthly',
                'origin' => Contract::ORIGIN_EXTERNAL,
                'origin_verified' => true,
                'previous_broker' => filled($p['previous_broker'] ?? null) ? trim((string) $p['previous_broker']) : null,
                'origin_note' => 'Als Vorvertrag erfasst, ersetzt durch '.$contract->insurer.'.',
                'cancellation_submitted_by_us' => (bool) ($p['cancellation_submitted_by_us'] ?? false),
                'added_by' => auth()->user()?->name,
            ]);
            if ($beginn) {
                app(ContractSwitchService::class)->recordCancellationForSwitch($vorvertrag, $beginn, 'manual', auth()->id());
            }
            $contract->forceFill(['replaces_contract_id' => $vorvertrag->id])->saveQuietly();
            ActivityLog::record('contract_predecessor_created', 'contract', $vorvertrag->id, [
                'successor_id' => $contract->id, 'insurer' => $vorvertrag->insurer,
            ]);
            $note = ' Vorvertrag '.$vorvertrag->insurer.' wurde als Fremdvertrag erfasst und verknüpft.';
        } elseif (! $contract->isExternal() && $contract->replaces_contract_id
            && ($contract->wasRecentlyCreated || $contract->wasChanged('replaces_contract_id'))) {
            // Vorhandenen Vertrag als Vorgaenger gewaehlt: ohne erfasste
            // Kuendigung endet er zum Beginn des neuen (wie beim Wechsel).
            $vorvertrag = Contract::find($contract->replaces_contract_id);
            if ($vorvertrag && $beginn && empty($vorvertrag->cancellation_date) && ! $vorvertrag->isHistoric()) {
                app(ContractSwitchService::class)->recordCancellationForSwitch($vorvertrag, $beginn, 'manual', auth()->id());
                $note = ' Am Vorvertrag '.$vorvertrag->insurer.' wurde die Kündigung zum '.$beginn->format('d.m.Y').' erfasst.';
            }
        }

        // Fremdvertrag: Nachfolger von dieser Seite aus setzen. Gespeichert
        // wird am NACHFOLGER (eine Spalte, eine Wahrheit).
        if ($contract->isExternal() && $request->has('replaced_by_contract_id')) {
            $neu = $request->input('replaced_by_contract_id') ?: null;
            Contract::where('replaces_contract_id', $contract->id)
                ->when($neu, fn ($q) => $q->where('id', '!=', $neu))
                ->update(['replaces_contract_id' => null]);
            if ($neu) {
                Contract::whereKey($neu)->update(['replaces_contract_id' => $contract->id]);
            }
        }
        return $note;
    }

    /**
     * Herkunftswechsel protokollieren: Aktivitaetsprotokoll (wer/wann) UND
     * Version History des Vertrags (alt -> neu) - eine stille Umdeutung
     * eines Fremdvertrags zum Eigenvertrag waere sonst nicht nachvollziehbar.
     */
    private function recordOriginChange(Contract $contract, string $before): void {
        $after = $contract->origin ?? Contract::ORIGIN_BROKERED;
        if ($after === $before) {
            return;
        }
        $label = fn ($o) => Contract::ORIGIN_LABELS[$o] ?? $o;
        ActivityLog::record('contract_origin_changed', 'contract', $contract->id, [
            'customer_id' => (string) $contract->customer_id,
            'old' => $before,
            'new' => $after,
        ]);
        ContractRevision::create([
            'contract_id' => $contract->id,
            'batch_id' => (string) Str::uuid(),
            'field' => 'origin',
            'label' => 'Vertragsherkunft',
            'old_value' => $label($before),
            'new_value' => $label($after),
            'source' => 'manual',
            'changed_by' => auth()->id(),
        ]);
    }

    /**
     * Schutz bei Handlungen an einem FREMDVERTRAG (kein Mandat): wer ihn
     * kuendigt oder einen Schaden daran erfasst, muss einen Grund nennen.
     * Liefert die Protokoll-Angaben oder null, wenn nichts zu schuetzen war.
     *
     * @return array<string,mixed>|null
     */
    private function guardExternalAction(Request $request, Contract $contract): ?array {
        if (! $contract->isExternal() || $request->input('origin') !== Contract::ORIGIN_EXTERNAL) {
            return null;
        }
        $aktionen = [];
        $wirdGekuendigt = ($request->input('status') === Contract::STATUS_CANCELLED && $contract->status !== Contract::STATUS_CANCELLED)
            || ($request->filled('cancellation_date') && empty($contract->cancellation_date));
        if ($wirdGekuendigt) {
            $aktionen[] = 'kuendigung';
        }
        $neueSchaeden = collect((array) $request->input('vehicle.claim_rows', []))
            ->filter(fn ($r) => is_array($r) && collect(['claim_date', 'claim_type', 'damage_amount', 'insurer', 'notes'])
                ->contains(fn ($k) => isset($r[$k]) && $r[$k] !== ''))
            ->count();
        if ($neueSchaeden > ($contract->vehicleDetail?->claims?->count() ?? 0)) {
            $aktionen[] = 'schadenmeldung';
        }
        if ($aktionen === []) {
            return null;
        }
        $request->validate([
            'fremdvertrag_grund' => 'required|string|min:5|max:1000',
        ], [
            'fremdvertrag_grund.required' => 'Dieser Vertrag wurde nicht über uns vermittelt (kein Mandat). Für Kündigung oder Schadenmeldung bitte einen Grund angeben.',
            'fremdvertrag_grund.min' => 'Bitte den Grund etwas genauer angeben.',
        ]);
        return ['aktionen' => $aktionen, 'grund' => trim((string) $request->input('fremdvertrag_grund'))];
    }

    /**
     * Kollidierenden KFZ-Bestandsvertrag zu den Formulardaten suchen
     * (Doppelversicherungs-Schutz, Betreiber-Vorgabe 26.07.2026): dasselbe
     * Fahrzeug darf keine zwei Vertraege mit ueberschneidendem Zeitraum
     * haben. Beim ANLEGEN loest ein anderer Versicherer die
     * Wechsel-Automatik aus (contractStore), beim BEARBEITEN wird nur
     * blockiert - Bearbeiten veraendert nie stillschweigend Altvertraege.
     */
    private function findVehicleConflict(Request $request, string $customerId, ?string $ignoreId = null): ?Contract {
        if ($request->type !== 'kfz') {
            return null;
        }
        // Transienter Vertrag nur fuer die Zeitraum-Logik - wird NIE gespeichert.
        $candidate = new Contract([
            'customer_id' => $customerId,
            'type' => 'kfz',
            'status' => $request->status,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'cancellation_date' => $request->cancellation_date,
        ]);
        return app(VehicleOverlapGuard::class)
            ->findConflict($candidate, (array) $request->input('vehicle', []), $ignoreId);
    }

    /** Fehlermeldung fuer contractUpdate (nur blockieren, nie automatisieren). */
    private function vehicleOverlapError(Request $request, string $customerId, ?string $ignoreId = null): ?string {
        $conflict = $this->findVehicleConflict($request, $customerId, $ignoreId);
        return $conflict ? app(VehicleOverlapGuard::class)->conflictMessage($conflict) : null;
    }

    /** Gemeinsame Validierung fuer Anlegen und Bearbeiten von Vertraegen. */
    /**
     * Sofort-Suche nach dem Bezugsfahrzeug (Erstwagen): KFZ-Vertraege des
     * Kunden und seiner verknuepften Familie, soweit der Bearbeiter sie
     * sehen darf. Liefert reine Daten - die Trefferliste baut das Formular
     * per textContent (Kundennamen sind Fremddaten).
     */
    public function sfReferenceSearch(Request $request, $customerId, SfReferenceService $service) {
        $this->authorizeCustomerAccess($customerId);
        $q = trim((string) $request->query('q', ''));
        $exclude = (string) $request->query('exclude', '');

        $query = $service->candidateQuery((string) $customerId, $request->user())
            ->when($exclude !== '', fn ($w) => $w->where('id', '!=', $exclude));
        if ($q !== '') {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $query->where(function ($w) use ($like) {
                $w->where('insurer', 'like', $like)
                    ->orWhere('contract_number', 'like', $like)
                    ->orWhereHas('vehicleDetail', fn ($v) => $v->where('license_plate', 'like', $like)
                        ->orWhere('manufacturer', 'like', $like)->orWhere('model', 'like', $like));
            });
        }

        $own = (string) $customerId;
        $rows = $query->orderByDesc('start_date')->limit(20)->get()
            ->sortBy(fn (Contract $c) => [(string) $c->customer_id === $own ? 0 : 1, $c->isCurrentlyActive() ? 0 : 1])
            ->values()
            ->map(fn (Contract $c) => [
                'id' => $c->id,
                'insurer' => $c->insurer,
                'contract_number' => $c->contract_number,
                'label' => VehicleSfReference::labelFor($c),
                'plate' => data_get($c, 'vehicleDetail.license_plate'),
                'vehicle' => trim(data_get($c, 'vehicleDetail.manufacturer', '').' '.data_get($c, 'vehicleDetail.model', '')),
                'sf_hp' => ContractVehicleDetail::sfLabel(data_get($c, 'vehicleDetail.sf_liability_class')),
                'sf_vk' => data_get($c, 'vehicleDetail.has_vollkasko') ? ContractVehicleDetail::sfLabel(data_get($c, 'vehicleDetail.sf_comprehensive_class')) : null,
                'status' => $c->displayStatus()['label'],
                'active' => $c->isCurrentlyActive(),
                'external' => $c->origin === Contract::ORIGIN_EXTERNAL,
                'owner' => (string) $c->customer_id === $own ? 'Kunde selbst' : data_get($c, 'customer.user.name', 'Familienmitglied'),
                'own_customer' => (string) $c->customer_id === $own,
                'url' => route('admin.contract.edit', $c->id),
            ]);

        return response()->json(['results' => $rows]);
    }

    /**
     * SF-Bezug VOR dem Speichern pruefen: Zugehoerigkeit, Selbst-/Kreisbezug
     * und - nur bei eingeschalteter Einstellung - die Bezugspflicht. So
     * entsteht nie ein halb gespeicherter Vertrag.
     */
    private function precheckSfReferences(Request $request, Contract $contract): void {
        if ($request->type !== 'kfz') return;
        $vehicle = (array) $request->input('vehicle', []);
        $errors = app(SfReferenceValidator::class)->blockingErrors($vehicle, $request->filled('stage') ? (string) $request->stage : null);
        if ($errors) throw ValidationException::withMessages($errors);

        $service = app(SfReferenceService::class);
        foreach (['haftpflicht', 'vollkasko'] as $branch) {
            $ref = (array) ($vehicle['sf_ref'][$branch] ?? []);
            if (($ref['reference_type'] ?? null) === 'internal' && ! empty($ref['reference_contract_id'])) {
                $service->assertAllowedReference($contract, (string) $ref['reference_contract_id'], $request->user());
            }
        }
    }

    private function sfNoteText(): string {
        return $this->sfNotes ? ' '.implode(' ', $this->sfNotes) : '';
    }

    private function validateContract(Request $request, ?string $ignoreId = null): array {
        return $request->validate([
            'type' => 'required|in:'.implode(',', Contract::typeKeys()),
            // Freitext-Sparte nur bei "Sonstige" - dann aber verpflichtend.
            'type_other' => 'nullable|string|max:120|required_if:type,andere',
            // Untergruppe je Sparte: GKV/PKV (Wechsel-Erinnerung, §175 SGB V)
            // bzw. Art der Krankenzusatz (ambulant/Zahn/Ausland).
            'subtype' => 'nullable|in:'.implode(',', Contract::subtypeKeys()),
            'insurer' => 'required|string|max:255',
            // Echte Versicherungsnummer, optional, aber eindeutig.
            'contract_number' => ['nullable', 'string', 'max:255', Rule::unique('contracts', 'contract_number')->ignore($ignoreId)],
            // Referenz-/Vorgangsnummer der Antragsstrecke (Portal/Vermittler).
            // Bewusst NICHT unique: ein Vorgang kann zwei Vertraege tragen
            // (z.B. Buendel Strom + Gas).
            'internal_contract_number' => 'nullable|string|max:60',
            'reference_number' => 'nullable|string|max:60',
            // Vermittler-ID (die `Id` aus der Abrechnungsdatei). Eindeutig:
            // ein Abrechnungs-Datensatz gehoert zu genau einem Vertrag -
            // zwei Vertraege mit derselben ID waeren ein Zuordnungsfehler.
            'vermittler_id' => ['nullable', 'string', 'max:60', Rule::unique('contracts', 'vermittler_id')->ignore($ignoreId)],
            // Status-Whitelist aus derselben Quelle wie die Auswahl im Formular
            // (Contract::STATUS_OPTIONS) - kein zweiter, driftender Wertevorrat.
            'status' => 'required|in:'.implode(',', Contract::statusKeys()),
            // Vertragsstufe: 'antrag' (Auftrag liegt vor, Bestaetigung fehlt)
            // oder 'vertrag' (Police/Bestaetigung liegt vor). Steuert, ob ein
            // spaeter hochgeladenes Bestaetigungs-Dokument diesen Vertrag
            // ergaenzt statt ein Duplikat anzulegen.
            'stage' => 'nullable|in:'.implode(',', array_keys(Contract::STAGE_LABELS)),
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'cancellation_date' => 'nullable|date',
            'notes' => 'nullable|string',
            // Beitrag + Zahlweise (was zahlt der Kunde, in welchem Rhythmus).
            'premium_amount' => 'nullable|numeric|min:0|max:9999999.99',
            'premium_interval' => 'nullable|in:'.implode(',', Contract::premiumIntervalKeys()),
            'energy.payment_amount' => 'nullable|numeric|min:0',
            'energy.payment_interval' => 'nullable|in:monatlich,vierteljaehrlich,halbjaehrlich,jaehrlich',
            // Energie: MaLo-ID hat 11 Ziffern und ist NICHT die Zählernummer
            'energy.malo_id' => ['nullable', 'regex:/^[0-9]{11}$/'],
            'energy.consumption_kwh' => 'nullable|integer|min:0',
            // Tarifpreise: Arbeitspreis (ct/kWh) und Grundpreis (EUR/Monat).
            'energy.working_price' => 'nullable|numeric|min:0|max:9999.999',
            'energy.base_price' => 'nullable|numeric|min:0|max:99999.99',
            // Vorversorger (bisheriger Lieferant beim Wechsel).
            'energy.previous_provider' => 'nullable|string|max:150',
            'energy.previous_customer_number' => 'nullable|string|max:60',
            // Internet: preisvariabler Tarif + Router + Bonus/Gutschein.
            'internet.tariff' => 'nullable|string|max:255',
            'internet.speed' => 'nullable|string|max:30',
            'internet.upload_speed' => 'nullable|string|max:30',
            'internet.price_initial' => 'nullable|numeric|min:0|max:99999.99',
            'internet.price_initial_months' => 'nullable|integer|min:0|max:60',
            'internet.price_regular' => 'nullable|numeric|min:0|max:99999.99',
            'internet.has_router' => 'nullable|boolean',
            'internet.router_name' => 'nullable|string|max:120',
            'internet.router_price' => 'nullable|numeric|min:0|max:99999.99',
            'internet.bonus_amount' => 'nullable|numeric|min:0|max:99999999.99',
            'internet.voucher_amount' => 'nullable|numeric|min:0|max:99999999.99',
            'internet.setup_fee' => 'nullable|numeric|min:0|max:99999.99',
            'internet.shipping_fee' => 'nullable|numeric|min:0|max:99999.99',
            'internet.min_duration_months' => 'nullable|integer|min:0|max:60',
            // ---- E-Scooter (schlankes Fahrzeug-Detail, eigener Namensraum) ----
            'escooter.license_plate' => 'nullable|string|max:20',
            'escooter.manufacturer' => 'nullable|string|max:255',
            'escooter.model' => 'nullable|string|max:255',
            'escooter.vin' => 'nullable|string|max:30',
            'escooter.has_teilkasko' => 'nullable|boolean',
            // ---- KFZ (Redesign 17.07.2026): alle Kataloge kommen aus dem Model ----
            'vehicle.vehicle_type' => 'nullable|in:'.implode(',', array_keys(ContractVehicleDetail::VEHICLE_TYPES)),
            'vehicle.license_plate' => 'nullable|string|max:20',
            'vehicle.manufacturer' => 'nullable|string|max:255',
            'vehicle.model' => 'nullable|string|max:255',
            'vehicle.vin' => 'nullable|string|max:30',
            'vehicle.hsn' => ['nullable', 'regex:/^[0-9]{4}$/'],
            'vehicle.tsn' => ['nullable', 'regex:/^[A-Za-z0-9]{1,10}$/'],
            'vehicle.first_registration' => 'nullable|date',
            'vehicle.acquisition_date' => 'nullable|date',
            'vehicle.vehicle_condition' => 'nullable|in:'.implode(',', array_keys(ContractVehicleDetail::CONDITIONS)),
            'vehicle.power_kw' => 'nullable|integer|min:1|max:2000',
            'vehicle.fuel_type' => 'nullable|in:'.implode(',', array_keys(ContractVehicleDetail::FUEL_TYPES)),
            'vehicle.transmission' => 'nullable|in:'.implode(',', array_keys(ContractVehicleDetail::TRANSMISSIONS)),
            'vehicle.color' => 'nullable|string|max:40',
            // Deckung: Haftpflicht ist immer enthalten; Vollkasko setzt Teilkasko voraus (wird im Sync erzwungen).
            'vehicle.has_teilkasko' => 'nullable|boolean',
            'vehicle.teilkasko_deductible' => 'nullable|integer|in:'.implode(',', ContractVehicleDetail::TK_DEDUCTIBLES),
            'vehicle.has_vollkasko' => 'nullable|boolean',
            'vehicle.vollkasko_deductible' => 'nullable|integer|in:'.implode(',', ContractVehicleDetail::VK_DEDUCTIBLES),
            'vehicle.extras' => 'nullable|array',
            'vehicle.extras.*' => 'in:'.implode(',', array_keys(ContractVehicleDetail::EXTRAS)),
            'vehicle.driver_groups' => 'nullable|array',
            'vehicle.driver_groups.*' => 'in:'.implode(',', array_keys(ContractVehicleDetail::DRIVER_GROUPS)),
            'vehicle.additional_drivers' => 'nullable|array',
            'vehicle.additional_drivers.*.name' => 'nullable|string|max:120',
            'vehicle.additional_drivers.*.birth_date' => 'nullable|date',
            'vehicle.additional_drivers.*.license_date' => 'nullable|date',
            'vehicle.holder_type' => 'nullable|in:'.implode(',', array_keys(ContractVehicleDetail::HOLDER_TYPES)),
            'vehicle.holder_name' => 'nullable|string|max:255',
            'vehicle.ownership_type' => 'nullable|in:'.implode(',', array_keys(ContractVehicleDetail::OWNERSHIP_TYPES)),
            // Nutzung / Kilometer
            'vehicle.initial_mileage' => 'nullable|integer|min:0|max:5000000',
            'vehicle.current_mileage' => 'nullable|integer|min:0|max:5000000',
            'vehicle.current_mileage_date' => 'nullable|date',
            // Buttons decken die Standardwerte ab; "custom" schaltet das
            // Freifeld fuer Sonderfaelle (8.000, 18.500, 22.500 km ...) frei.
            'vehicle.annual_mileage' => 'nullable|in:custom,'.implode(',', ContractVehicleDetail::ANNUAL_MILEAGE_OPTIONS),
            'vehicle.annual_mileage_custom' => 'nullable|integer|min:1000|max:150000|required_if:vehicle.annual_mileage,custom',

            // Vorversicherung (bisheriger Kfz-Versicherer beim Wechsel).
            'vehicle.previous_insurer' => 'nullable|string|max:120',
            'vehicle.previous_contract_number' => 'nullable|string|max:60',
            'vehicle.previous_insurance_since' => 'nullable|string|max:60',
            'vehicle.previous_insurance_terminated_by_insurer' => 'nullable|in:0,1',
            'vehicle.no_previous_insurance' => 'nullable|boolean',
            // SF-Bezug je Sparte (01.10.2026)
            'vehicle.sf_ref' => 'nullable|array',
            'vehicle.sf_ref.*.reference_type' => 'nullable|in:internal,external',
            'vehicle.sf_ref.*.reference_contract_id' => 'nullable|uuid',
            'vehicle.sf_ref.*.ext_insurer' => 'nullable|string|max:120',
            'vehicle.sf_ref.*.ext_contract_number' => 'nullable|string|max:60',
            'vehicle.sf_ref.*.ext_license_plate' => 'nullable|string|max:20',
            'vehicle.sf_ref.*.ext_sf_class' => 'nullable|in:'.implode(',', ContractVehicleDetail::sfClassKeys()),
            'vehicle.sf_ref.*.create_external' => 'nullable|boolean',
            'vehicle.sf_ref.*.copy_from_liability' => 'nullable|boolean',
            'vehicle.sf_ref.*.holder_relation' => 'nullable|in:'.implode(',', array_keys(VehicleSfReference::HOLDER_RELATIONS)),
            'vehicle.sf_ref.*.holder_name' => 'nullable|string|max:120',
            'vehicle.sf_ref.*.proof_document_id' => 'nullable|uuid',
            'vehicle.sf_ref.*.verified' => 'nullable|boolean',
            'vehicle.sf_ref.*.license_date' => 'nullable|date|before_or_equal:today',
            'vehicle.sf_ref.*.campaign_name' => 'nullable|string|max:120',
            'vehicle.sf_ref.*.note' => 'nullable|string|max:2000',
            // SF-Einstufung (Haftpflicht / Vollkasko getrennt)
            'vehicle.sf_liability_class' => 'nullable|in:'.implode(',', ContractVehicleDetail::sfClassKeys()),
            'vehicle.sf_liability_valid_from' => 'nullable|date',
            'vehicle.sf_liability_type' => 'nullable|in:'.implode(',', array_keys(ContractVehicleDetail::SF_TYPES)),
            'vehicle.sf_liability_special_reason' => 'nullable|in:'.implode(',', array_keys(ContractVehicleDetail::SF_SPECIAL_REASONS)),
            'vehicle.sf_liability_real_class' => 'nullable|in:'.implode(',', ContractVehicleDetail::sfClassKeys()),
            'vehicle.sf_comprehensive_class' => 'nullable|in:'.implode(',', ContractVehicleDetail::sfClassKeys()),
            'vehicle.sf_comprehensive_valid_from' => 'nullable|date',
            'vehicle.sf_comprehensive_type' => 'nullable|in:'.implode(',', array_keys(ContractVehicleDetail::SF_TYPES)),
            'vehicle.sf_comprehensive_special_reason' => 'nullable|in:'.implode(',', array_keys(ContractVehicleDetail::SF_SPECIAL_REASONS)),
            'vehicle.sf_comprehensive_real_class' => 'nullable|in:'.implode(',', ContractVehicleDetail::sfClassKeys()),
            // Schaeden (strukturierte Zeilen, eigene Tabelle)
            'vehicle.claim_rows' => 'nullable|array',
            'vehicle.claim_rows.*.claim_date' => 'nullable|date',
            'vehicle.claim_rows.*.claim_type' => 'nullable|in:'.implode(',', array_keys(VehicleClaim::TYPES)),
            'vehicle.claim_rows.*.damage_amount' => 'nullable|numeric|min:0|max:99999999',
            'vehicle.claim_rows.*.status' => 'nullable|in:'.implode(',', array_keys(VehicleClaim::STATUSES)),
            'vehicle.claim_rows.*.insurer' => 'nullable|string|max:255',
            'vehicle.claim_rows.*.notes' => 'nullable|string|max:2000',
        ], [
            'vehicle.annual_mileage_custom.required_if' => 'Bitte die eigene Jahresfahrleistung in km angeben.',
        ]);
    }

    /**
     * Spartenspezifische Detaildatensätze anlegen/aktualisieren (Spec Teil 4/5).
     * Beim Bearbeiten mit Typwechsel werden verwaiste Detaildaten entfernt.
     */
    private function syncContractDetails(Contract $contract, Request $request): void {
        // KFZ und E-Scooter teilen sich die Fahrzeugtabelle - beide behalten
        // ihr vehicleDetail (sonst wuerde ein Speichern das automatisch aus dem
        // Dokument angelegte E-Scooter-Detail loeschen).
        if (! in_array($contract->type, ['kfz', 'escooter'], true)) { $contract->vehicleDetail()->delete(); }
        if (! $contract->isEnergy())         { $contract->energyDetail()->delete(); }
        if ($contract->type !== 'internet') { $contract->internetDetail()->delete(); }

        if ($contract->type === 'kfz') {
            $this->syncVehicleDetail($contract, $request->input('vehicle', []));
        } elseif ($contract->type === 'escooter') {
            $this->syncEscooterDetail($contract, $request->input('escooter', []));
        } elseif ($contract->isEnergy()) {
            ContractEnergyDetail::updateOrCreate(
                ['contract_id' => $contract->id],
                collect($request->input('energy', []))
                    ->only(['tariff', 'consumption_kwh', 'meter_number', 'customer_number', 'malo_id', 'meter_reading', 'grid_operator', 'metering_operator', 'payment_amount', 'payment_interval', 'working_price', 'base_price', 'previous_provider', 'previous_customer_number'])
                    ->map(fn ($val) => $val === '' ? null : $val)
                    ->all()
            );
        } elseif ($contract->type === 'internet') {
            // has_router ist eine Checkbox - fehlt im Request, wenn nicht gesetzt.
            $internet = collect($request->input('internet', []))
                ->only(['tariff', 'speed', 'upload_speed', 'price_initial', 'price_initial_months', 'price_regular', 'router_name', 'router_price', 'bonus_amount', 'voucher_amount', 'setup_fee', 'shipping_fee', 'min_duration_months'])
                ->map(fn ($val) => $val === '' ? null : $val)
                ->all();
            $internet['has_router'] = $request->boolean('internet.has_router');
            ContractInternetDetail::updateOrCreate(
                ['contract_id' => $contract->id],
                $internet
            );
        }
    }

    /**
     * KFZ-Detail speichern (Redesign 17.07.2026). Erzwingt die Deckungs-
     * Hierarchie (Vollkasko nur mit Teilkasko), filtert Kataloge, pflegt
     * Schaeden als eigene Tabelle, legt km-Ablesungen an und schreibt den
     * SF-Verlauf fort statt ihn zu ueberschreiben.
     */
    private function syncVehicleDetail(Contract $contract, array $v): void {
        $blank = fn ($key) => isset($v[$key]) && $v[$key] !== '' ? $v[$key] : null;

        // Deckung: Haftpflicht ist Pflicht (immer enthalten). Vollkasko ohne
        // Teilkasko ist fachlich unmoeglich -> wird hier hart abgeraeumt.
        $hasTk = ! empty($v['has_teilkasko']);
        $hasVk = $hasTk && ! empty($v['has_vollkasko']);

        // Kataloge serverseitig filtern (Whitelist, Reihenfolge des Katalogs).
        $extras = array_values(array_intersect(array_keys(ContractVehicleDetail::EXTRAS), (array) ($v['extras'] ?? [])));
        $driverGroups = array_values(array_intersect(array_keys(ContractVehicleDetail::DRIVER_GROUPS), (array) ($v['driver_groups'] ?? [])));
        $additionalDrivers = in_array('weitere_fahrer', $driverGroups, true)
            ? collect($v['additional_drivers'] ?? [])
                ->filter(fn ($drv) => ! empty($drv['name']))
                ->map(fn ($drv) => [
                    'name' => trim((string) $drv['name']),
                    'birth_date' => $drv['birth_date'] ?? null,
                    'license_date' => $drv['license_date'] ?? null,
                ])->values()->all()
            : [];

        // SF: Art faellt auf "tatsaechlich" zurueck; Sondereinstufungs-Felder
        // (Grund + tatsaechliche Klasse) nur bei Sondereinstufung speichern.
        $sf = function (string $prefix) use ($blank) {
            $class = $blank($prefix.'_class');
            $type = $class ? ($blank($prefix.'_type') ?: 'tatsaechlich') : null;
            return [
                $prefix.'_class' => $class,
                $prefix.'_valid_from' => $class ? $blank($prefix.'_valid_from') : null,
                $prefix.'_type' => $type,
                $prefix.'_special_reason' => $type === 'sondereinstufung' ? $blank($prefix.'_special_reason') : null,
                $prefix.'_real_class' => $type === 'sondereinstufung' ? $blank($prefix.'_real_class') : null,
            ];
        };
        $sfLiability = $sf('sf_liability');
        $sfComprehensive = $hasVk ? $sf('sf_comprehensive') : [
            'sf_comprehensive_class' => null, 'sf_comprehensive_valid_from' => null,
            'sf_comprehensive_type' => null, 'sf_comprehensive_special_reason' => null,
            'sf_comprehensive_real_class' => null,
        ];

        $noPrev = ! empty($v['no_previous_insurance']);

        $detail = ContractVehicleDetail::updateOrCreate(
            ['contract_id' => $contract->id],
            array_merge([
                'vehicle_type' => $blank('vehicle_type'),
                'license_plate' => $blank('license_plate'),
                'manufacturer' => $blank('manufacturer'),
                'model' => $blank('model'),
                'vin' => $blank('vin'),
                'hsn' => $blank('hsn'),
                'tsn' => $blank('tsn') ? strtoupper($v['tsn']) : null,
                'first_registration' => $blank('first_registration'),
                'acquisition_date' => $blank('acquisition_date'),
                'vehicle_condition' => $blank('vehicle_condition'),
                'power_kw' => $blank('power_kw'),
                'fuel_type' => $blank('fuel_type'),
                'transmission' => $blank('transmission'),
                'color' => $blank('color'),
                'has_teilkasko' => $hasTk,
                'teilkasko_deductible' => $hasTk ? $blank('teilkasko_deductible') : null,
                'has_vollkasko' => $hasVk,
                'vollkasko_deductible' => $hasVk ? $blank('vollkasko_deductible') : null,
                'extras' => $extras,
                'driver_groups' => $driverGroups,
                'additional_drivers' => $additionalDrivers,
                'holder_type' => $blank('holder_type'),
                'holder_name' => ($blank('holder_type') === 'abweichender_halter') ? $blank('holder_name') : null,
                'ownership_type' => $blank('ownership_type'),
                'initial_mileage' => $blank('initial_mileage'),
                // "custom" = Freifeld-Wert (Sonderfaelle wie 18.500 km/Jahr).
                'annual_mileage' => $blank('annual_mileage') === 'custom' ? $blank('annual_mileage_custom') : $blank('annual_mileage'),
                // Vorversicherung: leerer Radio ("") = unbekannt (null).
                // "Keine Vorversicherung" (Neuzulassung/Ersterwerb) leert die
                // Felder - sonst stuende dort weiter eine Angabe, die es fuer
                // dieses Fahrzeug nicht gibt.
                'no_previous_insurance' => $noPrev,
                'previous_insurer' => $noPrev ? null : $blank('previous_insurer'),
                'previous_contract_number' => $noPrev ? null : $blank('previous_contract_number'),
                'previous_insurance_since' => $noPrev ? null : $blank('previous_insurance_since'),
                'previous_insurance_terminated_by_insurer' => ($noPrev || $blank('previous_insurance_terminated_by_insurer') === null)
                    ? null : ($v['previous_insurance_terminated_by_insurer'] === '1'),
            ], $sfLiability, $sfComprehensive)
        );

        // Schaeden: eingereichte Zeilen ersetzen den Bestand vollstaendig
        // (das Formular zeigt immer alle Schaeden inkl. Loeschen-Knopf).
        $detail->claims()->delete();
        foreach ((array) ($v['claim_rows'] ?? []) as $row) {
            if (! is_array($row)) continue;
            $hasContent = collect(['claim_date', 'claim_type', 'damage_amount', 'insurer', 'notes'])
                ->contains(fn ($key) => isset($row[$key]) && $row[$key] !== '');
            if (! $hasContent) continue;
            $detail->claims()->create([
                'claim_date' => $row['claim_date'] ?? null,
                'claim_type' => ($row['claim_type'] ?? '') !== '' ? $row['claim_type'] : null,
                'damage_amount' => ($row['damage_amount'] ?? '') !== '' ? $row['damage_amount'] : null,
                'status' => ($row['status'] ?? '') !== '' ? $row['status'] : null,
                'insurer' => ($row['insurer'] ?? '') !== '' ? $row['insurer'] : null,
                'notes' => ($row['notes'] ?? '') !== '' ? $row['notes'] : null,
            ]);
        }

        // Aktueller Kilometerstand: nur bei neuem Wert eine Ablesung anlegen -
        // die Historie bleibt vollstaendig erhalten.
        if ($blank('current_mileage') !== null) {
            $mileage = (int) $v['current_mileage'];
            $date = $blank('current_mileage_date') ?: now()->toDateString();
            $latest = $detail->mileageReadings()->first();
            if (! $latest || (int) $latest->mileage !== $mileage || $latest->reading_date->toDateString() !== $date) {
                $detail->mileageReadings()->create([
                    'mileage' => $mileage,
                    'reading_date' => $date,
                    'source' => 'staff',
                    'created_by' => auth()->user()?->name,
                ]);
            }
        }

        // Begruendung der Sondereinstufung (Bezugsfahrzeug usw.) - einziger
        // Schreibweg ist der Service (Selbst-/Kreisbezug, Protokoll).
        $this->sfNotes = app(SfReferenceService::class)
            ->sync($contract, $detail, (array) ($v['sf_ref'] ?? []), auth()->user());
        $detail->load('sfReferences');

        // SF-Verlauf fortschreiben (Teilkasko hat keine SF-Klasse).
        $this->syncSfHistory($detail, 'haftpflicht', $sfLiability['sf_liability_class'], $sfLiability['sf_liability_valid_from']);
        $this->syncSfHistory($detail, 'vollkasko', $sfComprehensive['sf_comprehensive_class'], $sfComprehensive['sf_comprehensive_valid_from']);
    }

    /**
     * E-Scooter-Detail speichern: schlanker als KFZ - nur Kennzeichen,
     * Hersteller/Modell und Fahrgestellnummer sowie die Deckung. E-Scooter
     * haben nur Haftpflicht oder Teilkasko (nie Vollkasko), keine SF-Klasse,
     * keine Kilometer und keine Selbstbeteiligungsstufen. Nutzt dieselbe
     * Fahrzeugtabelle wie KFZ (Fahrzeugtyp = escooter).
     */
    private function syncEscooterDetail(Contract $contract, array $v): void {
        $blank = fn ($key) => isset($v[$key]) && $v[$key] !== '' ? $v[$key] : null;

        ContractVehicleDetail::updateOrCreate(
            ['contract_id' => $contract->id],
            [
                'vehicle_type' => 'escooter',
                'license_plate' => $blank('license_plate') ? mb_strtoupper($v['license_plate']) : null,
                'manufacturer' => $blank('manufacturer'),
                'model' => $blank('model'),
                'vin' => $blank('vin') ? strtoupper($v['vin']) : null,
                'has_teilkasko' => ! empty($v['has_teilkasko']),
                'teilkasko_deductible' => null,
                'has_vollkasko' => false,
                'vollkasko_deductible' => null,
            ]
        );
    }

    /**
     * SF-Verlauf je Sparte: Klassenwechsel schliesst den offenen Eintrag
     * (gueltig bis = Vortag der neuen Einstufung) und legt einen neuen an.
     * Gleiche Klasse mit korrigiertem Datum aktualisiert nur das gueltig-ab.
     */
    private function syncSfHistory(ContractVehicleDetail $detail, string $branch, ?string $class, ?string $validFrom): void {
        $open = $detail->sfHistory()->where('branch', $branch)->whereNull('valid_until')->orderByDesc('created_at')->first();
        // Grund und Bezug stehen an JEDEM Verlaufseintrag - aendert sich der
        // Erstwagen spaeter, bleibt nachvollziehbar, worauf die damalige
        // Einstufung beruhte.
        $ref = $detail->sfReference($branch);
        $why = [
            'special_reason' => $class ? $detail->sfSpecialReason($branch) : null,
            'reference_label' => $class && $ref ? $ref->referenceText() : null,
            'reference_contract_id' => $class && $ref ? $ref->reference_contract_id : null,
        ];

        if (! $class) {
            if ($open) $open->update(['valid_until' => now()->toDateString()]);
            return;
        }
        if ($open && $open->sf_class === $class) {
            $openFrom = $open->valid_from?->toDateString();
            $patch = [];
            if ($openFrom !== $validFrom) $patch['valid_from'] = $validFrom;
            foreach ($why as $key => $value) {
                if ($open->{$key} !== $value) $patch[$key] = $value;
            }
            if ($patch) $open->update($patch);
            return;
        }
        if ($open) {
            $open->update(['valid_until' => $validFrom
                ? Carbon::parse($validFrom)->subDay()->toDateString()
                : now()->toDateString()]);
        }
        $detail->sfHistory()->create(['branch' => $branch, 'sf_class' => $class, 'valid_from' => $validFrom, 'valid_until' => null] + $why);
    }
}
