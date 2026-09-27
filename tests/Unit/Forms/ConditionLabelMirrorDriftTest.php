<?php

declare(strict_types=1);

use App\Enums\ComparisonOperator;

/*
|--------------------------------------------------------------------------
| The operator SENTENCE labels: one PHP source, two client copies, now gated (Increment M115, `D59`).
|--------------------------------------------------------------------------
| `ComparisonOperator::sentenceLabel()` renders an operator inside a sentence — "Age is at least 18".
| The client shipped those exact strings first, TWICE and byte-identically: `ConditionRow.vue`'s
| `COMPARATOR_LABELS` builds the condition editor's operator `<select>`, and `condition-describer.ts`'s
| `COMPARATORS` builds the plain-English reading of a stored expression. Neither was gated by anything.
|
| ⛔ WHY A GATE AND NOT THE REFACTOR `D59` ASKS FOR. The recorded answer says `ConditionRow.vue` "stops
| owning its own set". Measured, it cannot: its row vocabulary is LARGER than this enum — it also offers
| `not_blank` and `excludes`, which have no `ComparisonOperator` case at all because the expression grammar
| negates through `not(...)` rather than through an operator, and a second COUNT phrasing ("has at least")
| for a `count()` subject that PHP has no concept of. Sourcing six of its ten entries from the server and
| leaving four client-side would split ONE vocabulary across TWO sources — strictly worse than what this
| file does, which is make the overlap unable to drift in silence.
|
| ⚠️ SO THE DIVERGENCES ARE DECLARED, WITH THEIR REASONS, AND ASSERTED TO STILL EXIST. A divergence nobody
| re-checks becomes a licence; `DocumentedEnumMirrorDriftTest` has a stale-divergence arm for exactly that
| reason and this file carries the same idea.
|
| ⚠️ A GREEN RUN HERE PROVES NOTHING ABOUT THIS GATE. It was written against already-agreeing data; the
| evidence that it works is `scripts/mutate.php`, recorded in the claim.
|
| No database and no container: this reads source text, so the repo root comes from `__DIR__`.
*/

/** The two client files that own a copy of the six shared sentence labels, and the declaration in each. */
function sentenceLabelMirrors(): array
{
    return [
        'condition_row.COMPARATOR_LABELS' => ['resources/js/components/builder/ConditionRow.vue', 'COMPARATOR_LABELS'],
        'condition_describer.COMPARATORS' => ['resources/js/components/builder/condition-describer.ts', 'COMPARATORS'],
    ];
}

/** The six operators both sides render, keyed by `ComparisonOperator` value. */
function sharedComparators(): array
{
    return ['eq', 'neq', 'gt', 'lt', 'gte', 'lte'];
}

function mirrorSource(string $path): string
{
    $absolute = dirname(__DIR__, 3).'/'.$path;
    $source = file_get_contents($absolute);

    expect($source)->toBeString("{$path} must be readable at {$absolute}");

    return (string) $source;
}

/**
 * Parse `const NAME: Record<Comparator, string> = { eq: 'is', … }` into key => label.
 *
 * @return array<string, string>
 */
function parsedLabelMap(string $path, string $name): array
{
    $source = mirrorSource($path);

    $matched = preg_match(
        '/const\s+'.preg_quote($name, '/').'\s*:\s*Record<[^>]*>\s*=\s*\{(?<body>[^}]*)\}/',
        $source,
        $block
    );

    // ⛔ ANTI-VACUITY 1: a rename, a reshape (a `Map`, a frozen object, a computed set) or a move must fail
    // LOUDLY here rather than yield an empty map that every comparison below would then pass against.
    expect($matched)->toBe(1, "{$name} is no longer a `Record<…>` object literal in {$path}");

    // Strip line comments before harvesting: a `//` note containing an apostrophe would otherwise be
    // harvested as a pair. `DocumentedEnumMirrorDriftTest` records the sibling defect, where a `;` inside a
    // comment truncated a union to half its members.
    $body = (string) (preg_replace('~//[^\n]*~', '', $block['body']) ?? $block['body']);

    preg_match_all("/(?<key>[a-z_]+)\s*:\s*'(?<label>[^']*)'/", $body, $pairs, PREG_SET_ORDER);

    // ⛔ ANTI-VACUITY 2: an empty harvest is a broken parser, not an empty map.
    expect($pairs)->not->toBeEmpty("parsed {$name} in {$path} but found no key/label pairs");

    $map = [];
    foreach ($pairs as $pair) {
        $map[$pair['key']] = $pair['label'];
    }

    return $map;
}

it('parses six labels out of each client copy, so neither comparison can go vacuous', function (): void {
    // ⛔ ANTI-VACUITY 3: the floor. A regex that degrades to two members of six would satisfy every
    // equality below while covering almost nothing — the under-read `M112` measured on its first run.
    foreach (sentenceLabelMirrors() as $label => [$path, $name]) {
        $map = parsedLabelMap($path, $name);

        expect($map)->toHaveCount(6, "{$label} no longer declares exactly six operators");
        expect(array_keys($map))->toEqualCanonicalizing(sharedComparators(), "{$label} declares a different operator set");
    }
});

it('keeps both client copies byte-identical to ComparisonOperator::sentenceLabel()', function (): void {
    foreach (sentenceLabelMirrors() as $label => [$path, $name]) {
        $map = parsedLabelMap($path, $name);

        foreach (sharedComparators() as $value) {
            $operator = ComparisonOperator::from($value);

            expect($map[$value])->toBe(
                $operator->sentenceLabel(),
                "{$label} renders {$value} as “{$map[$value]}” and PHP renders it as “{$operator->sentenceLabel()}”"
            );
        }
    }
});

it('keeps the two operators rendered inline agreeing with the enum as well', function (): void {
    // `is_null` and `contains` are not in either map — they are rendered inline, by a ternary in
    // `clauseOf()` and by an option pushed onto the list. Parsing a ternary would anchor this gate on
    // surrounding code, which is the un-anchoring `DraftProjectionMirrorDriftTest` warns against, so the
    // assertion is presence of the exact string the enum produces. A rename on either side reddens.
    foreach (sentenceLabelMirrors() as $label => [$path, $_name]) {
        $source = mirrorSource($path);

        foreach ([ComparisonOperator::IsNull, ComparisonOperator::Contains] as $operator) {
            expect(str_contains($source, "'".$operator->sentenceLabel()."'"))->toBeTrue(
                "{$label}'s file no longer contains the literal “{$operator->sentenceLabel()}” for {$operator->value}"
            );
        }
    }
});

it('holds the client-only operators as a DECLARED divergence, with no PHP case to point them at', function (): void {
    // The two values that make `D59`'s "stop owning its own set" unexecutable. If a future increment adds
    // `not_blank` or `excludes` to `ComparisonOperator` — which would mean a DB CHECK change, since the
    // column is constrained from the enum — this arm reddens and the decision can be revisited deliberately
    // rather than by accident.
    $source = mirrorSource('resources/js/components/builder/ConditionRow.vue');

    foreach (['not_blank', 'excludes'] as $clientOnly) {
        expect(str_contains($source, "value: '".$clientOnly."'"))->toBeTrue(
            "ConditionRow.vue no longer offers {$clientOnly}; if it moved into ComparisonOperator, fold it into the mirror above"
        );
        expect(ComparisonOperator::tryFrom($clientOnly))->toBeNull(
            "{$clientOnly} now HAS a ComparisonOperator case — the divergence recorded here is stale"
        );
    }
});

it('holds the count phrasing as a DECLARED divergence, differing from the enum on every operator', function (): void {
    // `COUNT_COMPARATORS` is a second rendering of the same six against a `count()` subject, because "is
    // more than 0 entries" is not English. It must NOT be reconciled with PHP: asserting it differs is what
    // stops someone "fixing" the drift by pointing the count phrasing at `sentenceLabel()`.
    $counts = parsedLabelMap('resources/js/components/builder/condition-describer.ts', 'COUNT_COMPARATORS');

    expect($counts)->toHaveCount(6);

    foreach (sharedComparators() as $value) {
        expect($counts[$value])->not->toBe(
            ComparisonOperator::from($value)->sentenceLabel(),
            "the count phrasing for {$value} now equals the sentence label; one of the two is wrong"
        );
    }
});
