@extends('layouts.panou')
@section('title', 'Verificarea mediului')
@section('content')
    <x-panou.page-head title="Verificarea mediului" :crumbs="['Actualizări' => route('panou.updates.index'), 'Mediu' => null]"
        sub="Valorile reale raportate de serverul pe care rulează aplicația. Aceeași verificare: php artisan sevalia:install --check." />
    <x-panou.panel :flush="true">
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Grup</th><th>Element</th><th>Valoare</th><th>Stare</th><th>Observație</th></tr></thead>
                <tbody>
                @foreach ($rows as $r)
                    <tr>
                        <td>{{ $r['group'] }}</td>
                        <td>{{ $r['label'] }}</td>
                        <td class="num">{{ $r['value'] }}</td>
                        <td><x-panou.badge :tone="['ok' => 'ok', 'warn' => 'warn', 'fail' => 'danger'][$r['status']]">{{ ['ok' => 'ok', 'warn' => 'atenție', 'fail' => 'problemă'][$r['status']] }}</x-panou.badge></td>
                        <td class="small muted">{{ $r['hint'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </x-panou.panel>
@endsection
