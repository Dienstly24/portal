@php $p = $prefix ?? ''; @endphp
<div class="field"><label>{{ __('Adresstyp *') }}</label>
    <select name="type" id="{{ $p }}type" required>
        @foreach(\App\Models\CustomerAddress::TYPES as $key => $label)
        <option value="{{ $key }}">{{ $label }}</option>
        @endforeach
    </select>
</div>
<div class="field"><label>{{ __('Straße & Hausnummer *') }}</label><input type="text" name="street" id="{{ $p }}street" required maxlength="255"></div>
<div class="grid-2">
    <div class="field"><label>{{ __('PLZ *') }}</label><input type="text" name="zip" id="{{ $p }}zip" required maxlength="10"></div>
    <div class="field"><label>{{ __('Stadt *') }}</label><input type="text" name="city" id="{{ $p }}city" required maxlength="100"></div>
</div>
<div class="field"><label>{{ __('Land') }}</label><input type="text" name="country" id="{{ $p }}country" value="Deutschland" maxlength="100"></div>
<div class="field">
    <label>{{ __('Gültig ab') }}</label>
    <input type="date" name="effective_from" id="{{ $p }}effective_from">
    <p style="font-size:12px;color:var(--ink-soft);margin-top:4px;">{{ __('Seit wann bzw. ab wann wohnen Sie unter dieser Anschrift?') }}</p>
</div>

{{-- Pflicht-Nachweis: eine Adressaenderung steuert Post, Policen und
     Beitraege - deshalb nehmen wir sie nur mit Beleg an. --}}
<div style="border:1px dashed var(--line);border-radius:10px;padding:14px;margin:14px 0;background:var(--canvas);">
    <div style="font-weight:700;font-size:13.5px;margin-bottom:6px;">{{ __('📎 Nachweis der Anschrift *') }}</div>
    <div class="field">
        <label style="font-size:12.5px;">{{ __('Was laden Sie hoch?') }}</label>
        <select name="proof_kind" id="{{ $p }}proof_kind">
            <option value="meldebescheinigung">{{ __('Meldebescheinigung') }}</option>
            <option value="id_front">{{ __('Ausweis mit neuer Anschrift (Vorderseite)') }}</option>
            <option value="other">{{ __('Anderer Nachweis (z. B. Mietvertrag)') }}</option>
        </select>
    </div>
    <div class="field"><input type="file" name="proof" id="{{ $p }}proof" required accept=".pdf,.jpg,.jpeg,.png,.webp"></div>
    <div class="field"><label style="font-size:12.5px;">{{ __('Rückseite / zweites Dokument (optional)') }}</label><input type="file" name="proof_back" id="{{ $p }}proof_back" accept=".pdf,.jpg,.jpeg,.png,.webp"></div>
    <p style="font-size:11.5px;color:var(--ink-soft);">{{ __('Erlaubt: PDF oder Foto (JPG, PNG, WEBP), max. 10 MB je Datei. Straße, PLZ und Ort müssen lesbar sein.') }}</p>
</div>
@error('proof')<div class="alert-error">{{ $message }}</div>@enderror
