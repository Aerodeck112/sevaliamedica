@extends('layouts.panou')
@section('title', 'Reguli programări')
@section('content')
    <x-panou.page-head title="Reguli de programare și acces" sub="Valorile sunt stabilite de clinică. Fiecare modificare se înregistrează în jurnalul de audit, cu valoarea veche și cea nouă." />
    <form method="post" action="{{ route('panou.scheduling-settings.update') }}" class="stack">
        @csrf @method('put')
        @foreach ($groups as $group => $defs)
            <x-panou.panel :title="$group">
                <div class="form-grid">
                    @foreach ($defs as $d)
                        @php $label = $d['label'].(! empty($d['unit']) ? ' ('.$d['unit'].')' : ''); @endphp
                        @if ($d['type'] === 'checkbox')
                            <x-panou.field :name="$d['field']" type="checkbox" :label="$label" :value="$values[$d['key']]" full />
                            @if (! empty($d['hint']))<p class="field__hint field--full" style="margin-top:-.6rem">{{ $d['hint'] }}</p>@endif
                        @elseif ($d['type'] === 'select')
                            <x-panou.field :name="$d['field']" type="select" :label="$label" :value="$values[$d['key']]" :options="$d['options']" :placeholder="false" :hint="$d['hint'] ?? null" />
                        @else
                            <x-panou.field :name="$d['field']" :type="$d['type']" :label="$label" :value="$values[$d['key']]" required :hint="$d['hint'] ?? null" />
                        @endif
                    @endforeach
                </div>
            </x-panou.panel>
        @endforeach
        <div class="form-actions"><button class="btn btn--primary" type="submit">Salvează regulile</button></div>
    </form>
@endsection
