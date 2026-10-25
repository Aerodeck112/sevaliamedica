{{-- Program săptămânal: hours[1..7][0][from|to]; un interval pe zi (suficient pentru programul public). --}}
@php $days = [1 => 'Luni', 2 => 'Marți', 3 => 'Miercuri', 4 => 'Joi', 5 => 'Vineri', 6 => 'Sâmbătă', 7 => 'Duminică']; @endphp
<fieldset class="field field--full">
    <legend class="field__label">{{ $field->label }}</legend>
    <div class="table-wrap">
        <table class="table" style="max-width:520px">
            <thead><tr><th>Zi</th><th>De la</th><th>Până la</th></tr></thead>
            <tbody>
            @foreach ($days as $d => $label)
                @php $slot = $value[$d][0] ?? $value[(string) $d][0] ?? null; @endphp
                <tr>
                    <td>{{ $label }}</td>
                    <td><input type="time" name="{{ $field->name }}[{{ $d }}][0][0]" value="{{ $slot[0] ?? $slot['from'] ?? '' }}" aria-label="{{ $label }} de la"></td>
                    <td><input type="time" name="{{ $field->name }}[{{ $d }}][0][1]" value="{{ $slot[1] ?? $slot['to'] ?? '' }}" aria-label="{{ $label }} până la"></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <span class="field__hint">Lăsați gol pentru „Închis”.</span>
</fieldset>
