@extends('layouts.panou')
@section('title', 'Recenzii')
@section('content')
    @php $stars = fn ($n) => str_repeat('★', (int) round($n)).str_repeat('☆', 5 - (int) round($n)); @endphp
    <x-panou.page-head title="Recenziile pacienților" sub="Note de 1–5 stele lăsate pe tableta de la recepție după servicii. Comentariile pot conține informații personale: se păstrează criptat și se publică doar cu acordul pacientului." />
    <x-panou.panel :flush="true">
        <form class="filters" method="get" data-autosubmit>
            <x-panou.field name="staff_member_id" type="select" label="Specialist" :options="$staff" :value="$filters['staff_member_id'] ?? null" placeholder="Toți" />
            <x-panou.field name="rating" type="select" label="Note" :options="[5 => '5 stele', 4 => '4 stele', 3 => '3 stele', 2 => '2 stele', 1 => '1 stea']" :value="$filters['rating'] ?? null" placeholder="Toate" />
            <x-panou.field name="from" type="date" label="De la" :value="$filters['from'] ?? null" />
            <x-panou.field name="to" type="date" label="Până la" :value="$filters['to'] ?? null" />
            <noscript><button class="btn" type="submit">Filtrează</button></noscript>
        </form>
        <div class="metrics">
            <div class="metric"><span class="metric__value num">{{ $average ? number_format($average, 2, ',', '') : '—' }}</span><span class="metric__label">Nota medie</span><span class="metric__help" style="color:#f5a623;font-size:1.1rem">{{ $average ? $stars($average) : '' }}</span></div>
            <div class="metric"><span class="metric__value num">{{ $total }}</span><span class="metric__label">Recenzii</span></div>
            @foreach ([5, 4, 3, 2, 1] as $n)
                <div class="metric"><span class="metric__value num">{{ $distribution[$n] ?? 0 }}</span><span class="metric__label">{{ $n }} {{ $n === 1 ? 'stea' : 'stele' }}</span></div>
            @endforeach
        </div>
    </x-panou.panel>
    <div class="grid grid--2" style="margin-top:1.25rem">
        <x-panou.panel title="Pe specialiști" :flush="true">
            <table class="table"><thead><tr><th>Specialist</th><th>Recenzii</th><th>Media</th></tr></thead><tbody>
            @forelse ($byStaff as $row)
                <tr><td>{{ $row->staffMember?->display_name ?? '—' }}</td><td class="num">{{ $row->total }}</td><td class="num"><span style="color:#f5a623">{{ $stars($row->media) }}</span> {{ number_format($row->media, 2, ',', '') }}</td></tr>
            @empty <tr><td colspan="3" class="muted">Nicio recenzie încă.</td></tr> @endforelse
            </tbody></table>
        </x-panou.panel>
        <x-panou.panel title="Pe servicii" :flush="true">
            <table class="table"><thead><tr><th>Serviciu</th><th>Recenzii</th><th>Media</th></tr></thead><tbody>
            @forelse ($byService as $row)
                <tr><td>{{ $row->service?->name ?? '—' }}</td><td class="num">{{ $row->total }}</td><td class="num">{{ number_format($row->media, 2, ',', '') }}</td></tr>
            @empty <tr><td colspan="3" class="muted">Nicio recenzie încă.</td></tr> @endforelse
            </tbody></table>
        </x-panou.panel>
    </div>
    <x-panou.panel title="Recenzii" :flush="true" style="margin-top:1.25rem">
        @if ($reviews->isEmpty())
            <x-panou.empty title="Nicio recenzie">Recenziile apar aici după ce pacienții le lasă pe tabletă.</x-panou.empty>
        @else
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Data</th><th>Note</th><th>Serviciu / specialist</th><th>Comentariu</th><th>Pe site</th></tr></thead>
                <tbody>
                @foreach ($reviews as $r)
                    <tr>
                        <td class="num">{{ $r->created_at->format('d.m.Y H:i') }}</td>
                        <td style="color:#f5a623;white-space:nowrap">{{ $stars($r->rating) }}</td>
                        <td>{{ $r->service?->name }}<br><span class="muted small">{{ $r->staffMember?->display_name }}</span></td>
                        <td style="max-width:420px">{{ $r->comment ?? '—' }}<br><span class="muted small">{{ $r->publicAuthor() }}</span></td>
                        <td>
                            @if (! $r->publish_consent)
                                <span class="muted small">fără acord de publicare</span>
                            @elseif (auth()->user()->can('reviews.moderate'))
                                <form method="post" action="{{ route('panou.kiosk.reviews.publish', $r) }}">
                                    @csrf <input type="hidden" name="published" value="{{ $r->published ? 0 : 1 }}">
                                    <button class="btn btn--small" type="submit">{{ $r->published ? 'Retrage de pe site' : 'Publică pe site' }}</button>
                                </form>
                            @else
                                {{ $r->published ? 'publicată' : 'nepublicată' }}
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
            {{ $reviews->links() }}
        @endif
    </x-panou.panel>
@endsection
