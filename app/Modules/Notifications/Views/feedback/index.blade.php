@extends('layouts.panou')
@section('title', 'Feedback pacienți')
@section('content')
    <x-panou.page-head title="Feedback pacienți" sub="Răspunsurile la formularul scurt trimis după vizită. Comentariile sunt scrise liber de pacienți; tratați-le ca date personale." />
    <x-panou.panel :flush="true">
        <div class="metrics" style="border-top:0">
            <div class="metric"><span class="metric__value">{{ $average ? number_format($average, 1, ',', '') : '—' }}</span><span class="metric__label">Nota medie</span><span class="metric__help">răspunsuri din ultimele 90 de zile</span></div>
            <div class="metric"><span class="metric__value">{{ $sent }}</span><span class="metric__label">Solicitări create</span><span class="metric__help">ultimele 90 de zile</span></div>
            <div class="metric"><span class="metric__value">{{ $sent ? round($answered / $sent * 100) : 0 }}%</span><span class="metric__label">Rata de răspuns</span><span class="metric__help">răspunsuri / solicitări</span></div>
        </div>
        @if ($responses->isEmpty())
            <x-panou.empty title="Niciun răspuns încă">Solicitarea de feedback se activează din Canale notificări → Tipuri de mesaje active.</x-panou.empty>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Răspuns</th><th>Vizita</th><th>Locația</th><th>Persoana</th><th>Nota</th><th>Comentariu</th></tr></thead>
                    <tbody>
                    @foreach ($responses as $r)
                        <tr>
                            <td class="num">{{ $r->submitted_at->format('d.m.Y H:i') }}</td>
                            <td class="num">{{ $r->appointment?->starts_at?->format('d.m.Y') }}</td>
                            <td>{{ $r->appointment?->location?->name }}</td>
                            <td>{{ $r->appointment?->staffMember?->display_name }}</td>
                            <td><strong>{{ $r->rating }}</strong>/5</td>
                            <td class="small">{{ $r->comment }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            {{ $responses->links() }}
        @endif
    </x-panou.panel>
@endsection
