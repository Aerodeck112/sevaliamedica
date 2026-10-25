@extends('layouts.panou')
@section('title', $model->exists ? $titles['singular'] : $titles['new'])
@section('content')
    <x-panou.page-head :title="$model->exists ? ($model->name ?? $model->display_name ?? $titles['singular']) : $titles['new']"
        :crumbs="[$titles['plural'] => route($routeBase.'.index'), ($model->exists ? 'Editare' : 'Adăugare') => null]" />
    <x-panou.panel>
        <form method="post" action="{{ $model->exists ? route($routeBase.'.update', $model->id) : route($routeBase.'.store') }}">
            @csrf
            @if ($model->exists) @method('put') @endif
            <div class="form-grid">
                @foreach ($fields as $f)
                    @if ($f->type === 'multiselect')
                        @php $selected = old($f->name, $model->exists ? $model->{$f->relation ?? $f->name}->pluck('id')->all() : []); @endphp
                        <fieldset class="field {{ $f->full ? 'field--full' : '' }}">
                            <legend class="field__label">{{ $f->label }}</legend>
                            <div class="cluster" style="gap:.4rem 1rem">
                                @foreach ($f->resolvedOptions() as $optValue => $optLabel)
                                    <label class="check"><input type="checkbox" name="{{ $f->name }}[]" value="{{ $optValue }}" @checked(in_array($optValue, $selected))><span>{{ $optLabel }}</span></label>
                                @endforeach
                            </div>
                            @if ($f->hint)<span class="field__hint">{{ $f->hint }}</span>@endif
                        </fieldset>
                    @elseif ($f->type === 'hours')
                        @include('crud.hours', ['field' => $f, 'value' => old($f->name, $model->{$f->name} ?? [])])
                    @else
                        @php $val = $model->{$f->name}; if ($val instanceof \DateTimeInterface) { $val = $val->format($f->type === 'date' ? 'Y-m-d' : 'Y-m-d\TH:i'); } @endphp
                        <x-panou.field :name="$f->name" :label="$f->label" :type="$f->type === 'color' ? 'text' : $f->type" :value="$val"
                            :required="$f->required" :hint="$f->hint" :options="$f->resolvedOptions()" :full="$f->full || $f->type === 'textarea'" />
                    @endif
                @endforeach
            </div>
            <div class="form-actions">
                <button class="btn btn--primary" type="submit">Salvează</button>
                <a class="btn btn--ghost" href="{{ route($routeBase.'.index') }}">Renunță</a>
            </div>
        </form>
        @if ($model->exists)
            <hr class="sep">
            <form method="post" action="{{ route($routeBase.'.destroy', $model->id) }}" data-confirm="Ștergeți definitiv această înregistrare?">
                @csrf @method('delete')
                <button class="btn btn--danger btn--small" type="submit">Șterge</button>
            </form>
        @endif
    </x-panou.panel>
    {!! $extra ?? '' !!}
@endsection
