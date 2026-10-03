<?php

declare(strict_types=1);

use App\Rules\ContentBlocks;

// M129 — the builder's content editor mirrors `ContentBlocks`' bounds so it can stop an author before a save is
// refused (`resources/js/components/builder/content-blocks.ts`). A mirror is a second copy, so this reads both and
// fails when they disagree; neither can move alone. The server stays the authority either way.
//
// ⚠️ It reads the TS source as text. Each pattern is anchored on the declaration's own name, and an unmatched one
// fails the case rather than comparing against nothing.

/** @return string the mirror's source */
function contentBlocksMirrorSource(): string
{
    return (string) file_get_contents(__DIR__.'/../../../resources/js/components/builder/content-blocks.ts');
}

/** @return list<string> the quoted strings of the array literal declared as `$name` */
function contentBlocksMirrorList(string $name): array
{
    $found = preg_match('/export const '.$name.'[^=]*=\s*\[([^\]]*)\]/', contentBlocksMirrorSource(), $match);
    expect($found)->toBe(1, "content-blocks.ts declares no {$name} list");
    preg_match_all("/'([^']*)'/", $match[1], $items);

    return $items[1];
}

it('mirrors every bound the rule enforces, by value', function (string $mirror, int $bound): void {
    $found = preg_match('/^\s*'.$mirror.':\s*(\d+),$/m', contentBlocksMirrorSource(), $match);

    expect($found)->toBe(1, "CONTENT_LIMITS has no {$mirror}")
        ->and((int) $match[1])->toBe($bound);
})->with([
    'blocks' => ['maxBlocks', ContentBlocks::MAX_BLOCKS],
    'pieces of text' => ['maxSpans', ContentBlocks::MAX_SPANS],
    'heading length' => ['maxHeadingLength', ContentBlocks::MAX_HEADING_LENGTH],
    'text length' => ['maxTextLength', ContentBlocks::MAX_TEXT_LENGTH],
    'link length' => ['maxLinkLength', ContentBlocks::MAX_LINK_LENGTH],
    'description length' => ['maxAltLength', ContentBlocks::MAX_ALT_LENGTH],
]);

it('mirrors the callout tones and the link schemes, in the rule’s order', function (): void {
    expect(contentBlocksMirrorList('CALLOUT_TONES'))->toBe(ContentBlocks::TONES)
        ->and(contentBlocksMirrorList('LINK_SCHEMES'))->toBe(ContentBlocks::LINK_SCHEMES);
});

it('mirrors exactly the bounds the rule has, so a new one cannot be left out of both lists above', function (): void {
    $reflection = new ReflectionClass(ContentBlocks::class);
    $bounds = array_keys(array_filter($reflection->getConstants(), static fn (mixed $value): bool => is_int($value)));

    preg_match('/export const CONTENT_LIMITS = \{([^}]*)\}/', contentBlocksMirrorSource(), $limits);
    preg_match_all('/^\s*(\w+):/m', $limits[1] ?? '', $keys);

    expect($bounds)->toBe(['MAX_BLOCKS', 'MAX_SPANS', 'MAX_HEADING_LENGTH', 'MAX_TEXT_LENGTH', 'MAX_LINK_LENGTH', 'MAX_ALT_LENGTH'])
        ->and($keys[1])->toBe(['maxBlocks', 'maxSpans', 'maxHeadingLength', 'maxTextLength', 'maxLinkLength', 'maxAltLength']);
});
