{{-- Formularul fișei, generat din structura șablonului. Câmpurile se trimit ca values[<cheie>]. --}}
@foreach ($schema['sections'] ?? [] as $section)
    <section class="med-section" aria-labelledby="sec-{{ $section['key'] }}">
        <h3 id="sec-{{ $section['key'] }}">{{ $section['title'] }}</h3>
        <div class="form-grid">
            @foreach ($section['fields'] as $field)
                @php
                    $key = $field['key'];
                    $name = 'values['.$key.']';
                    $id = 'v_'.$key;
                    $value = old('values.'.$key, $values[$key] ?? null);
                    $error = $errors->first('values.'.$key);
                    $full = in_array($field['type'], ['textarea', 'table', 'scale'], true);
                @endphp
                @if ($field['type'] === 'checkbox')
                    <div class="field">
                        <input type="hidden" name="{{ $name }}" value="0">
                        <label class="check" for="{{ $id }}">
                            <input type="checkbox" id="{{ $id }}" name="{{ $name }}" value="1" @checked((bool) $value)>
                            <span>{{ $field['label'] }}</span>
                        </label>
                        @if ($field['help'])<span class="field__hint">{{ $field['help'] }}</span>@endif
                    </div>
                @elseif ($field['type'] === 'scale')
                    <fieldset class="field field--full">
                        <legend class="field__label">{{ $field['label'] }}@if ($field['required'])<span class="req" aria-hidden="true">*</span>@endif</legend>
                        <div class="med-scale">
                            <label><input type="radio" name="{{ $name }}" value="" @checked($value === null || $value === '')><span>—</span></label>
                            @for ($i = $field['min']; $i <= $field['max']; $i++)
                                <label><input type="radio" name="{{ $name }}" value="{{ $i }}" @checked((string) $value === (string) $i)><span>{{ $i }}</span></label>
                            @endfor
                        </div>
                        @if ($field['help'])<span class="field__hint">{{ $field['help'] }}</span>@endif
                        @if ($error)<span class="field__error">{{ $error }}</span>@endif
                    </fieldset>
                @elseif ($field['type'] === 'table')
                    <fieldset class="field field--full">
                        <legend class="field__label">{{ $field['label'] }}@if ($field['required'])<span class="req" aria-hidden="true">*</span>@endif</legend>
                        <div class="table-wrap">
                            <table class="med-table">
                                <thead><tr>@foreach ($field['columns'] as $col)<th scope="col">{{ $col }}</th>@endforeach</tr></thead>
                                <tbody>
                                @for ($r = 0; $r < $field['rows']; $r++)
                                    <tr>
                                        @foreach ($field['columns'] as $ci => $col)
                                            <td><input type="text" name="{{ $name }}[{{ $r }}][{{ $ci }}]" value="{{ $value[$r][$ci] ?? '' }}" aria-label="{{ $col }}, rândul {{ $r + 1 }}"></td>
                                        @endforeach
                                    </tr>
                                @endfor
                                </tbody>
                            </table>
                        </div>
                        @if ($field['help'])<span class="field__hint">{{ $field['help'] }}</span>@endif
                    </fieldset>
                @else
                    <div class="field {{ $full ? 'field--full' : '' }}">
                        <label class="field__label" for="{{ $id }}">{{ $field['label'] }}@if ($field['type'] === 'number' && ($field['unit'] ?? null)) ({{ $field['unit'] }})@endif @if ($field['required'])<span class="req" aria-hidden="true">*</span>@endif</label>
                        @if ($field['type'] === 'textarea')
                            <textarea id="{{ $id }}" name="{{ $name }}" rows="5" @if ($error) aria-invalid="true" @endif>{{ $value }}</textarea>
                        @elseif ($field['type'] === 'select')
                            <select id="{{ $id }}" name="{{ $name }}" @if ($error) aria-invalid="true" @endif>
                                <option value="">—</option>
                                @foreach ($field['options'] as $opt)
                                    <option value="{{ $opt }}" @selected((string) $value === (string) $opt)>{{ $opt }}</option>
                                @endforeach
                            </select>
                        @else
                            <input id="{{ $id }}" name="{{ $name }}" type="{{ $field['type'] === 'number' ? 'text' : $field['type'] }}" @if ($field['type'] === 'number') inputmode="decimal" @endif value="{{ is_float($value) ? rtrim(rtrim(number_format($value, 4, ',', ''), '0'), ',') : $value }}" @if ($error) aria-invalid="true" @endif>
                        @endif
                        @if ($field['help'])<span class="field__hint">{{ $field['help'] }}</span>@endif
                        @if ($error)<span class="field__error">{{ $error }}</span>@endif
                    </div>
                @endif
            @endforeach
        </div>
    </section>
@endforeach
