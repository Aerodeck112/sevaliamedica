{{--
    Câmp de formular cu etichetă, ajutor și eroare legate prin aria-describedby.
    type: text|email|password|tel|number|date|time|datetime-local|url|textarea|select|checkbox
--}}
@props([
    'name', 'label', 'type' => 'text', 'value' => null, 'required' => false, 'hint' => null,
    'options' => [], 'placeholder' => null, 'full' => false, 'autocomplete' => null, 'id' => null,
])
@php
    $id = $id ?? 'f_'.str_replace(['[', ']', '.'], '_', $name);
    $dot = str_replace(['[', ']'], ['.', ''], $name);
    $error = $errors->first($dot);
    $current = old($dot, $value);
    $describedBy = trim(($hint ? $id.'_h ' : '').($error ? $id.'_e' : ''));
@endphp
<div class="field {{ $full ? 'field--full' : '' }}">
    @if ($type === 'checkbox')
        <input type="hidden" name="{{ $name }}" value="0">
        <label class="check" for="{{ $id }}">
            <input type="checkbox" id="{{ $id }}" name="{{ $name }}" value="1" @checked((bool) $current) {{ $attributes }}>
            <span>{{ $label }}</span>
        </label>
    @else
        <label class="field__label" for="{{ $id }}">{{ $label }}@if ($required)<span class="req" aria-hidden="true">*</span>@endif</label>
        @if ($type === 'textarea')
            <textarea id="{{ $id }}" name="{{ $name }}" @required($required) @if ($placeholder) placeholder="{{ $placeholder }}" @endif @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif @if ($error) aria-invalid="true" @endif {{ $attributes }}>{{ $current }}</textarea>
        @elseif ($type === 'select')
            <select id="{{ $id }}" name="{{ $name }}" @required($required) @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif @if ($error) aria-invalid="true" @endif {{ $attributes }}>
                @if ($placeholder !== false)<option value="">{{ $placeholder ?? '— alegeți —' }}</option>@endif
                @foreach ($options as $optValue => $optLabel)
                    <option value="{{ $optValue }}" @selected((string) $current === (string) $optValue)>{{ $optLabel }}</option>
                @endforeach
            </select>
        @else
            <input type="{{ $type }}" id="{{ $id }}" name="{{ $name }}" @if ($type !== 'password') value="{{ $current }}" @endif @required($required) @if ($placeholder) placeholder="{{ $placeholder }}" @endif @if ($autocomplete) autocomplete="{{ $autocomplete }}" @endif @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif @if ($error) aria-invalid="true" @endif {{ $attributes }}>
        @endif
    @endif
    @if ($hint)<span class="field__hint" id="{{ $id }}_h">{{ $hint }}</span>@endif
    @if ($error)<span class="field__error" id="{{ $id }}_e">{{ $error }}</span>@endif
</div>
