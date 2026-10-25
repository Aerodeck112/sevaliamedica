@props(['title', 'sub' => null, 'crumbs' => []])
<header class="page-head">
    <div class="page-head__text">
        @if ($crumbs)
            <nav aria-label="Navigare ierarhică">
                <ol class="breadcrumbs">
                    @foreach ($crumbs as $label => $url)
                        <li>@if ($url)<a href="{{ $url }}">{{ $label }}</a>@else{{ $label }}@endif</li>
                    @endforeach
                </ol>
            </nav>
        @endif
        <h1>{{ $title }}</h1>
        @if ($sub)<p class="page-head__sub">{{ $sub }}</p>@endif
    </div>
    @isset($actions)
        <div class="page-head__actions">{{ $actions }}</div>
    @endisset
</header>
