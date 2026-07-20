@php $p = $prefix ?? ''; @endphp
<div class="field"><label>{{ __('Adresstyp') }} *</label>
    <select name="type" id="{{ $p }}type" required>
        @foreach(\App\Models\CustomerAddress::TYPES as $key => $label)
        <option value="{{ $key }}">{{ __($label) }}</option>
        @endforeach
    </select>
</div>
<div class="field"><label>{{ __('Straße & Hausnummer') }} *</label><input type="text" name="street" id="{{ $p }}street" required maxlength="255"></div>
<div class="grid-2">
    <div class="field"><label>{{ __('PLZ') }} *</label><input type="text" name="zip" id="{{ $p }}zip" required maxlength="10"></div>
    <div class="field"><label>{{ __('Stadt') }} *</label><input type="text" name="city" id="{{ $p }}city" required maxlength="100"></div>
</div>
<div class="field"><label>{{ __('Land') }}</label><input type="text" name="country" id="{{ $p }}country" value="Deutschland" maxlength="100"></div>
