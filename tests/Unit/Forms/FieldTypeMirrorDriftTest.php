<?php

declare(strict_types=1);

use App\Enums\ComparisonOperator;
use App\Enums\FieldType;
use App\Enums\ValueShape;
use App\Services\Submissions\EncodeFormPresenter;

/*
|--------------------------------------------------------------------------
| M113 — the client-side field-type catalogue census (`R-09f73330`, closing `R-c5858976` with it).
|--------------------------------------------------------------------------
|
| The PHP `FieldType` enum is authoritative and the builder palette is fully server-driven, but the
| RENDERERS carry ten hand-written subsets of the same catalogue. Exactly one of them was gated before
| this file: `field-roles.ts`'s `RENDERS_NOTHING`, by `tests/Unit/Forms/PdfFieldRoleTest.php` — whose
| technique this copies rather than invents.
|
| ⛔ EIGHT OF THE TEN AGREE WITH PHP TODAY, WHICH IS EXACTLY WHY THIS GATE PROVES NOTHING UNTIL A
| COMMITTED MUTATION REDDENS IT. A gate written against already-correct data is green on arrival; the
| evidence that it works is `scripts/mutate.php`, not this file passing.
|
| ⚠️ THE POINT IS TO GUARD THE MIRRORS, NOT TO DELETE THEM. `EncodeFormPresenter`'s own docblock records
| that collapsing the duplication was deliberately declined, so converting unguarded mirrors into guarded
| ones is the remedy — the sets stay where they are and drift becomes a red build.
|
| ⛔ TWO OF THE TEN MIRROR NO PHP COUNTERPART AT ALL, AND THAT IS A FINDING RATHER THAN A GAP.
| `TEXT_TYPES` and the inline literal in `FieldInput.vue` are a TypeScript-only control-kind grouping:
| they cannot drift FROM a source because they ARE one, and an undeclared one. They are pinned against
| EACH OTHER (they are byte-identical today and a silent divergence would give the guest runtime and the
| encode page different controls) and against the catalogue, which catches a typo'd type string — a class
| of defect nothing in this repository catches today.
|
| ⚠️ AND `NUMERIC_TYPES` IS A DECLARED DIVERGENCE, NOT A MIRROR. `R-09f73330` says it "mirrors nothing";
| that was true when it was filed and stopped being true in the same increment that filed it. `M112`'s
| `ValueShape::allowsOperator()` now answers the identical question for the ordered operators and answers
| it DIFFERENTLY — it admits `duration`, and `NUMERIC_TYPES` does not. Pinned as a divergence with its
| membership stated, so the day someone reconciles them this file says what changed.
|
| No database, no container (`tests/Pest.php` binds TestCase to Feature only), so the repo root is
| computed from `__DIR__` rather than through `base_path()`.
*/

/**
 * Every client-side field-type subset, by a stable key: the file it lives in, the declared name, and the
 * grammar it is written in. Three grammars, because the tree really does use three.
 *
 * @return array<string, array{path: string, name: string, grammar: string}>
 */
function clientTypeMirrors(): array
{
    return [
        'schema_mapping.SUPPORTED' => ['path' => 'resources/public-runtime/lib/schema-mapping.ts', 'name' => 'SUPPORTED', 'grammar' => 'set'],
        'schema_mapping.HAS_OPTIONS' => ['path' => 'resources/public-runtime/lib/schema-mapping.ts', 'name' => 'HAS_OPTIONS', 'grammar' => 'set'],
        'schema_mapping.TEXT_TYPES' => ['path' => 'resources/public-runtime/lib/schema-mapping.ts', 'name' => 'TEXT_TYPES', 'grammar' => 'set'],
        'display_value.HAS_OPTIONS' => ['path' => 'resources/public-runtime/engine/display-value.ts', 'name' => 'HAS_OPTIONS', 'grammar' => 'set'],
        'display_value.GEO_TYPES' => ['path' => 'resources/public-runtime/engine/display-value.ts', 'name' => 'GEO_TYPES', 'grammar' => 'set'],
        'display_value.MEDIA_TYPES' => ['path' => 'resources/public-runtime/engine/display-value.ts', 'name' => 'MEDIA_TYPES', 'grammar' => 'set'],
        'semantic_validator.MEDIA_FIELD_TYPES' => ['path' => 'resources/public-runtime/engine/semantic-validator.ts', 'name' => 'MEDIA_FIELD_TYPES', 'grammar' => 'set'],
        'field_input.MEDIA_TYPES' => ['path' => 'resources/js/components/submissions/FieldInput.vue', 'name' => 'MEDIA_TYPES', 'grammar' => 'array'],
        'config_panel.NUMERIC_TYPES' => ['path' => 'resources/js/components/builder/ConfigPanel.vue', 'name' => 'NUMERIC_TYPES', 'grammar' => 'set'],
        // The only unnamed one: an inline literal inside `controlKind()`'s if-chain, anchored on the
        // statement it governs because there is no identifier to anchor on.
        'field_input.inline_text' => ['path' => 'resources/js/components/submissions/FieldInput.vue', 'name' => '(inline text literal)', 'grammar' => 'inline_text'],
    ];
}

/**
 * A measured FLOOR per declaration, in the idiom `DraftProjectionMirrorDriftTest` established.
 *
 * ⛔ THESE EXIST SO A REGEX THAT STARTS MATCHING TWO MEMBERS INSTEAD OF TWENTY-FIVE FAILS HERE rather
 * than reporting a clean mirror while blind. That arm caught a real under-read the first time it ran in
 * this repository — ES shorthand keys defeated a colon-only pattern — so it is not ceremony.
 *
 * @return array<string, int>
 */
function clientTypeMirrorFloors(): array
{
    return [
        'schema_mapping.SUPPORTED' => 25,
        'schema_mapping.HAS_OPTIONS' => 4,
        'schema_mapping.TEXT_TYPES' => 7,
        'display_value.HAS_OPTIONS' => 4,
        'display_value.GEO_TYPES' => 3,
        'display_value.MEDIA_TYPES' => 5,
        'semantic_validator.MEDIA_FIELD_TYPES' => 5,
        'field_input.MEDIA_TYPES' => 5,
        'config_panel.NUMERIC_TYPES' => 4,
        'field_input.inline_text' => 7,
    ];
}

/**
 * The members of one client declaration, parsed out of the real source file.
 *
 * Deliberately NOT a hand-copied list: a hand-copied list passes forever after someone edits the source,
 * which is the exact drift this file exists to catch.
 *
 * @return list<string>
 */
function clientTypeMembers(string $key): array
{
    $mirrors = clientTypeMirrors();

    expect($mirrors)->toHaveKey($key);

    $spec = $mirrors[$key];
    $path = dirname(__DIR__, 3).'/'.$spec['path'];
    $source = file_get_contents($path);

    expect($source)->toBeString("{$spec['path']} must be readable at {$path}");

    $name = preg_quote($spec['name'], '/');

    $pattern = match ($spec['grammar']) {
        // `new Set<string>([...])` and `new Set([...])` are both in use, in different files.
        'set' => '/const\s+'.$name.'\s*=\s*new\s+Set(?:<string>)?\(\s*\[(?<members>[^\]]*)\]\s*\)/',
        'array' => '/const\s+'.$name.'\s*=\s*\[(?<members>[^\]]*)\]/',
        // Anchored on the statement rather than on an identifier, because this one has no name.
        'inline_text' => "/\[(?<members>[^\]]*)\]\.includes\(t\)\) return 'text'/",
        default => throw new LogicException("unknown grammar {$spec['grammar']}"),
    };

    $matched = preg_match($pattern, (string) $source, $set);

    // ⛔ ANTI-VACUITY 1. A rename, or a shape change (a frozen array, a union type, a computed set, a
    // readonly tuple), must FAIL HERE rather than silently yield an empty list and let the equality
    // assertion below pass against nothing.
    expect($matched)->toBe(1, "{$spec['name']} is no longer a `{$spec['grammar']}` literal in {$spec['path']}");

    // Strip line comments before extracting: `SUPPORTED`'s members carry them, and one contains the word
    // "signature's" — an apostrophe inside the very span the member pattern scans.
    $members = preg_replace('~//[^\n]*~', '', $set['members']) ?? $set['members'];

    preg_match_all("/'([a-z_]+)'/", $members, $found);

    // ⛔ ANTI-VACUITY 2.
    expect($found[1])->not->toBeEmpty("parsed {$spec['name']} in {$spec['path']} but found no members");

    return $found[1];
}

/** @return list<string> the backing values of every field type satisfying the predicate */
function phpTypesWhere(callable $predicate): array
{
    return array_values(array_map(
        static fn (FieldType $t): string => $t->value,
        array_filter(FieldType::cases(), $predicate),
    ));
}

it('parses every client declaration it claims to guard, and reads all of it', function (): void {
    // ⛔ ANTI-VACUITY 3 — pin what was actually READ. Anti-vacuity 1 catches a parse that fails; this
    // catches a parse that SUCCEEDS against the wrong construct, or one whose member pattern silently
    // under-reads. The counts are a floor and are expected to rise with the catalogue.
    foreach (clientTypeMirrorFloors() as $key => $floor) {
        expect(clientTypeMembers($key))->toHaveCount($floor, "{$key} no longer holds {$floor} members");
    }
});

it('names only real field types, in every client declaration', function (): void {
    // Nothing else in the repository catches a typo'd type string. A member that is not a `FieldType`
    // backing value is dead weight at best and a silently-never-matching branch at worst — and it is the
    // ONLY assertion available for the two declarations that mirror no PHP predicate.
    $catalogue = array_map(static fn (FieldType $t): string => $t->value, FieldType::cases());

    // Collected and asserted as ONE list rather than per member: Pest's toContain() is VARIADIC, so a
    // second argument reads as a second expected VALUE rather than as a failure message — which silently
    // turns the assertion into a stricter one about a string nobody meant to look for. Measured here.
    $unknown = [];

    foreach (array_keys(clientTypeMirrors()) as $key) {
        foreach (clientTypeMembers($key) as $member) {
            if (! in_array($member, $catalogue, true)) {
                $unknown[] = "{$key} names `{$member}`";
            }
        }
    }

    expect($unknown)->toBe([]);
});

it('keeps the encode pages supported set byte-identical to the guest runtimes', function (): void {
    // `schema-mapping.ts`'s own comment declares this a mirror of `EncodeFormPresenter::SUPPORTED`.
    // The PHP side is read by REFLECTION rather than transcribed — the constant is private, and a
    // transcription here would be an eleventh copy of the thing being gated.
    $reflected = new ReflectionClassConstant(EncodeFormPresenter::class, 'SUPPORTED');
    /** @var list<FieldType> $supported */
    $supported = $reflected->getValue();

    expect(array_map(static fn (FieldType $t): string => $t->value, $supported))
        ->toEqualCanonicalizing(clientTypeMembers('schema_mapping.SUPPORTED'));
});

it('keeps both option-list mirrors equal to FieldType::hasOptions()', function (): void {
    // Asserted in BOTH directions: `toEqualCanonicalizing` fails on a missing member and on an extra one
    // alike, so neither side can quietly widen.
    $php = phpTypesWhere(static fn (FieldType $t): bool => $t->hasOptions());

    expect(clientTypeMembers('schema_mapping.HAS_OPTIONS'))->toEqualCanonicalizing($php)
        ->and(clientTypeMembers('display_value.HAS_OPTIONS'))->toEqualCanonicalizing($php);
});

it('keeps the geo mirror equal to FieldType::isGeo()', function (): void {
    expect(clientTypeMembers('display_value.GEO_TYPES'))
        ->toEqualCanonicalizing(phpTypesWhere(static fn (FieldType $t): bool => $t->isGeo()));
});

it('keeps all three media mirrors equal to FieldType::isMedia()', function (): void {
    // THREE copies of one set, in three files, two bundles apart. A layout branch added to one and
    // forgotten in another gives the guest runtime and the encode page different forms — which is the
    // drift class this census exists for, stated at its worst case.
    $php = phpTypesWhere(static fn (FieldType $t): bool => $t->isMedia());

    expect(clientTypeMembers('display_value.MEDIA_TYPES'))->toEqualCanonicalizing($php)
        ->and(clientTypeMembers('semantic_validator.MEDIA_FIELD_TYPES'))->toEqualCanonicalizing($php)
        ->and(clientTypeMembers('field_input.MEDIA_TYPES'))->toEqualCanonicalizing($php);
});

it('keeps the two text groupings equal to each other, because neither mirrors PHP', function (): void {
    // ⛔ THESE MIRROR NOTHING AND THEREFORE CANNOT BE CHECKED AGAINST PHP — measured, not assumed.
    // `ValueShape::Text` is `{short_text, long_text, email, phone, url, hidden}`; `FieldCategory::Text`
    // is five; `PrintAnswerArea::Comb` is eleven. None of them is this seven. They are a TypeScript-only
    // CONTROL-KIND grouping — the seventh partition of the same thirty-one cases — so the strongest true
    // statement available is that the two copies of it agree, which today they do byte for byte.
    expect(clientTypeMembers('schema_mapping.TEXT_TYPES'))
        ->toEqualCanonicalizing(clientTypeMembers('field_input.inline_text'));
});

it('records NUMERIC_TYPES as a DIVERGENCE from ValueShape, not as a mirror of it', function (): void {
    // ⚠️ `R-09f73330` says this one "mirrors nothing". That was true when it was filed and stopped being
    // true in the increment that filed it: `M112`'s `ValueShape::allowsOperator()` answers the identical
    // question — may this field take an ORDERED comparison — and answers it differently.
    //
    // The client omits `duration`. Consequence is bounded today (`ConditionRow.vue` reads the flag only to
    // decide whether a new fixed value is emitted as a number literal, not to filter operators), which is
    // why this is pinned rather than reconciled here. Reconciling it is filed as its own row.
    $phpOrdered = phpTypesWhere(
        static fn (FieldType $t): bool => ValueShape::for($t)->allowsOperator(ComparisonOperator::Gt),
    );
    $client = clientTypeMembers('config_panel.NUMERIC_TYPES');

    // array_diff PRESERVES KEYS, so both sides are re-indexed before comparison.
    expect(array_values(array_diff($phpOrdered, $client)))->toBe(['duration'])
        ->and(array_values(array_diff($client, $phpOrdered)))->toBe([]);
});
