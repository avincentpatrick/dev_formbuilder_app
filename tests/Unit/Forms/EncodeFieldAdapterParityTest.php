<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| M130 — the adapter census `R-048a3286` asked to land FIRST.
|--------------------------------------------------------------------------
|
| `FieldInput.vue` is the one control every channel renders a question through: the guest runtime, the
| builder preview, the encode page and its resume, edit and OCR-review modes. It decides WHICH control
| from the field it is handed, and three adapters hand it that field, each building its own copy:
|
|   · `EncodeFormPresenter::field()` (PHP) — the encode page, all four of its modes, and `Encode.vue`'s own
|     repeat loop, which passes presenter rows straight through;
|   · `FieldControl.vue` — the guest runtime's and the builder preview's flat questions;
|   · `InstanceField.vue` — their repeat instances.
|
| ⛔ THE ROW SAID "TWO SWITCHES, AND NO PARITY TEST BETWEEN THEM", AND THE PREMISE WAS FALSE. The second
| switch, `schema-mapping.ts`'s `controlFor()`, was read by nothing in production and `M130` deleted it.
| What CAN drift is these three builders: a member added to `EncodeField` and to one adapter, and
| forgotten in another, renders the question differently in that channel and nothing goes red — which is
| exactly how a layout hint or a note's content would ship to the guest page and not to the encode page.
| So every member `EncodeField` declares must be built by every adapter, or be exempt for a stated reason.
|
| Same technique as `FieldTypeMirrorDriftTest`: the sources are parsed, never transcribed, and every parse
| proves it read something before anything is compared — a pattern that stops matching must fail here,
| not hand the comparison an empty list it would pass against.
|
| No database and no container, so the repo root is computed from `__DIR__`.
*/

function encodeFieldAdapterSource(string $relative): string
{
    $path = dirname(__DIR__, 3).'/'.$relative;
    $source = file_get_contents($path);

    expect($source)->toBeString("{$relative} must be readable at {$path}");

    return str_replace("\r\n", "\n", (string) $source);
}

/** @return list<string> the members `EncodeField` declares, optional ones included */
function encodeFieldInterfaceMembers(): array
{
    $source = encodeFieldAdapterSource('resources/js/components/submissions/FieldInput.vue');

    // ⛔ ANTI-VACUITY 1: a rename or a shape change (a type alias, an intersection) fails here.
    $matched = preg_match('/export interface EncodeField \{\n(?<body>.*?)\n\}/s', $source, $interface);
    expect($matched)->toBe(1, '`EncodeField` is no longer an interface literal in FieldInput.vue');

    // Members sit at four spaces; comment lines and the inline option shape never start a member there.
    preg_match_all('/^ {4}([a-z_]+)\??:/m', $interface['body'], $found);

    // ⛔ ANTI-VACUITY 2.
    expect($found[1])->not->toBeEmpty('parsed `EncodeField` but found no members');

    return $found[1];
}

/** @return list<string> the keys one Vue adapter's `encodeField` computed builds */
function encodeFieldComputedKeys(string $relative): array
{
    $source = encodeFieldAdapterSource($relative);

    $matched = preg_match('/const encodeField = computed<EncodeField>\(\(\) => \(\{\n(?<body>.*?)\n\}\)\);/s', $source, $computed);
    expect($matched)->toBe(1, "{$relative} no longer builds `encodeField` as one computed object literal");

    // Four spaces deep, then a key followed by `:` or, for ES shorthand, `,`. The option map's nested
    // `value:`/`label:` and the upload block's `url:` sit deeper and are skipped by the anchor.
    preg_match_all('/^ {4}([a-z_]+)(?=\s*[:,])/m', $computed['body'], $found);

    expect($found[1])->not->toBeEmpty("parsed {$relative}'s `encodeField` but found no keys");

    return $found[1];
}

/** @return list<string> the keys `EncodeFormPresenter::field()` returns */
function encodeFieldPresenterKeys(): array
{
    $source = encodeFieldAdapterSource('app/Services/Submissions/EncodeFormPresenter.php');

    $start = strpos($source, 'private function field(Form $form, FormField $field');
    expect($start)->not->toBeFalse('`EncodeFormPresenter::field()` is no longer declared with that signature');

    $tail = substr($source, (int) $start);
    $matched = preg_match('/^ {8}\];/m', $tail, $end, PREG_OFFSET_CAPTURE);
    expect($matched)->toBe(1, '`EncodeFormPresenter::field()` no longer closes its returned array at eight spaces');

    preg_match_all("/^ {12}'([a-z_]+)' =>/m", substr($tail, 0, $end[0][1]), $found);

    expect($found[1])->not->toBeEmpty('parsed `EncodeFormPresenter::field()` but found no keys');

    return $found[1];
}

/**
 * The three adapters, and what each may leave out — every exemption with its reason, because an exemption
 * without one is just a drift this file agreed not to see.
 *
 * @return array<string, array{exempt: array<string, string>}>
 */
function encodeFieldAdapters(): array
{
    $noHiddenField = 'the guest runtime never renders a hidden field (`rendersNothing`), so it never passes where one comes from';
    $bannedInRepeats = 'StructuralValidationGate keeps this type out of a repeatable section, so no instance can carry it';

    return [
        'EncodeFormPresenter::field()' => ['exempt' => []],
        'resources/public-runtime/components/FieldControl.vue' => ['exempt' => [
            'prefill' => $noHiddenField,
            'prefill_value' => $noHiddenField,
        ]],
        'resources/public-runtime/components/InstanceField.vue' => ['exempt' => [
            'prefill' => $noHiddenField,
            'prefill_value' => $noHiddenField,
            'matrix' => $bannedInRepeats,
            'geo' => $bannedInRepeats,
            'media' => $bannedInRepeats,
            'upload' => 'media is kept out of a repeatable section, so an instance has no upload endpoint',
        ]],
    ];
}

/** @return list<string> */
function encodeFieldAdapterKeys(string $adapter): array
{
    return $adapter === 'EncodeFormPresenter::field()'
        ? encodeFieldPresenterKeys()
        : encodeFieldComputedKeys($adapter);
}

it('reads all of EncodeField and all of every adapter', function (): void {
    // ⛔ ANTI-VACUITY 3 — a parse that SUCCEEDS against too little. Floors measured on the tree this file
    // was written against; they are expected to rise as members are added.
    expect(count(encodeFieldInterfaceMembers()))->toBeGreaterThanOrEqual(15, '`EncodeField` read short')
        ->and(count(encodeFieldPresenterKeys()))->toBeGreaterThanOrEqual(15, '`EncodeFormPresenter::field()` read short')
        ->and(count(encodeFieldComputedKeys('resources/public-runtime/components/FieldControl.vue')))->toBeGreaterThanOrEqual(13, '`FieldControl.vue` read short')
        ->and(count(encodeFieldComputedKeys('resources/public-runtime/components/InstanceField.vue')))->toBeGreaterThanOrEqual(9, '`InstanceField.vue` read short');
});

it('has every adapter build every member EncodeField declares, unless exempt for a stated reason', function (): void {
    $members = encodeFieldInterfaceMembers();
    $missing = [];

    foreach (encodeFieldAdapters() as $adapter => $spec) {
        foreach (array_diff($members, encodeFieldAdapterKeys($adapter), array_keys($spec['exempt'])) as $member) {
            $missing[] = "{$adapter} does not build `{$member}`";
        }
    }

    expect($missing)->toBe([]);
});

it('has no adapter build a member EncodeField does not declare', function (): void {
    // The reverse direction, which also catches a pattern that started reading nested keys.
    $members = encodeFieldInterfaceMembers();
    $extra = [];

    foreach (array_keys(encodeFieldAdapters()) as $adapter) {
        foreach (array_diff(encodeFieldAdapterKeys($adapter), $members) as $key) {
            $extra[] = "{$adapter} builds `{$key}`, which EncodeField does not declare";
        }
    }

    expect($extra)->toBe([]);
});

it('lets no exemption outlive its reason', function (): void {
    // An adapter that builds a member it is exempt from has made the exemption false; and an exemption
    // naming no `EncodeField` member exempts nothing. Either way the list must shrink.
    $members = encodeFieldInterfaceMembers();
    $stale = [];

    foreach (encodeFieldAdapters() as $adapter => $spec) {
        $keys = encodeFieldAdapterKeys($adapter);

        foreach (array_keys($spec['exempt']) as $member) {
            if (in_array($member, $keys, true)) {
                $stale[] = "{$adapter} builds `{$member}` now, so its exemption is stale";
            }
            if (! in_array($member, $members, true)) {
                $stale[] = "{$adapter} is exempt from `{$member}`, which EncodeField does not declare";
            }
        }
    }

    expect($stale)->toBe([]);
});
