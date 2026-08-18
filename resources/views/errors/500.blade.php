{{--
    Deliberately standalone rather than extending layouts.app.

    The layout renders the reader switcher, which queries the users table. A 500 is
    quite often a database failure, and an error page that needs the database to
    render is an error page that fails when it is most needed. The .NET Error page
    has the same property for the same reason: it reads nothing but the request id.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Error · ReadLog</title>
    <link rel="stylesheet" href="{{ asset('css/site.css') }}">
</head>
<body>
    <div class="rl-container rl-main">
        <div class="rl-narrow">
            <h1 class="rl-page-title rl-error">Something went wrong.</h1>
            <p>An error occurred while processing your request.</p>
            <p><a href="{{ url('/') }}">Return home</a></p>
        </div>
    </div>
</body>
</html>
