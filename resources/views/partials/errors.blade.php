{{--
    .NET counterpart: <div asp-validation-summary="ModelOnly">, the block that shows
    errors not tied to one field. The duplicate-entry message arrives under the
    "form" key for exactly that reason.

    One deliberate difference: this iterates $errors->all(), which is "All", not
    "ModelOnly". Blade has no per-field validation-message tag, so the field errors
    have nowhere else to go and would be invisible if this block filtered them out.
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
