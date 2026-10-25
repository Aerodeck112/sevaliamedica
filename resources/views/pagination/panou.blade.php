@if ($paginator->hasPages())
    <nav aria-label="Paginare">
        <ul class="pagination">
            @if ($paginator->onFirstPage())
                <li><span aria-disabled="true">Înapoi</span></li>
            @else
                <li><a href="{{ $paginator->previousPageUrl() }}" rel="prev">Înapoi</a></li>
            @endif
            @foreach ($elements as $element)
                @if (is_string($element))
                    <li><span>{{ $element }}</span></li>
                @endif
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <li aria-current="page"><span>{{ $page }}</span></li>
                        @else
                            <li><a href="{{ $url }}">{{ $page }}</a></li>
                        @endif
                    @endforeach
                @endif
            @endforeach
            @if ($paginator->hasMorePages())
                <li><a href="{{ $paginator->nextPageUrl() }}" rel="next">Înainte</a></li>
            @else
                <li><span aria-disabled="true">Înainte</span></li>
            @endif
        </ul>
    </nav>
@endif
