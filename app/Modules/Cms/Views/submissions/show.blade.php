@extends('layouts.panou')
@php $p = $item->payload; @endphp
@section('title', 'Mesaj '.$item->reference)
@section('content')
    <x-panou.page-head :title="'Mesaj '.$item->reference" :crumbs="['Mesaje din site' => route('panou.cms.submissions.index'), $item->reference => null]" :sub="'Primit la '.$item->created_at->format('d.m.Y H:i').' prin formularul '.($item->form === 'emsella' ? 'landing EMSELLA' : 'de contact')" />
    <div class="grid grid--aside">
        <x-panou.panel title="Detalii">
            <dl class="facts">
                <dt>Nume</dt><dd>{{ $p['nume'] ?? '' }}</dd>
                <dt>Telefon</dt><dd>{{ $p['telefon'] ?? '—' }}</dd>
                <dt>E-mail</dt><dd>{{ $p['email'] ?? '—' }}</dd>
                <dt>Contact preferat</dt><dd>{{ $p['contact_preferat'] ?? '—' }}</dd>
                <dt>Specialitate</dt><dd>{{ $specialty?->name ?? '—' }}</dd>
                <dt>Medic dorit</dt><dd>{{ $staff?->display_name ?? '—' }}</dd>
                @if (! empty($p['data_nasterii']))<dt>Data nașterii</dt><dd>{{ $p['data_nasterii'] }}</dd>@endif
                <dt>Acord</dt><dd>{{ isset($p['consimtamant_la']) ? \Carbon\Carbon::parse($p['consimtamant_la'])->timezone(config('app.timezone'))->format('d.m.Y H:i') : '—' }}</dd>
            </dl>
            @if (! empty($p['mesaj']))
                <hr class="sep">
                <p style="white-space:pre-line">{{ $p['mesaj'] }}</p>
            @endif
        </x-panou.panel>
        <x-panou.panel title="Stare">
            <form method="post" action="{{ route('panou.cms.submissions.update', $item) }}" class="stack">
                @csrf @method('put')
                <x-panou.field name="status" type="select" label="Stare" :value="$item->status" :options="\App\Modules\Cms\Http\Controllers\SubmissionController::STATUSES" :placeholder="false" />
                <button class="btn btn--primary" type="submit">Salvează</button>
            </form>
            @if ($item->handled_at)<p class="small muted" style="margin-top:.75rem">Ultima actualizare: {{ $item->handled_at->format('d.m.Y H:i') }}</p>@endif
        </x-panou.panel>
    </div>
@endsection
