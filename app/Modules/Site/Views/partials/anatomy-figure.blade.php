{{--
    Schiță anatomică în linie (vedere frontală), desenată progresiv la apariție. Decorativă: aria-hidden.
    $focus: zonele evidențiate (coloana, gat_umeri, atm, genunchi, sold, pelvin, articulatii).
    Toate traseele au pathLength="1" ca animația de desen să fie independentă de lungime.
--}}
@php
    $focus = (array) ($focus ?? []);
    $markers = [
        'atm' => [[79, 50], [121, 50]],
        'gat_umeri' => [[100, 76], [60, 104], [140, 104]],
        'coloana' => [[100, 150], [100, 214]],
        'pelvin' => [[100, 250]],
        'sold' => [[76, 258], [124, 258]],
        'genunchi' => [[80, 360], [120, 360]],
        'articulatii' => [[60, 104], [42, 196], [80, 360], [120, 360], [158, 196], [140, 104]],
    ];
    $mirror = fn (string $d) => preg_replace_callback('/(-?\d+(?:\.\d+)?) (-?\d+(?:\.\d+)?)/', fn ($m) => (200 - (float) $m[1]).' '.$m[2], $d);
    $leftArm = 'M 55 97 C 46 110 44 128 44 148 L 40 198 L 34 248 C 31 260 33 272 39 275 C 44 273 46 263 46 255 L 52 205 L 58 158 L 60 134';
    $leftLeg = 'M 63 253 C 60 290 63 318 68 340 C 71 352 69 362 68 372 C 65 398 69 418 71 428 L 64 437 L 86 437 L 85 427 C 88 408 90 388 88 372 C 89 360 92 350 92 340 C 95 316 97 290 99 270';
@endphp
<svg class="sv-anatomy__svg" viewBox="0 0 200 444" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
    <g class="sv-anatomy__body">
        <ellipse cx="100" cy="40" rx="21" ry="26" pathLength="1" />
        <path d="M 92 64 L 91 80 M 108 64 L 109 80" pathLength="1" />
        <path d="M 91 80 C 76 84 60 86 54 98 C 50 108 54 120 60 132 C 62 160 60 188 66 210 C 68 226 63 240 63 253 C 71 262 88 268 100 270 C 112 268 129 262 137 253 C 137 240 132 226 134 210 C 140 188 138 160 140 132 C 146 120 150 108 146 98 C 140 86 124 84 109 80" pathLength="1" />
        <path d="{{ $leftArm }}" pathLength="1" />
        <path d="{{ $mirror($leftArm) }}" pathLength="1" />
        <path d="{{ $leftLeg }}" pathLength="1" />
        <path d="{{ $mirror($leftLeg) }}" pathLength="1" />
    </g>
    <g class="sv-anatomy__inner">
        {{-- maxilar și articulațiile temporo-mandibulare --}}
        <path d="M 82 52 C 86 62 94 67 100 67 C 106 67 114 62 118 52" pathLength="1" />
        {{-- claviculele și coastele --}}
        <path d="M 66 96 C 78 92 90 94 99 97 M 134 96 C 122 92 110 94 101 97" pathLength="1" />
        <path d="M 72 116 C 84 110 94 112 99 116 M 128 116 C 116 110 106 112 101 116 M 70 132 C 82 126 94 128 99 132 M 130 132 C 118 126 106 128 101 132 M 71 148 C 82 142 94 144 99 148 M 129 148 C 118 142 106 144 101 148 M 74 164 C 84 159 94 160 99 164 M 126 164 C 116 159 106 160 101 164" pathLength="1" />
        {{-- coloana vertebrală --}}
        @for ($y = 86; $y <= 232; $y += 9)
            <rect class="sv-anatomy__vertebra" style="--v: {{ intdiv($y - 86, 9) }}" x="{{ 96 + ($y > 170 ? -0.5 : 0) }}" y="{{ $y }}" width="{{ $y > 170 ? 9 : 8 }}" height="6.2" rx="2.2" pathLength="1" />
        @endfor
        {{-- bazinul și planșeul pelvin --}}
        <path d="M 76 236 C 68 246 72 258 84 263 C 92 266 96 262 100 257 C 104 262 108 266 116 263 C 128 258 132 246 124 236 C 112 243 88 243 76 236 Z" pathLength="1" />
        <path class="sv-anatomy__floor" d="M 86 256 C 94 262 106 262 114 256" pathLength="1" />
        {{-- articulațiile mari --}}
        <circle cx="60" cy="104" r="5" pathLength="1" /><circle cx="140" cy="104" r="5" pathLength="1" />
        <circle cx="76" cy="258" r="5.5" pathLength="1" /><circle cx="124" cy="258" r="5.5" pathLength="1" />
        <ellipse cx="80" cy="360" rx="6" ry="7" pathLength="1" /><ellipse cx="120" cy="360" rx="6" ry="7" pathLength="1" />
    </g>
    @foreach ($focus as $zone)
        @foreach ($markers[$zone] ?? [] as [$x, $y])
            <g class="sv-anatomy__focus" data-zone="{{ $zone }}" style="--d: {{ $loop->parent->index * 0.25 + $loop->index * 0.15 }}s">
                <circle cx="{{ $x }}" cy="{{ $y }}" r="9" class="sv-anatomy__ring" />
                <circle cx="{{ $x }}" cy="{{ $y }}" r="3.2" class="sv-anatomy__dot" />
            </g>
        @endforeach
    @endforeach
</svg>
