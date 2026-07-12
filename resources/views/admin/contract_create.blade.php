@extends('layouts.admin')
@section('content')
<div class="page-title">Neuer Vertrag</div>
<div class="page-sub">Für: {{ $customer->user?->name }}</div>
<div class="card">
    <form method="POST" action="{{ route('admin.contract.store', $customer->id) }}">
        @csrf
        <div class="grid-2">
            <div class="field"><label>Versicherungsart</label>
                <select name="type" id="contract-type" onchange="toggleBranchSections()" required>
                    <option value="kfz">Kfz-Versicherung</option>
                    <option value="krankenversicherung">Krankenversicherung</option>
                    <option value="internet">Internet & Mobilfunk</option>
                    <option value="strom_gas">Strom & Gas</option>
                    <option value="andere">Andere</option>
                </select>
            </div>
            <div class="field"><label>Versicherer / Anbieter</label><input type="text" name="insurer" required></div>
        </div>
        <div class="grid-2">
            <div class="field"><label>Status</label>
                <select name="status" required>
                    <option value="active">Aktiv</option>
                    <option value="pending">In Bearbeitung</option>
                    <option value="cancelled">Gekündigt</option>
                    <option value="expired">Abgelaufen</option>
                </select>
            </div>
            <div class="field"><label>Startdatum</label><input type="date" name="start_date"></div>
        </div>
        <div class="grid-2">
            <div class="field"><label>Enddatum</label><input type="date" name="end_date"></div>
            <div class="field"><label>Kündigungsdatum</label><input type="date" name="cancellation_date"></div>
            <div class="field"><label>Notizen</label><input type="text" name="notes"></div>
        </div>
        <div class="field"><label>Vertragsnummer (leer = automatisch)</label><input type="text" name="contract_number" maxlength="100"></div>

        {{-- ===== KFZ (Spec Teil 4) ===== --}}
        <div id="section-kfz" class="branch-section" style="display:none;border:1px solid var(--line);border-radius:10px;padding:16px;margin-bottom:16px;">
            <div class="card-title" style="font-size:14px;">🚗 Fahrzeug & Einstufung</div>
            <div class="grid-2">
                <div class="field"><label>Kennzeichen</label><input type="text" name="vehicle[license_plate]" maxlength="20" placeholder="HH-AB 1234"></div>
                <div class="field"><label>Fahrzeugtyp</label><input type="text" name="vehicle[vehicle_type]" maxlength="50" placeholder="PKW"></div>
            </div>
            <div class="grid-2">
                <div class="field"><label>Hersteller</label><input type="text" name="vehicle[manufacturer]" placeholder="VW"></div>
                <div class="field"><label>Modell</label><input type="text" name="vehicle[model]" placeholder="Golf VIII"></div>
            </div>
            <div class="grid-2">
                <div class="field"><label>FIN / Fahrgestellnummer</label><input type="text" name="vehicle[vin]" maxlength="30"></div>
                <div class="field"><label>Erstzulassung</label><input type="date" name="vehicle[first_registration]"></div>
            </div>
            <div class="grid-2">
                <div class="field"><label>SF-Klasse Haftpflicht</label><input type="text" name="vehicle[sf_liability_class]" maxlength="10" placeholder="SF 12"></div>
                <div class="field"><label>SF-Jahr Haftpflicht</label><input type="number" name="vehicle[sf_liability_year]" min="1950" max="2100"></div>
            </div>
            <div class="grid-2">
                <div class="field"><label>SF-Klasse Vollkasko</label><input type="text" name="vehicle[sf_comprehensive_class]" maxlength="10" placeholder="SF 10"></div>
                <div class="field"><label>SF-Jahr Vollkasko</label><input type="number" name="vehicle[sf_comprehensive_year]" min="1950" max="2100"></div>
            </div>
            <div class="field">
                <label style="display:flex;align-items:center;gap:8px;"><input type="checkbox" id="has-claims" onchange="document.getElementById('claims-area').style.display=this.checked?'block':'none'"> Schäden vorhanden</label>
            </div>
            <div id="claims-area" style="display:none;">
                <div id="claims-list"></div>
                <button type="button" class="btn btn-ghost" style="font-size:12.5px;" onclick="addClaimRow()">+ Schaden hinzufügen</button>
            </div>
        </div>

        {{-- ===== Energie (Spec Teil 5) ===== --}}
        <div id="section-strom_gas" class="branch-section" style="display:none;border:1px solid var(--line);border-radius:10px;padding:16px;margin-bottom:16px;">
            <div class="card-title" style="font-size:14px;">⚡ Energievertrag</div>
            <div class="grid-2">
                <div class="field"><label>Tarif</label><input type="text" name="energy[tariff]"></div>
                <div class="field"><label>Verbrauch (kWh/Jahr)</label><input type="number" name="energy[consumption_kwh]" min="0"></div>
            </div>
            <div class="grid-2">
                <div class="field"><label>Zählernummer</label><input type="text" name="energy[meter_number]" maxlength="60"></div>
                <div class="field"><label>Marktlokations-ID (MaLo-ID, 11 Ziffern)</label><input type="text" name="energy[malo_id]" maxlength="11" pattern="[0-9]{11}" placeholder="Nicht die Zählernummer!"></div>
            </div>
            <div class="grid-2">
                <div class="field"><label>Zählerstand (optional)</label><input type="text" name="energy[meter_reading]" maxlength="30"></div>
                <div class="field"><label>Netzbetreiber (optional)</label><input type="text" name="energy[grid_operator]"></div>
            </div>
            <div class="field"><label>Messstellenbetreiber (optional)</label><input type="text" name="energy[metering_operator]"></div>
            <div class="grid-2">
                <div class="field"><label>Abschlag (€)</label><input type="number" step="0.01" name="energy[payment_amount]" min="0"></div>
                <div class="field"><label>Zahlungsintervall</label>
                    <select name="energy[payment_interval]">
                        <option value="">—</option>
                        <option value="monatlich">Monatlich</option>
                        <option value="vierteljaehrlich">Vierteljährlich</option>
                        <option value="halbjaehrlich">Halbjährlich</option>
                        <option value="jaehrlich">Jährlich</option>
                    </select>
                </div>
            </div>
        </div>

        {{-- ===== Krankenversicherung: GKV/PKV steuert die Wechsel-Erinnerungen
             (nur GKV wird erinnert, §175 SGB V - Verbesserungsplan Paket C) ===== --}}
        <div id="section-krankenversicherung" class="branch-section" style="display:none;border:1px solid var(--line);border-radius:10px;padding:16px;margin-bottom:16px;">
            <div class="card-title" style="font-size:14px;">🏥 Krankenversicherung</div>
            <div class="field"><label>Art</label>
                <select name="subtype">
                    <option value="">— bitte wählen —</option>
                    <option value="gkv">Gesetzlich (GKV) – erhält Wechsel-Erinnerung nach 12 Monaten</option>
                    <option value="pkv">Privat (PKV) – keine Wechsel-Erinnerung</option>
                </select>
            </div>
        </div>

        {{-- ===== Internet (Spec Teil 5) ===== --}}
        <div id="section-internet" class="branch-section" style="display:none;border:1px solid var(--line);border-radius:10px;padding:16px;margin-bottom:16px;">
            <div class="card-title" style="font-size:14px;">📶 Internetvertrag</div>
            <div class="grid-2">
                <div class="field"><label>Tarif</label><input type="text" name="internet[tariff]"></div>
                <div class="field"><label>Geschwindigkeit</label><input type="text" name="internet[speed]" maxlength="30" placeholder="250 Mbit/s"></div>
            </div>
        </div>

        <script>
        function toggleBranchSections() {
            const type = document.getElementById('contract-type').value;
            document.querySelectorAll('.branch-section').forEach(el => el.style.display = 'none');
            const active = document.getElementById('section-' + type);
            if (active) active.style.display = 'block';
        }
        let claimIndex = 0;
        function addClaimRow() {
            const wrap = document.createElement('div');
            wrap.style.cssText = 'display:flex;gap:8px;margin-bottom:8px;align-items:center;';
            wrap.innerHTML = `
                <input type="number" name="vehicle[claims][${claimIndex}][month]" min="1" max="12" placeholder="Monat" style="width:90px;padding:8px;border:1px solid var(--line);border-radius:8px;">
                <input type="number" name="vehicle[claims][${claimIndex}][year]" min="1990" max="2100" placeholder="Jahr" style="width:100px;padding:8px;border:1px solid var(--line);border-radius:8px;">
                <select name="vehicle[claims][${claimIndex}][type]" style="padding:8px;border:1px solid var(--line);border-radius:8px;">
                    <option value="haftpflicht">Haftpflicht</option>
                    <option value="vollkasko">Vollkasko</option>
                    <option value="teilkasko">Teilkasko</option>
                </select>
                <button type="button" onclick="this.parentElement.remove()" style="border:none;background:none;cursor:pointer;">✕</button>`;
            document.getElementById('claims-list').appendChild(wrap);
            claimIndex++;
        }
        document.addEventListener('DOMContentLoaded', toggleBranchSections);
        </script>

        <div style="display:flex;gap:10px;">
            <button type="submit" class="btn btn-primary">Vertrag speichern</button>
            <a href="{{ route('admin.customer', $customer->id) }}" class="btn btn-ghost">Abbrechen</a>
        </div>
    </form>
</div>
@endsection
