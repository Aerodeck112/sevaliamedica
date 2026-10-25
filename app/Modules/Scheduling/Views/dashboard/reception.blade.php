<x-panou.panel title="Recepție — azi">
    <x-slot:actions>
        <a class="btn btn--small" href="{{ route('panou.calendar') }}"><x-panou.icon name="calendar" />Calendar</a>
        <a class="btn btn--small btn--primary" href="{{ route('panou.appointments.create') }}"><x-panou.icon name="plus" />Programare</a>
    </x-slot:actions>
    <div class="metrics" style="border-top:0;margin:-1.25rem -1.25rem 1rem;border-bottom:1px solid var(--line)">
        <div class="metric"><span class="metric__value">{{ $counts['total'] }}</span><span class="metric__label">Programări azi</span><span class="metric__help">neanulate, din calendar</span></div>
        <div class="metric"><span class="metric__value">{{ $counts['waiting'] }}</span><span class="metric__label">De sosit</span><span class="metric__help">confirmate, încă nesosite</span></div>
        <div class="metric"><span class="metric__value">{{ $counts['arrived'] }}</span><span class="metric__label">Sosiți / în curs</span><span class="metric__help">marcate „sosit” sau „în consultație”</span></div>
        <div class="metric"><span class="metric__value">{{ $counts['done'] }}</span><span class="metric__label">Finalizate</span><span class="metric__help">închise explicit de personal</span></div>
        <div class="metric"><a href="{{ route('panou.requests.index') }}" class="metric__value" style="text-decoration:none">{{ $requests }}</a><span class="metric__label">Solicitări noi</span><span class="metric__help">de pe site, de preluat</span></div>
        <div class="metric"><a href="{{ route('panou.waitlist.index') }}" class="metric__value" style="text-decoration:none">{{ $waitlist }}</a><span class="metric__label">Listă de așteptare</span><span class="metric__help">intrări deschise</span></div>
    </div>
    @if ($unidentified || $reschedule)
        <div class="alert alert--warn"><x-panou.icon name="warn" /><div>
            @if ($unidentified){{ $unidentified }} programări confirmate automat așteaptă identificarea pacientului. @endif
            @if ($reschedule){{ $reschedule }} pacienți au cerut reprogramarea. @endif
            <a href="{{ route('panou.requests.index') }}">Vezi solicitările</a>
        </div></div>
    @endif
    @if ($today->isEmpty())
        <x-panou.empty title="Nicio programare azi">Programările confirmate apar aici în ordinea orei.</x-panou.empty>
    @else
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Ora</th><th>Pacient</th><th>Serviciu</th><th>Persoana</th><th>Status</th><th></th></tr></thead>
                <tbody>
                @foreach ($today as $a)
                    <tr>
                        <td class="num">{{ $a->starts_at->format('H:i') }}</td>
                        <td><a class="row-link" href="{{ route('panou.appointments.show', $a) }}">{{ $a->displayName() }}</a></td>
                        <td>{{ $a->service?->name }}</td>
                        <td>{{ $a->staffMember?->display_name }}</td>
                        <td>@include('scheduling::partials.status', ['status' => $a->status])</td>
                        <td class="text-right">
                            @if ($a->status === 'confirmata' && $a->starts_at->isToday())
                                <form method="post" action="{{ route('panou.appointments.status', $a) }}">@csrf<input type="hidden" name="to" value="sosit"><input type="hidden" name="return" value="calendar"><button class="btn btn--small" type="submit">Sosit</button></form>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
    @if ($cancellations->isNotEmpty())
        <hr class="sep">
        <p class="small muted" style="margin-bottom:.4rem">Anulări în ultimele 24 de ore</p>
        <ul class="small" style="margin:0;padding-left:1rem">
            @foreach ($cancellations as $c)
                <li><a href="{{ route('panou.appointments.show', $c) }}">{{ $c->starts_at->format('d.m H:i') }} · {{ $c->displayName() }}</a> — {{ $c->cancel_reason }}</li>
            @endforeach
        </ul>
    @endif
</x-panou.panel>
