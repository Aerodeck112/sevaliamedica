@extends('site::layout')
@section('main')
    <div id="wrap" class="{{ $wrapClass }}">
        @if (! empty($notice))
            <div class="container pt-4"><div class="alert alert-success" role="status">{{ $notice }}</div></div>
        @endif
        {!! $main !!}
    </div>
@endsection
