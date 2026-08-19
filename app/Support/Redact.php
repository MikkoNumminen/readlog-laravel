<?php

namespace App\Support;

/**
 * Removes credentials from text that is about to be logged or printed.
 *
 * One place for one rule. Both the search service (logging a provider failure)
 * and the smoke check (printing one) had their own copy of the same regex; a
 * rule that exists to keep a key out of a log file must not be able to drift
 * between the two.
 */
final class Redact
{
    /**
     * Blanks the value of any `key=` query parameter. Google Books only accepts
     * its API key in the query string, and Guzzle puts the full request URL into a
     * connection-failure message, so this is exactly where a key would leak.
     */
    public static function apiKey(string $text): string
    {
        return preg_replace('/([?&]key=)[^&\s]+/i', '$1REDACTED', $text) ?? $text;
    }
}
