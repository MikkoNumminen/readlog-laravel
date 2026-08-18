<?php

namespace App\Services;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerAction;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Cleans third-party (Google Books) description HTML before it is rendered.
 *
 * .NET counterpart: Services/BookDescriptionSanitizer.cs, which builds a
 * Ganss.Xss HtmlSanitizer. The Symfony component is the closest equivalent in PHP:
 * both work from an allowlist, so anything not named is dropped rather than anything
 * known-bad being blocked.
 *
 * The source starts from Ganss's default allowlist and removes one thing: the
 * `target` attribute, so a sanitised link cannot open target="_blank" without
 * rel="noopener" and expose reverse tabnabbing. Symfony's config has no default
 * element list to subtract from, so the allowlist is written out. It is short on
 * purpose: a book description needs paragraphs, line breaks, emphasis, lists and
 * links, and nothing else. `target` is simply never allowed, and
 * forceAttribute puts rel="noopener noreferrer" on every link that survives.
 */
class BookDescriptionSanitizer
{
    private readonly HtmlSanitizer $sanitizer;

    public function __construct()
    {
        $config = (new HtmlSanitizerConfig)
            // Unknown elements are unwrapped, keeping their text, rather than being
            // removed with everything inside them.
            //
            // This one line is the difference between the two libraries. Ganss.Xss,
            // which the .NET version uses, keeps the text of a disallowed tag by
            // default. Symfony's default action is Drop, so the first version of this
            // class turned "<marquee>Still readable</marquee>" into an empty string:
            // no error, no warning, just a description that silently disappeared.
            // Found by a test, not by reading either library's documentation.
            //
            // The explicit dropElement calls below are what makes Block safe: script
            // and style content must not survive as text.
            ->defaultAction(HtmlSanitizerAction::Block)
            ->allowElement('p')
            ->allowElement('br')
            ->allowElement('b')
            ->allowElement('strong')
            ->allowElement('i')
            ->allowElement('em')
            ->allowElement('u')
            ->allowElement('span')
            ->allowElement('ul')
            ->allowElement('ol')
            ->allowElement('li')
            ->allowElement('blockquote')
            ->allowElement('h3')
            ->allowElement('h4')
            ->allowElement('a', ['href'])
            // http and https only, so a javascript: or data: href cannot survive.
            ->allowLinkSchemes(['http', 'https'])
            ->forceAttribute('a', 'rel', 'noopener noreferrer')
            // Anything not allowed above has its tags stripped but its text kept,
            // which matters: a description wrapped in an unknown tag should still
            // read, not vanish.
            ->dropElement('script')
            ->dropElement('style')
            ->dropElement('iframe')
            ->dropElement('object')
            ->dropElement('embed')
            ->dropElement('form');

        $this->sanitizer = new HtmlSanitizer($config);
    }

    public function sanitize(string $html): string
    {
        return $this->sanitizer->sanitize($html);
    }
}
