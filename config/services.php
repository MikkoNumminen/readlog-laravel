<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    | The two book providers.
    |
    | .NET counterpart: the "GoogleBooks" section bound to Options/GoogleBooksOptions.cs
    | through IOptions, plus the base addresses and timeouts set on the typed
    | HttpClients in Program.cs. Laravel has no IOptions equivalent worth building
    | here: config() is already a typed-enough, injectable, cached lookup, and one
    | class per config section would be ceremony without a payoff.
    |
    | Open Library needs no credentials. Google Books needs an API key, and when it
    | is absent that provider is skipped entirely rather than failing: search falls
    | back to Open Library only, and the book detail page shows "no details", which
    | is exactly what readlog-dotnet does.
    */
    'open_library' => [
        'base_url' => env('OPEN_LIBRARY_BASE_URL', 'https://openlibrary.org/'),
    ],

    'google_books' => [
        'api_key' => env('GOOGLE_BOOKS_API_KEY'),
        'base_url' => env('GOOGLE_BOOKS_BASE_URL', 'https://www.googleapis.com/books/v1/'),
    ],

    // Where `php artisan readlog:smoke` points its HTTP checks when no --url is
    // given. Falls back to APP_URL. Inside the compose app container APP_URL is
    // the host's address and not reachable, so compose sets this to http://web.
    'smoke' => [
        'url' => env('SMOKE_URL'),
    ],

    /*
    | Ollama, for the AI-assisted "ask your library" search. Everything degrades
    | when it is absent: the app never depends on it, and a search box that
    | cannot reach Ollama falls back to the title match with a one-line notice.
    |
    | Two models: one to embed entries and questions, one to phrase an answer over
    | the entries the deterministic layer already retrieved. Both are local; no
    | request ever leaves the machine.
    */
    'ollama' => [
        'enabled' => filter_var(env('AI_SEARCH_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'url' => rtrim((string) env('OLLAMA_URL', 'http://localhost:11434'), '/'),
        'embed_model' => env('OLLAMA_EMBED_MODEL', 'nomic-embed-text'),
        'chat_model' => env('OLLAMA_CHAT_MODEL', 'qwen2.5:7b'),
        // Seconds. The probe is what decides "is Ollama there"; it must be fast,
        // because it runs on a page request. Embedding and generation may take
        // longer, and generation is bounded because a demo cannot wait forever.
        'probe_timeout' => (int) env('OLLAMA_PROBE_TIMEOUT', 2),
        'embed_timeout' => (int) env('OLLAMA_EMBED_TIMEOUT', 20),
        'generate_timeout' => (int) env('OLLAMA_GENERATE_TIMEOUT', 45),
        // How long a successful or failed probe is trusted before asking again.
        'probe_cache_seconds' => (int) env('OLLAMA_PROBE_CACHE', 60),
    ],

    'book_search' => [
        // Seconds before a provider request is abandoned. Matches the 10 second
        // HttpClient.Timeout the .NET app sets on both typed clients.
        'timeout' => (int) env('BOOK_SEARCH_TIMEOUT', 10),

        // How many hits to ask each provider for. Kept equal across providers so the
        // Open-Library-first merge stays balanced.
        'limit' => (int) env('BOOK_SEARCH_LIMIT', 15),

        // Off by default: the test suite fakes every outbound request. Turning this
        // on lets the handful of tests tagged "live" actually call openlibrary.org
        // and googleapis.com, which is how the faked response shapes get checked
        // against reality now and then.
        'live_tests' => filter_var(env('BOOK_SEARCH_LIVE_TESTS', false), FILTER_VALIDATE_BOOLEAN),
    ],

];
