@php
    $fTypes = ['text' => 'Text', 'tel' => 'Telefon', 'email' => 'E-Mail', 'number' => 'Zahl', 'select' => 'Auswahl', 'textarea' => 'Mehrzeilig'];
@endphp
<div class="field-row" style="border:1px solid var(--line);border-radius:10px;padding:12px;margin-bottom:12px;">
    <div class="grid-2">
        <div class="field" style="margin-bottom:8px;"><label>Feldname (DE)</label>
            <input type="text" name="field_label_de[]" maxlength="120" value="{{ $label_de ?? '' }}" placeholder="z.B. Fahrzeug / Kennzeichen"></div>
        <div class="field" style="margin-bottom:8px;"><label>Feldname (AR)</label>
            <input type="text" name="field_label_ar[]" maxlength="120" dir="rtl" value="{{ $label_ar ?? '' }}"></div>
    </div>
    <div class="grid-2">
        <div class="field" style="margin-bottom:8px;"><label>Typ</label>
            <select name="field_type[]" style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                @foreach($fTypes as $val => $lbl)
                    <option value="{{ $val }}" @selected(($type ?? 'text') === $val)>{{ $lbl }}</option>
                @endforeach
            </select>
        </div>
        <div class="field" style="margin-bottom:8px;"><label>Pflichtfeld?</label>
            <select name="field_required[]" style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                <option value="0" @selected(!($required ?? false))>Nein</option>
                <option value="1" @selected($required ?? false)>Ja</option>
            </select>
        </div>
    </div>
    <div class="grid-2">
        <div class="field" style="margin-bottom:8px;"><label>Auswahl-Optionen (DE, mit Komma) – nur bei Typ „Auswahl“</label>
            <input type="text" name="field_options_de[]" value="{{ $options_de ?? '' }}" placeholder="Haftpflicht, Teilkasko, Vollkasko"></div>
        <div class="field" style="margin-bottom:8px;"><label>Auswahl-Optionen (AR, mit Komma)</label>
            <input type="text" name="field_options_ar[]" dir="rtl" value="{{ $options_ar ?? '' }}"></div>
    </div>
    <button type="button" class="btn btn-ghost" style="padding:5px 12px;color:#A32D2D;" onclick="removeFieldRow(this)">Entfernen</button>
</div>
