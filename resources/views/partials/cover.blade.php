{{--
    A book cover, or the placeholder the source shows when there is no cover URL.
    Repeated in five places in the .NET views; folded into one partial here.

    $url  the cover URL, may be null
    $size one of sm, md, lg, grid
--}}
@if (filled($url ?? null))
    <img class="rl-cover rl-cover-{{ $size }}" src="{{ $url }}" alt="" loading="lazy" referrerpolicy="no-referrer">
@else
    <div class="rl-cover rl-cover-{{ $size }}" aria-hidden="true">📖</div>
@endif
