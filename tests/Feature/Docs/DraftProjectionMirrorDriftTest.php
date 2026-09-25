<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The draft projection vs. the wire shape it mirrors (M112).
|--------------------------------------------------------------------------
| `resources/js/components/builder/draft-snapshot.ts` builds a `SchemaResponse` by hand from the
| builder's draft model. That makes it a MIRROR of `RawField`/`RawValidation`/`RawSection` in
| `resources/public-runtime/lib/types.ts`, and unguarded hand-mirrors are this repository's measured
| pathology — ten of them exist in this very area, five of which mirror a PHP predicate and two of which
| mirror nothing at all.
|
| ⛔ THE FAILURE THIS CATCHES IS SILENT BY CONSTRUCTION. TypeScript does NOT catch a missing key here:
| the projection assigns an object literal into a `RawField`-typed array, so a MISSING required member is
| a compile error — but a member ADDED to `RawField` with an optional marker, or a member the projection
| emits that the wire shape no longer declares, both compile clean and diverge in production. The preview
| would then render a form the real runtime does not.
|
| The technique is `tests/Unit/Forms/PdfFieldRoleTest.php`'s, deliberately, INCLUDING both of its
| anti-vacuity arms: a mirror gate whose regex silently matches nothing reports `passed` while blind,
| which is the `gate with no floor` shape this project has now met at several layers.
|
| Path via `dirname(__DIR__, 3)` rather than `base_path()` — this reads source text and needs no
| container.
*/

/** The members declared by an interface block in the public-runtime type file. */
function rawInterfaceMembers(string $interface): array
{
    $path = dirname(__DIR__, 3).'/resources/public-runtime/lib/types.ts';
    $source = file_get_contents($path);

    expect($source)->toBeString()->not->toBeEmpty("could not read {$path}");

    $matched = preg_match(
        '/export interface '.preg_quote($interface, '/').'\s*\{(?<body>.*?)\n\}/s',
        (string) $source,
        $block,
    );

    // ⛔ ANTI-VACUITY 1: a rename or a shape change must fail LOUDLY rather than yield an empty set.
    expect($matched)->toBe(1, "{$interface} is no longer an `export interface … { … }` block in types.ts");

    // Members are `name: type;` or `name?: type;` at one level of indentation. Comments are skipped
    // because a `//` or `*` line never matches the leading-spaces-then-identifier-then-colon shape.
    preg_match_all('/^    (?<name>[a-z_][a-z0-9_]*)\??:/mi', $block['body'], $members);

    // ⛔ ANTI-VACUITY 2: an empty member list is a broken parser, not an empty interface.
    expect($members['name'])->not->toBeEmpty("parsed no members out of {$interface}");

    return array_values(array_unique($members['name']));
}

/** The top-level keys the projection emits for one object literal. */
function projectedKeys(string $marker): array
{
    $path = dirname(__DIR__, 3).'/resources/js/components/builder/draft-snapshot.ts';
    $source = file_get_contents($path);

    expect($source)->toBeString()->not->toBeEmpty("could not read {$path}");

    // Anchored on an explicit `// mirror:<Interface>` comment in the source rather than on the
    // surrounding code, so refactoring the projection cannot silently un-anchor this gate.
    $matched = preg_match(
        '{//\s*mirror:'.preg_quote($marker, '}').'\n\s*(?:return|fields\.push\()\s*\{(?<body>.*?)\n(?<indent> *)\}}s',
        (string) $source,
        $block,
    );

    expect($matched)->toBe(1, "no `// mirror:{$marker}` anchor followed by an object literal in draft-snapshot.ts");

    // Keys sit one level deeper than the literal's closing brace.
    $depth = strlen($block['indent']) + 4;
    // ⚠️ BOTH FORMS, and the shorthand half is not optional: `key,` and `config,` are written as
    // ES shorthand, so a colon-only regex silently missed two of RawField's members — measured, by
    // this very gate, on its first run against a correct projection.
    preg_match_all('/^ {'.$depth.'}(?<name>[a-z_][a-z0-9_]*)\s*[:,]/mi', $block['body'], $keys);

    expect($keys['name'])->not->toBeEmpty("parsed no keys out of the «{$marker}» literal");

    return array_values(array_unique($keys['name']));
}

it('projects exactly the members RawField declares', function (): void {
    // One call, so a MISSING key and an EXTRA key both fail — the same discipline PdfFieldRoleTest uses.
    expect(projectedKeys('RawField'))
        ->toEqualCanonicalizing(rawInterfaceMembers('RawField'));
});

it('projects exactly the members RawValidation declares', function (): void {
    expect(projectedKeys('RawValidation'))
        ->toEqualCanonicalizing(rawInterfaceMembers('RawValidation'));
});

it('projects exactly the members RawSection declares', function (): void {
    expect(projectedKeys('RawSection'))
        ->toEqualCanonicalizing(rawInterfaceMembers('RawSection'));
});

it('holds a member count floor, so a parser that degrades cannot pass', function (): void {
    // ⚠️ These numbers are a FLOOR and are expected to rise. They exist so that a regex which starts
    // matching two members instead of seventeen fails here rather than reporting a clean mirror.
    expect(rawInterfaceMembers('RawField'))->toHaveCount(17)
        ->and(rawInterfaceMembers('RawValidation'))->toHaveCount(10)
        ->and(rawInterfaceMembers('RawSection'))->toHaveCount(10);
});
