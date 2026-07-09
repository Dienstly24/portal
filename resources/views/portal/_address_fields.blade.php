@php $p = $prefix ?? ''; @endphp
<div class="field"><label>Adresstyp *</label>
    <select name="type" id="{{ $p }}type" required>
        @foreach(\App\Models\CustomerAddress::TYPES as $key => $label)
        <option value="{{ $key }}">{{ $label }}</option>
        @endforeach
    </select>
</div>
<div class="field"><label>Straße & Hausnummer *</label><input type="text" name="street" id="{{ $p }}street" required maxlength="255"></div>
<div class="grid-2">
    <div class="field"><label>PLZ *</label><input type="text" name="zip" id="{{ $p }}zip" required maxlength="10"></div>
    <div class="field"><label>Stadt *</label><input type="text" name="city" id="{{ $p }}city" required maxlength="100"></div>
</div>
<div class="field"><label>Land</label><input type="text" name="country" id="{{ $p }}country" value="Deutschland" maxlength="100"></div>
