<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ScopesCustomerAccess;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Contract;
use App\Models\ContractEnergyDetail;
use App\Models\ContractInternetDetail;
use App\Models\ContractVehicleDetail;
use App\Models\Customer;
use App\Models\Document;
use App\Models\MeterReading;
use App\Models\VehicleClaim;
use App\Services\CommissionImport\CommissionAuditLogger;
use App\Services\ContractSwitchService;
use App\Services\Energy\MeterReadingService;
use App\Services\VehicleOverlapGuard;
use App\Services\Vermittler\VermittlerLinkService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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

        $basis = fn () => Contract::query()
            ->when($ids !== null, fn ($q) => $q->whereIn('customer_id', $ids))
            ->search($suche);

        // Zaehler je Gruppe als reine COUNT-Abfragen - es wird keine einzige
        // Zeile geladen, nur gezaehlt. Sie folgen der Suche, damit die Zahl
        // in den Reitern zum Gezeigten passt.
        $zaehler = [
            Contract::GROUP_ACTIVE => $basis()->statusGroup(Contract::GROUP_ACTIVE)->count(),
            Contract::GROUP_PENDING => $basis()->statusGroup(Contract::GROUP_PENDING)->count(),
            Contract::GROUP_HISTORY => $basis()->statusGroup(Contract::GROUP_HISTORY)->count(),
            'alle' => $basis()->count(),
        ];

        $contracts = $basis()
            ->with('customer.user')
            ->statusGroup($gruppe === 'alle' ? null : $gruppe)
            ->latest()
            ->paginate(50)
            ->withQueryString();

        return view('admin.contracts', compact('contracts', 'gruppe', 'suche', 'zaehler'));
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
        return view('admin.contract_create', compact('customer'));
    }

    public function contractStore(Request $request, $customerId) {
        $this->authorizeCustomerAccess($customerId);
        $this->validateContract($request);

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
        ]);

        $this->syncContractDetails($contract, $request);
        // Bei der Neuanlage erfasste Vermittler-Kennungen gehen sofort in die
        // Historie - und eine bereits importierte, bisher unzugeordnete
        // Abrechnungszeile findet damit ihren Vertrag.
        app(VermittlerLinkService::class)
            ->recordContractEdit($contract, ['reference_number' => null, 'vermittler_id' => null], auth()->id());

        return redirect()->route('admin.customer', $customerId)
            ->with('success', 'Vertrag erfolgreich hinzugefügt.'.$switchNote);
    }

    public function contractEdit($id) {
        $contract = Contract::with(['vehicleDetail.claims', 'vehicleDetail.mileageReadings', 'vehicleDetail.sfHistory', 'energyDetail.meterReadings', 'internetDetail', 'customer.user', 'revisions.changedBy'])->findOrFail($id);
        $this->authorizeCustomerAccess($contract->customer_id);
        return view('admin.contract_edit', compact('contract'));
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
        abort_unless($detail, 404);

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
        $contract = Contract::findOrFail($id);
        $this->authorizeCustomerAccess($contract->customer_id);
        $this->validateContract($request, $contract->id);
        if ($conflictError = $this->vehicleOverlapError($request, (string) $contract->customer_id, $contract->id)) {
            return back()->withErrors(['vehicle_overlap' => $conflictError])->withInput();
        }

        // Vermittler-Kennungen VOR der Aenderung merken: die Historie soll
        // zeigen, wann eine Referenz-Nr./ID von Hand kam (20.08.2026).
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
        ]);

        $this->syncContractDetails($contract, $request);
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

        return redirect()->route('admin.customer', $contract->customer_id)->with('success', 'Vertrag aktualisiert.');
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
                'previous_insurer' => $blank('previous_insurer'),
                'previous_contract_number' => $blank('previous_contract_number'),
                'previous_insurance_since' => $blank('previous_insurance_since'),
                'previous_insurance_terminated_by_insurer' => $blank('previous_insurance_terminated_by_insurer') === null
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

        if (! $class) {
            if ($open) $open->update(['valid_until' => now()->toDateString()]);
            return;
        }
        if ($open && $open->sf_class === $class) {
            $openFrom = $open->valid_from?->toDateString();
            if ($openFrom !== $validFrom) $open->update(['valid_from' => $validFrom]);
            return;
        }
        if ($open) {
            $open->update(['valid_until' => $validFrom
                ? Carbon::parse($validFrom)->subDay()->toDateString()
                : now()->toDateString()]);
        }
        $detail->sfHistory()->create(['branch' => $branch, 'sf_class' => $class, 'valid_from' => $validFrom, 'valid_until' => null]);
    }
}
