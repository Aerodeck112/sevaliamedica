{{-- Afișarea (doar citire) a valorilor unei fișe. --}}
@foreach ($schema['sections'] ?? [] as $section)
    <section class="med-section">
        <h3>{{ $section['title'] }}</h3>
        <dl class="med-values">
            @foreach ($section['fields'] as $field)
                @php $value = $values[$field['key']] ?? null; @endphp
                <dt>{{ $field['label'] }}</dt>
                @switch($field['type'])
                    @case('checkbox')
                        <dd>{{ $value ? 'da' : 'nu' }}</dd>
                        @break
                    @case('number')
                        <dd class="{{ $value === null ? 'is-empty' : '' }}">{{ $value === null ? 'necompletat' : rtrim(rtrim(number_format($value, 4, ',', '.'), '0'), ',').(($field['unit'] ?? null) ? ' '.$field['unit'] : '') }}</dd>
                        @break
                    @case('date')
                        <dd class="{{ $value === null ? 'is-empty' : '' }}">{{ $value ? \Carbon\Carbon::parse($value)->format('d.m.Y') : 'necompletat' }}</dd>
                        @break
                    @case('scale')
                        <dd class="{{ $value === null ? 'is-empty' : '' }}">{{ $value === null ? 'necompletat' : $value.' (scala '.$field['min'].'–'.$field['max'].')' }}</dd>
                        @break
                    @case('table')
                        @php $rows = collect($value ?? [])->filter(fn ($r) => collect($r)->filter()->isNotEmpty()); @endphp
                        <dd class="{{ $rows->isEmpty() ? 'is-empty' : '' }}" style="white-space:normal">
                            @if ($rows->isEmpty())
                                necompletat
                            @else
                                <table class="med-table">
                                    <thead><tr>@foreach ($field['columns'] as $col)<th scope="col">{{ $col }}</th>@endforeach</tr></thead>
                                    <tbody>@foreach ($rows as $row)<tr>@foreach ($field['columns'] as $ci => $col)<td>{{ $row[$ci] ?? '' }}</td>@endforeach</tr>@endforeach</tbody>
                                </table>
                            @endif
                        </dd>
                        @break
                    @default
                        <dd class="{{ $value === null || $value === '' ? 'is-empty' : '' }}">{{ $value === null || $value === '' ? 'necompletat' : $value }}</dd>
                @endswitch
            @endforeach
        </dl>
    </section>
@endforeach
