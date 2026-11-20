@extends('layouts.panou')
@section('title', 'Funcții panou')
@section('content')
    <x-panou.page-head title="Funcții panou"
        sub="Panoul se folosește pe etape: începeți cu esențialul și activați celelalte funcții când echipa este pregătită. O etapă oprită doar ascunde elementele ei din meniu și de pe ecranul principal; datele rămân, iar drepturile utilizatorilor nu se schimbă." />
    <form method="post" action="{{ route('panou.features.update') }}" class="stack">
        @csrf @method('put')
        @foreach ($stages as $number => $stage)
            @php $on = in_array($number, $enabled, true); @endphp
            <x-panou.panel>
                <label class="check" style="align-items:flex-start">
                    <input type="checkbox" name="stages[]" value="{{ $number }}" @checked($on) @disabled($number === 1)>
                    <span>
                        <strong>Etapa {{ $number }}. {{ $stage['label'] }}</strong>
                        @if ($number === 1) <x-panou.badge tone="ok">mereu activă</x-panou.badge>
                        @elseif ($on) <x-panou.badge tone="ok">activă</x-panou.badge>
                        @else <x-panou.badge>ascunsă</x-panou.badge>
                        @endif
                        <br><span class="small">{{ $stage['detail'] }}</span>
                        <br><span class="small muted">În meniu: {{ implode(', ', $stage['items']) ?: '—' }}</span>
                    </span>
                </label>
            </x-panou.panel>
        @endforeach
        <p class="small muted">Mereu vizibile pentru cine are dreptul: Acasă, Utilizatori, Actualizări, Backupuri și această pagină.</p>
        <div class="form-actions"><button class="btn btn--primary" type="submit">Salvează</button></div>
    </form>
@endsection
