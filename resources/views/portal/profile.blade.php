@extends('layouts.portal')
@section('content')
<div class="page-title">Meine Daten</div>
<div class="page-sub">Änderungen werden von unserem Team geprüft.</div>
@if($pending > 0)<div class="notice">Sie haben {{ $pending }} Änderung(en) in Prüfung.</div>@endif
<div class="card">
    <form method="POST" action="{{ route('portal.profile.update') }}">
        @csrf
        <div class="grid-2">
            <div class="field"><label>Adresse</label><input type="text" name="address" value="{{ $customer?->address }}"></div>
            <div class="field"><label>Telefon</label><input type="text" name="phone" value="{{ $customer?->phone }}"></div>
        </div>
        <div class="grid-2">
            <div class="field"><label>IBAN</label><input type="text" name="iban" value="{{ $customer?->iban }}"></div>
            <div class="field"><label>Geschlecht</label>
                <select name="gender">
                    <option value="">— Bitte wählen —</option>
                    @foreach(\App\Models\Customer::GENDERS as $key => $label)
                    <option value="{{ $key }}" {{ $customer?->gender === $key ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field"><label>Familienstand</label>
                <select name="marital_status">
                    <option value="single" {{ $customer?->marital_status === 'single' ? 'selected' : '' }}>Ledig</option>
                    <option value="married" {{ $customer?->marital_status === 'married' ? 'selected' : '' }}>Verheiratet</option>
                    <option value="divorced" {{ $customer?->marital_status === 'divorced' ? 'selected' : '' }}>Geschieden</option>
                    <option value="widowed" {{ $customer?->marital_status === 'widowed' ? 'selected' : '' }}>Verwitwet</option>
                </select>
            </div>
        </div>
        <button type="submit" class="btn btn-primary">Änderung einreichen</button>
    </form>
</div>
@endsection
