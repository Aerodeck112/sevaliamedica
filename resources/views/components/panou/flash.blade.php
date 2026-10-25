@if (session('status'))
    <div class="alert alert--ok" role="status"><x-panou.icon name="check" /><div>{{ session('status') }}</div></div>
@endif
@if (session('warning'))
    <div class="alert alert--warn" role="status"><x-panou.icon name="warn" /><div>{{ session('warning') }}</div></div>
@endif
@if ($errors->any())
    <div class="alert alert--danger" role="alert">
        <x-panou.icon name="warn" />
        <div>
            <strong>Verificați câmpurile marcate.</strong>
            @if ($errors->count() > 1)
                <ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            @else
                <div>{{ $errors->first() }}</div>
            @endif
        </div>
    </div>
@endif
