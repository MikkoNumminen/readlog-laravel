<?php

use App\Services\BookDescriptionSanitizer;

/*
| Port of tests/ReadLog.Tests/Services/BookDescriptionSanitizerTests.cs, plus a few
| cases of my own. This is the only place in the app that renders third-party HTML
| unescaped, so it gets more coverage than the source has rather than less.
*/

function sanitize(string $html): string
{
    return app(BookDescriptionSanitizer::class)->sanitize($html);
}

it('strips script tags and their contents', function () {
    $clean = sanitize('<p>Hello</p><script>alert("xss")</script>');

    expect($clean)->toContain('Hello')
        ->and(strtolower($clean))->not->toContain('<script')
        ->and(strtolower($clean))->not->toContain('alert');
});

it('strips inline event handlers', function () {
    expect(strtolower(sanitize('<img src="x" onerror="alert(1)" />')))->not->toContain('onerror');
});

it('strips javascript uris', function () {
    expect(strtolower(sanitize('<a href="javascript:alert(1)">click</a>')))->not->toContain('javascript:');
});

it('drops the target attribute, which prevents reverse tabnabbing', function () {
    $clean = sanitize('<a href="https://example.com" target="_blank">link</a>');

    expect(strtolower($clean))->not->toContain('target')
        ->and($clean)->toContain('https://example.com'); // the safe link survives
});

it('forces rel="noopener noreferrer" onto every surviving link', function () {
    // Stronger than the .NET version, which only removes target. Belt as well as
    // braces: even if a target ever came back, the link could not reach window.opener.
    expect(sanitize('<a href="https://example.com">link</a>'))->toContain('noopener');
});

it('keeps safe formatting markup', function () {
    $clean = sanitize('<p>A <b>bold</b> and <i>italic</i> blurb.</p>');

    expect($clean)->toContain('<b>bold</b>')->toContain('<i>italic</i>');
});

it('keeps lists and paragraphs, which real descriptions use', function () {
    $clean = sanitize('<p>One</p><ul><li>Two</li></ul><br><em>Three</em>');

    expect($clean)->toContain('<p>')->toContain('<ul>')->toContain('<li>')->toContain('<em>');
});

it('keeps the text of an element it does not allow', function () {
    // A description wrapped in an unknown tag should still read, not vanish.
    expect(sanitize('<marquee>Still readable</marquee>'))->toContain('Still readable');
});

it('strips data: uris as well as javascript:', function () {
    $clean = sanitize('<a href="data:text/html;base64,PHNjcmlwdD4=">click</a>');

    expect(strtolower($clean))->not->toContain('data:');
});

it('drops iframes, objects, embeds and forms whole', function (string $html, string $needle) {
    expect(strtolower(sanitize($html)))->not->toContain($needle);
})->with([
    ['<iframe src="https://evil"></iframe>', '<iframe'],
    ['<object data="https://evil"></object>', '<object'],
    ['<embed src="https://evil">', '<embed'],
    ['<form action="https://evil"><input name="x"></form>', '<form'],
    ['<style>body{display:none}</style>', '<style'],
]);

it('leaves plain text untouched', function () {
    expect(sanitize('Just a plain blurb.'))->toContain('Just a plain blurb.');
});
