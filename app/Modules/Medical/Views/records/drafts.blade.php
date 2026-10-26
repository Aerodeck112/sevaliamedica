@extends('layouts.panou')
@section('title', 'Fișe de finalizat')
@section('content')
    <x-panou.page-head title="Fișe de finalizat" sub="Drafturile dumneavoastră, cele mai vechi primele." />
    <x-panou.panel :flush="true">
        @if (! $staff)
            <x-panou.empty title="Contul nu este legat de o persoană din personal">Administratorul vă poate asocia contul în Administrare → Personal.</x-panou.empty>
        @elseif ($drafts->isEmpty())
            <x-panou.empty title="Nicio fișă în draft">Toate fișele dumneavoastră sunt finalizate.</x-panou.empty>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Creată</th><th>Pacient</th><th>Fișă</th><th>Tip</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($drafts as $d)
                        <tr>
                            <td class="num">{{ $d->created_at->format('d.m.Y H:i') }}</td>
                            <td><a class="row-link" href="{{ route('panou.patients.show', [$d->patient, 'tab' => 'fise']) }}">{{ $d->patient->fullName() }}</a></td>
                            <td>{{ $d->title }}</td>
                            <td>{{ $d->kindLabel() }}</td>
                            <td class="text-right"><a class="btn btn--small" href="{{ route('panou.medical.records.edit', $d) }}">Continuă</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-panou.panel>
@endsection
