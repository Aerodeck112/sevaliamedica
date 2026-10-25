@extends('layouts.panou')
@section('title', 'Mesaje din site')
@section('content')
    <x-panou.page-head title="Mesaje din site" sub="Solicitările trimise prin formularele siteului. Conținutul este criptat; fiecare deschidere se înregistrează în jurnalul de audit." />
    <nav class="tabs" aria-label="Stare">
        @foreach (\App\Modules\Cms\Http\Controllers\SubmissionController::STATUSES as $key => $label)
            <a href="{{ route('panou.cms.submissions.index', ['stare' => $key]) }}" @if ($status === $key) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>
    <x-panou.panel :flush="true">
        @if ($items->isEmpty())
            <x-panou.empty title="Niciun mesaj">Nu există mesaje în această categorie.</x-panou.empty>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Referință</th><th>Primit</th><th>Formular</th><th>Stare</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($items as $item)
                        <tr>
                            <td><a class="row-link" href="{{ route('panou.cms.submissions.show', $item) }}">{{ $item->reference }}</a></td>
                            <td class="num">{{ $item->created_at->format('d.m.Y H:i') }}</td>
                            <td>{{ $item->form === 'emsella' ? 'Landing EMSELLA' : 'Contact' }}</td>
                            <td><x-panou.badge :tone="$item->status === 'noua' ? 'warn' : null">{{ \App\Modules\Cms\Http\Controllers\SubmissionController::STATUSES[$item->status] ?? $item->status }}</x-panou.badge></td>
                            <td class="text-right"><a class="btn btn--small" href="{{ route('panou.cms.submissions.show', $item) }}">Deschide</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            {{ $items->links() }}
        @endif
    </x-panou.panel>
@endsection
