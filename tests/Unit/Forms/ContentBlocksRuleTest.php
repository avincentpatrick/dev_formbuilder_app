<?php

declare(strict_types=1);

use App\Rules\ContentBlocks;

// M125 — a note's content blocks (R-6dedc3a9, the shape half). Pure: no container, no database.
//
// ⛔ EVERY REFUSAL BELOW IS ASSERTED IN BOTH MODES. Leniency is only ever about blanks an author has mid-edit; a bad
// shape or an unsafe link must be refused by the builder's save exactly as firmly as by publish, because the save is
// what persists it and the renderer is what shows it.
//
// ⚠️ Control characters are built with chr(), never written as escapes: the tool layer that wrote this file collapses
// doubled backslashes (CLAUDE.md, Traps on this host).
//
// ⚠️ Helpers are prefixed `contentBlocks*`: Pest loads every file into one process.

/** @return array<string, mixed> */
function contentBlocksSpan(string $text = 'Read the form', array $extra = []): array
{
    return ['text' => $text, ...$extra];
}

/** @return list<array<string, mixed>> */
function contentBlocksEveryKind(): array
{
    return [
        ['type' => 'heading', 'level' => 1, 'text' => 'Before you begin'],
        ['type' => 'heading', 'level' => 2, 'text' => 'Consent'],
        ['type' => 'paragraph', 'spans' => [
            contentBlocksSpan('Read the '),
            contentBlocksSpan('consent form', ['link' => 'https://example.org/consent?v=2', 'bold' => true]),
            contentBlocksSpan(' first.', ['italic' => true, 'code' => false]),
        ]],
        ['type' => 'callout', 'tone' => 'info', 'spans' => [contentBlocksSpan('Takes five minutes.')]],
        ['type' => 'callout', 'tone' => 'success', 'spans' => [contentBlocksSpan('Saved as you go.')]],
        ['type' => 'callout', 'tone' => 'warning', 'spans' => [contentBlocksSpan('Have your ID ready.')]],
        ['type' => 'callout', 'tone' => 'danger', 'spans' => [contentBlocksSpan('Do not share this link.')]],
        ['type' => 'divider'],
        ['type' => 'paragraph', 'spans' => [
            contentBlocksSpan('Questions? '),
            contentBlocksSpan('Email us', ['link' => 'mailto:help@example.org']),
            contentBlocksSpan(' or '),
            contentBlocksSpan('call', ['link' => 'tel:+6321234567']),
        ]],
    ];
}

it('accepts every block kind, in both modes', function (bool $strict): void {
    expect(ContentBlocks::problem(contentBlocksEveryKind(), $strict))->toBeNull()
        ->and(ContentBlocks::problem([], $strict))->toBeNull();
})->with(['lenient' => [false], 'strict' => [true]]);

it('accepts what an author has mid-edit when lenient, and refuses it when strict', function (array $block, string $strictReason): void {
    expect(ContentBlocks::problem([$block], false))->toBeNull()
        ->and(ContentBlocks::problem([$block], true))->toContain($strictReason);
})->with([
    'a heading with no text yet' => [['type' => 'heading', 'level' => 1, 'text' => null], 'heading with no text'],
    'a heading of spaces' => [['type' => 'heading', 'level' => 2, 'text' => '   '], 'heading with no text'],
    'an empty paragraph' => [['type' => 'paragraph', 'spans' => []], 'has no text'],
    'a paragraph of blanks' => [['type' => 'paragraph', 'spans' => [contentBlocksSpan(' '), ['text' => null]]], 'has no text'],
    'a callout with no text' => [['type' => 'callout', 'tone' => 'info', 'spans' => []], 'has no text'],
    'a link with no address' => [['type' => 'paragraph', 'spans' => [contentBlocksSpan('here', ['link' => null])]], 'link with no address'],
]);

it('refuses a bad shape in both modes', function (mixed $value, string $reason): void {
    expect(ContentBlocks::problem($value, false))->toContain($reason)
        ->and(ContentBlocks::problem($value, true))->toContain($reason);
})->with([
    'a string' => ['<h1>Hi</h1>', 'list of blocks'],
    'an object instead of a list' => [['first' => ['type' => 'divider']], 'list of blocks'],
    'a block that is a list' => [[['divider']], 'is not a block'],
    'an unknown type' => [[['type' => 'video', 'src' => 'x']], 'unknown type'],
    'an image, until D58' => [[['type' => 'image', 'attachment_id' => '01J']], 'unknown type'],
    'an html key on a block' => [[['type' => 'heading', 'level' => 1, 'text' => 'Hi', 'html' => '<b>x</b>']], 'unknown setting “html”'],
    'an html key on a span' => [[['type' => 'paragraph', 'spans' => [contentBlocksSpan('Hi', ['html' => '<b>x</b>'])]]], 'unknown setting “html”'],
    'a span nesting spans' => [[['type' => 'paragraph', 'spans' => [contentBlocksSpan('Hi', ['spans' => [contentBlocksSpan()]])]]], 'unknown setting “spans”'],
    'a span that is a list' => [[['type' => 'paragraph', 'spans' => [['Hi']]]], 'not one'],
    'text that is a number' => [[['type' => 'paragraph', 'spans' => [['text' => 42]]]], 'not text'],
    'a heading level of 3' => [[['type' => 'heading', 'level' => 3, 'text' => 'Hi']], 'level is not 1 or 2'],
    'a heading level as a string' => [[['type' => 'heading', 'level' => '1', 'text' => 'Hi']], 'level is not 1 or 2'],
    'an unknown tone' => [[['type' => 'callout', 'tone' => 'purple', 'spans' => [contentBlocksSpan()]]], 'unknown tone'],
    'bold that is not on or off' => [[['type' => 'paragraph', 'spans' => [contentBlocksSpan('Hi', ['bold' => 'yes'])]]], '“bold” is not on or off'],
    'spans that are not a list' => [[['type' => 'paragraph', 'spans' => 'Hi']], 'no list of text'],
    'a heading too long' => [[['type' => 'heading', 'level' => 1, 'text' => str_repeat('a', ContentBlocks::MAX_HEADING_LENGTH + 1)]], 'longer than'],
    'a span too long' => [[['type' => 'paragraph', 'spans' => [contentBlocksSpan(str_repeat('a', ContentBlocks::MAX_TEXT_LENGTH + 1))]]], 'longer than'],
]);

it('refuses more blocks or pieces of text than the bounds, in both modes', function (bool $strict): void {
    $blocks = array_fill(0, ContentBlocks::MAX_BLOCKS + 1, ['type' => 'divider']);
    $spans = array_fill(0, ContentBlocks::MAX_SPANS + 1, contentBlocksSpan());

    expect(ContentBlocks::problem($blocks, $strict))->toContain('at most')
        ->and(ContentBlocks::problem(array_fill(0, ContentBlocks::MAX_BLOCKS, ['type' => 'divider']), $strict))->toBeNull()
        ->and(ContentBlocks::problem([['type' => 'paragraph', 'spans' => $spans]], $strict))->toContain('more than');
})->with(['lenient' => [false], 'strict' => [true]]);

it('refuses every link it cannot vouch for, in both modes', function (string $link): void {
    $blocks = [['type' => 'paragraph', 'spans' => [contentBlocksSpan('click', ['link' => $link])]]];

    expect(ContentBlocks::problem($blocks, false))->toContain('link this form cannot show')
        ->and(ContentBlocks::problem($blocks, true))->toContain('link this form cannot show')
        ->and(ContentBlocks::linkIsSafe($link))->toBeFalse();
})->with([
    'javascript' => ['javascript:alert(1)'],
    'javascript, mixed case' => ['JavaScript:alert(1)'],
    'vbscript' => ['vbscript:msgbox(1)'],
    'a data URL' => ['data:text/html,<script>alert(1)</script>'],
    'a local file' => ['file:///etc/passwd'],
    'a newline inside the scheme' => ['java'.chr(10).'script:alert(1)'],
    'a tab inside the scheme' => ['java'.chr(9).'script:alert(1)'],
    'a leading space' => [' https://example.org'],
    'a NUL byte' => ['https://example.org/'.chr(0)],
    'a relative path' => ['/admin'],
    'protocol-relative' => ['//evil.example'],
    'no scheme at all' => ['example.org'],
    'https with no host' => ['https://'],
    'too long' => ['https://example.org/'.str_repeat('a', ContentBlocks::MAX_LINK_LENGTH)],
]);

it('accepts the links a form can safely show', function (string $link): void {
    expect(ContentBlocks::linkIsSafe($link))->toBeTrue();
})->with([
    'https' => ['https://example.org/a?b=c#d'],
    'http' => ['http://example.org'],
    'uppercase scheme' => ['HTTPS://EXAMPLE.ORG'],
    'mailto' => ['mailto:help@example.org'],
    'tel' => ['tel:+6321234567'],
]);
