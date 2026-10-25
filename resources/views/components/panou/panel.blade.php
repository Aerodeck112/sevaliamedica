@props(['title' => null, 'flush' => false, 'id' => null])
<section {{ $attributes->merge(['class' => 'panel']) }} @if ($id) id="{{ $id }}" @endif @if ($title) aria-labelledby="{{ $id ?? 'p'.crc32($title) }}-t" @endif>
    @if ($title || isset($actions))
        <div class="panel__head">
            @if ($title)<h2 id="{{ $id ?? 'p'.crc32($title) }}-t">{{ $title }}</h2>@endif
            @isset($actions)<div class="panel__actions">{{ $actions }}</div>@endisset
        </div>
    @endif
    <div class="panel__body {{ $flush ? 'panel__body--flush' : '' }}">{{ $slot }}</div>
    @isset($footer)<div class="panel__foot">{{ $footer }}</div>@endisset
</section>
