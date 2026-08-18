{{--
    .NET counterpart: <div asp-validation-summary="ModelOnly">, the block that shows
    errors not tied to one field. The duplicate-entry message arrives under the
    "form" key for exactly that reason.
--}}
@if ($errors->any())
    <div class="rl-error-summary" role="alert">
        <ul>
            @foreach ($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif
