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

    /*
    | Google sign-in. Absent by default, and absence is a supported state: the
    | sign-in page says so and the app runs read-only for everyone, which is what
    | a fresh clone and the static snapshot both get. The .NET original registers
    | its Google handler the same way, only when both halves are configured.
    */
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID', ''),
        'client_secret' => env('GOOGLE_CLIENT_SECRET', ''),
        'timeout' => (int) env('GOOGLE_OAUTH_TIMEOUT', 10),
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
        // longer: measured on a GPU shared with two other models, the first
        // question after a while took 47 s (the chat model loading), the next
        // ones 3 to 16 s. The bounds below let the first one through and still
        // end a truly stuck request. `readlog:ask --warm` after start-up is
        // what makes the first real question fast.
        'probe_timeout' => (int) env('OLLAMA_PROBE_TIMEOUT', 2),
        'embed_timeout' => (int) env('OLLAMA_EMBED_TIMEOUT', 60),
        'generate_timeout' => (int) env('OLLAMA_GENERATE_TIMEOUT', 90),
        // Embedding one entry right after it is saved: short, because a cold
        // model can take half a minute to load and a save must not wait for it.
        // A missed embedding is filled in at the next search or by readlog:embed,
        // which uses the long timeout below precisely so it can absorb that load.
        'write_embed_timeout' => (int) env('OLLAMA_WRITE_EMBED_TIMEOUT', 5),
        'backfill_embed_timeout' => (int) env('OLLAMA_BACKFILL_EMBED_TIMEOUT', 120),
        // How long a successful or failed probe is trusted before asking again.
        'probe_cache_seconds' => (int) env('OLLAMA_PROBE_CACHE', 60),
        // nomic-embed-text is trained with task prefixes and ranks noticeably
        // better with them; other models ignore or do not want them. Set both
        // to empty for a model without prefixes. Changing the document prefix
        // re-embeds every entry on the next readlog:embed (the hash covers it).
        'embed_document_prefix' => env('OLLAMA_EMBED_DOCUMENT_PREFIX', 'search_document: '),
        'embed_query_prefix' => env('OLLAMA_EMBED_QUERY_PREFIX', 'search_query: '),
        // "Ask your library": how many ranked entries the chat model is shown,
        // and how many still-unembedded entries one search will embed on the
        // spot before answering (the rest wait for readlog:embed).
        'ask_candidates' => (int) env('OLLAMA_ASK_CANDIDATES', 8),
        'ask_backfill_limit' => (int) env('OLLAMA_ASK_BACKFILL_LIMIT', 50),
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
