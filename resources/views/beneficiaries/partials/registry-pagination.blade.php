<nav class="rlcr-page-links" aria-label="Beneficiary pages">
    @if ($paginator->onFirstPage())
        <span aria-disabled="true" aria-label="Previous page">&larr;</span>
    @else
        <a href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Previous page">&larr;</a>
    @endif

    @foreach ($elements as $element)
        @if (is_string($element))
            <span aria-hidden="true">{{ $element }}</span>
        @else
            @foreach ($element as $page => $url)
                @if ($page == $paginator->currentPage())
                    <span aria-current="page" aria-label="{{ 'Page '.$page }}">{{ $page }}</span>
                @else
                    <a href="{{ $url }}" aria-label="{{ 'Go to page '.$page }}">{{ $page }}</a>
                @endif
            @endforeach
        @endif
    @endforeach

    @if ($paginator->hasMorePages())
        <a href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Next page">&rarr;</a>
    @else
        <span aria-disabled="true" aria-label="Next page">&rarr;</span>
    @endif
</nav>
