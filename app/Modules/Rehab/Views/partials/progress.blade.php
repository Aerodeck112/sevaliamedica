{{-- Banda de evidență a ședințelor: $p = SessionProgress (forItem sau totals). --}}
@php
    $total = max(1, $p['prescribed']);
    $w = fn ($n) => round(min($n, $total) / $total * 100, 2);
@endphp
<div class="sessions-bar" role="img" aria-label="{{ $p['done'] }} efectuate, {{ $p['scheduled'] }} programate, {{ $p['pending'] }} de clarificat, din {{ $p['prescribed'] }} prescrise">
    <span class="is-done" style="width: {{ $w($p['done']) }}%"></span>
    <span class="is-pending" style="width: {{ $w($p['pending']) }}%"></span>
    <span class="is-scheduled" style="width: {{ $w($p['scheduled']) }}%"></span>
</div>
<div class="sessions-legend">
    <span><i style="background:var(--ok)"></i>{{ $p['done'] }} efectuate</span>
    <span><i style="background:var(--lagoon)"></i>{{ $p['scheduled'] }} programate</span>
    @if ($p['pending'])<span><i style="background:#e0a43a"></i>{{ $p['pending'] }} de clarificat</span>@endif
    <span>{{ $p['remaining'] }} rămase din {{ $p['prescribed'] }}</span>
    @if ($p['unscheduled'])<span><strong>{{ $p['unscheduled'] }} neprogramate</strong></span>@endif
    @if ($p['cancelled'] || $p['no_show'])<span class="muted">{{ $p['cancelled'] }} anulate · {{ $p['no_show'] }} neprezentări</span>@endif
</div>
