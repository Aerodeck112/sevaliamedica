{{-- Un câmp editabil al unui bloc. $uid, $slot, $media (colecție id => Media). --}}
@php
    $id = $slot['id'];
    $base = "slots[$uid][$id]";
    $dot = "slots.$uid.$id";
    $fid = 's_'.$uid.'_'.$id;
@endphp
<div class="cms-slot cms-slot--{{ $slot['kind'] }}" data-slot="{{ $id }}">
    @switch($slot['kind'])
        @case('text')
            <label class="field__label" for="{{ $fid }}">{{ $slot['label'] }}</label>
            <textarea id="{{ $fid }}" name="{{ $base }}" class="cms-inline" rows="2" data-rich="inline">{{ old($dot, $slot['value']) }}</textarea>
            @break
        @case('link')
            <fieldset class="cms-slot__group">
                <legend class="field__label">{{ $slot['label'] }}</legend>
                <label class="small" for="{{ $fid }}_t">Text</label>
                <textarea id="{{ $fid }}_t" name="{{ $base }}[text]" class="cms-inline" rows="1" data-rich="inline">{{ old("$dot.text", $slot['value']['text']) }}</textarea>
                <label class="small" for="{{ $fid }}_h">Adresă</label>
                <input id="{{ $fid }}_h" type="text" name="{{ $base }}[href]" value="{{ old("$dot.href", $slot['value']['href']) }}" data-link-check>
                <label class="check small"><input type="hidden" name="{{ $base }}[new_tab]" value="0"><input type="checkbox" name="{{ $base }}[new_tab]" value="1" @checked(old("$dot.new_tab", $slot['value']['new_tab']))> <span>Se deschide în filă nouă</span></label>
            </fieldset>
            @break
        @case('href')
            <fieldset class="cms-slot__group">
                <legend class="field__label">{{ $slot['label'] }}</legend>
                <input id="{{ $fid }}_h" type="text" name="{{ $base }}[href]" value="{{ old("$dot.href", $slot['value']['href']) }}" aria-label="Adresă link" data-link-check>
                <label class="check small"><input type="hidden" name="{{ $base }}[new_tab]" value="0"><input type="checkbox" name="{{ $base }}[new_tab]" value="1" @checked(old("$dot.new_tab", $slot['value']['new_tab']))> <span>Se deschide în filă nouă</span></label>
            </fieldset>
            @break
        @case('image')
        @case('bg')
            @php $m = $media[$slot['value']['media_id'] ?? 0] ?? null; @endphp
            <fieldset class="cms-slot__group cms-media-field">
                <legend class="field__label">{{ $slot['label'] }}</legend>
                <div class="cms-media-field__row">
                    <img class="cms-media-field__thumb" src="{{ $m ? $m->bestUrl(320) : ($slot['value']['src'] ?? $slot['value']['url'] ?? '') }}" alt="" width="96" height="64" loading="lazy">
                    <div class="stack" style="flex:1">
                        <label class="small" for="{{ $fid }}_m">ID imagine din bibliotecă</label>
                        <input id="{{ $fid }}_m" type="number" name="{{ $base }}[media_id]" value="{{ old("$dot.media_id", $slot['value']['media_id']) }}" data-media-input>
                        <button type="button" class="btn btn--small" data-media-pick="{{ $fid }}_m">Alege din bibliotecă</button>
                    </div>
                </div>
                @if ($slot['kind'] === 'image')
                    <label class="small" for="{{ $fid }}_a">Text alternativ (descrie imaginea pentru cititoarele de ecran; gol dacă e decorativă)</label>
                    <input id="{{ $fid }}_a" type="text" name="{{ $base }}[alt]" value="{{ old("$dot.alt", $slot['value']['alt']) }}" maxlength="300">
                @endif
            </fieldset>
            @break
    @endswitch
</div>
