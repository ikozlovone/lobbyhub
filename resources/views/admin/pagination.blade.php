{{-- Two links and a count: this list is read by one person, not browsed. --}}
@if ($paginator->hasPages())
    @if ($paginator->onFirstPage())
        <span>Previous</span>
    @else
        <a href="{{ $paginator->previousPageUrl() }}" rel="prev">Previous</a>
    @endif

    <span>Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}</span>

    @if ($paginator->hasMorePages())
        <a href="{{ $paginator->nextPageUrl() }}" rel="next">Next</a>
    @else
        <span>Next</span>
    @endif
@endif
