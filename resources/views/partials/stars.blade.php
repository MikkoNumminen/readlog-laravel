{{--
    .NET counterpart: Pages/Shared/_Stars.cshtml.

    Renders nothing at all when the rating is null, which is the point: null means
    "no rating" and 0 means "rated zero stars", and those must not look the same.
--}}
@if (! is_null($rating))
    <span class="rl-stars" title="{{ $rating }} / 5" aria-label="{{ $rating }} out of 5 stars">
        @for ($i = 1; $i <= 5; $i++)
            <span class="rl-star @if ($i <= $rating) rl-star-filled @endif">{{ $i <= $rating ? '★' : '☆' }}</span>
        @endfor
    </span>
@endif
