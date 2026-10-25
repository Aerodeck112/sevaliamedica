@extends('layouts.panou')
@section('title', 'Jurnal de audit')
@section('content')
    <x-panou.page-head title="Jurnal de audit" sub="Cine a consultat, modificat, descărcat sau exportat date. Înregistrările nu pot fi modificate sau șterse din aplicație." />
    <x-panou.panel :flush="true">
        <form class="filters" method="get">
            <x-panou.field name="action" label="Acțiune (prefix)" :value="$filters['action'] ?? ''" placeholder="ex. patients." />
            <x-panou.field name="user_id" type="number" label="ID utilizator" :value="$filters['user_id'] ?? ''" />
            <x-panou.field name="patient_id" type="number" label="ID pacient" :value="$filters['patient_id'] ?? ''" />
            <x-panou.field name="from" type="date" label="De la" :value="$filters['from'] ?? ''" />
            <x-panou.field name="to" type="date" label="Până la" :value="$filters['to'] ?? ''" />
            <button class="btn" type="submit">Filtrează</button>
        </form>
        @if ($logs->isEmpty())
            <x-panou.empty title="Nicio înregistrare">Nu există evenimente pentru filtrele alese.</x-panou.empty>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Moment</th><th>Utilizator</th><th>Acțiune</th><th>Obiect</th><th>Pacient</th><th>IP</th><th>Detalii</th></tr></thead>
                    <tbody>
                    @foreach ($logs as $log)
                        <tr>
                            <td class="num">{{ $log->occurred_at->format('d.m.Y H:i:s') }}</td>
                            <td>{{ $log->user?->name ?? '—' }}</td>
                            <td><code>{{ $log->action }}</code></td>
                            <td class="small">{{ $log->subject_type ? class_basename($log->subject_type).' #'.$log->subject_id : '—' }}</td>
                            <td class="num">{{ $log->patient_id ?? '—' }}</td>
                            <td class="num small">{{ $log->ip }}</td>
                            <td class="small">{{ $log->context ? json_encode($log->context, JSON_UNESCAPED_UNICODE) : '' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            {{ $logs->links() }}
        @endif
    </x-panou.panel>
@endsection
