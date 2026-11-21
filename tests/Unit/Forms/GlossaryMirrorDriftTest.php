<?php

declare(strict_types=1);

use App\Support\Forms\Glossary;

// M245 — the builder's glossary panel and the respondent app mirror `Glossary`'s bounds
// (`resources/js/components/builder/glossary.ts`). A mirror is a second copy, so this reads both and fails when they
// disagree. The term bound is `ContentBlocks::MAX_TERM_LENGTH`, pinned by `ContentBlocksMirrorDriftTest`.
//
// ⚠️ It reads the TS source as text. Each pattern is anchored on the declaration's own name, and an unmatched one
// fails the case rather than comparing against nothing.

/** @return string the mirror's source */
function glossaryMirrorSource(): string
{
    return (string) file_get_contents(__DIR__.'/../../../resources/js/components/builder/glossary.ts');
}

it('mirrors every bound the glossary enforces, by value', function (string $mirror, int $bound): void {
    $found = preg_match('/^\s*'.$mirror.':\s*(\d+),$/m', glossaryMirrorSource(), $match);

    expect($found)->toBe(1, "GLOSSARY_LIMITS has no {$mirror}")
        ->and((int) $match[1])->toBe($bound);
})->with([
    'entries' => ['maxEntries', Glossary::MAX_ENTRIES],
    'definition length' => ['maxDefinitionLength', Glossary::MAX_DEFINITION_LENGTH],
]);

it('mirrors exactly the bounds the glossary has, so a new one cannot be left out of the list above', function (): void {
    $reflection = new ReflectionClass(Glossary::class);
    $bounds = array_keys(array_filter($reflection->getConstants(), static fn (mixed $value): bool => is_int($value)));

    preg_match('/export const GLOSSARY_LIMITS = \{([^}]*)\}/', glossaryMirrorSource(), $limits);
    preg_match_all('/^\s*(\w+):/m', $limits[1] ?? '', $keys);

    expect($bounds)->toBe(['MAX_ENTRIES', 'MAX_DEFINITION_LENGTH'])
        ->and($keys[1])->toBe(['maxEntries', 'maxDefinitionLength']);
});
