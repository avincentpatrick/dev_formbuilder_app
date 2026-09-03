<?php

declare(strict_types=1);

use App\Enums\SettingKey;
use App\Enums\SettingScope;

/*
|--------------------------------------------------------------------------
| The settings vocabulary §20 publishes vs. the enum that defines it (M68).
|--------------------------------------------------------------------------
| `docs/data-dictionary.md` §20 is where anyone inventorying tenant configuration reads the
| `settings.key` catalog, and until M68 it omitted `security.require_two_factor` — a TENANT-SCOPED
| SECURITY POLICY, live since I8a and enforced by `EnforceTenantTwoFactor`. The backlog row named
| one omission in one place; there were two places, and the second also omitted
| `maintenance.message`'s default. §20 named three of the five defaults.
|
| ⛔ WHY BOTH PASSAGES ARE GATED AND NOT JUST THE CATALOG. They are different claims about the same
| enum and they drifted independently: the `key` column says which keys EXIST and on which side, and
| Design Note 2 says what each RESOLVES TO when no row exists. Gating one would have left the other
| free to go stale, which is how the second omission survived the increment that filed the first.
|
| ⛔ WHY THIS IS A TEST AND NOT A SIXTH LINT SCRIPT (M58, and `DocumentedCommandDriftTest`'s
| argument applies verbatim). `php artisan test` already discovers `tests/Feature`, so this needs no
| `composer.json` alias, no `quality` entry and no `ci.yml` step. A `scripts/*-lint.php` sibling
| would need all three, and `scripts/mutate.php` drives Pest in a container and nothing else — so a
| script would have to reimplement that harness's discipline by hand at the call site, which M42
| recorded as the weaker form.
|
| ⚠️ EVERY ARM COMPARES TWO SETS FOR EQUALITY, NEVER FOR CONTAINMENT. M56's lesson is that Laravel's
| own JSON assertions are all subset checks, which let `/api/v1` publish a shape it never returned
| through 25+ green assertions. A catalog gate that asserts "the document mentions every case" is
| that same defect: it passes over a document naming a key the enum does not have. Both directions
| are asserted, in both arms.
|
| ⚠️ THE SIDE LABELS ARE READ PER CLAUSE, NOT PER LINE. A key documented on the WRONG side is
| invisible rather than wrong — both readers filter on `tenant_id`, so the row is simply never found
| — which is exactly the silence `SettingKey::scope()` exists to prevent and the reason this arm is
| here at all rather than only the membership one.
|
| ⚠️ `modules.<plan_feature_key>` IS DELIBERATELY OUT OF SCOPE. It is an open namespace keyed by
| `ToggleableModules`, not a case, and the enum's own docblock records that enumerating it twice is
| how two lists come to disagree. The key pattern below cannot match it (it carries `<`), and that
| is by construction rather than by luck.
|
| ⚠️ Reads one named file and iterates no directory, so the partial `RecursiveDirectoryIterator`
| descent of the Windows bind mount that `docs/gate-baselines.md` records for the lint gates cannot
| reach it. Equally correct on the host and in the container. No database and no Vite.
|
| Helper names are prefixed `documentedSettingKey*` deliberately: Pest loads every file in a
| directory into one process, so a same-named file-scope helper is a fatal redeclaration — which is
| how M67's first CI run died before a single test executed.
*/

/** The document whose §20 catalog is gated. */
const DOCUMENTED_SETTING_KEY_DOCUMENT = 'docs/data-dictionary.md';

/** The section heading, matched whole so a renumbering fails loudly instead of silently. */
const DOCUMENTED_SETTING_KEY_HEADING = '## 20. `settings`';

/**
 * Plausible floors for the discovery pass.
 *
 * Measured on the tree this gate was written against: §20 is 33 lines and both passages yield 5
 * keys. These sit below that so ordinary editing does not trip them, and above zero so a renamed
 * document, a renumbered section or a broken parser fails LOUDLY rather than reporting green over
 * nothing. Four, because there are four independent ways for this gate to pass while blind: the
 * document could move, the heading could change, the table row could stop matching, or the
 * defaults paragraph could stop matching.
 */
const DOCUMENTED_SETTING_KEY_MIN_SECTION_LINES = 15;

const DOCUMENTED_SETTING_KEY_MIN_KEYS = 4;

/**
 * The prose vocabulary Design Note 2 may use for a default, and what each means.
 *
 * A closed map on purpose. A default of a shape not listed here fails as "unknown default prose"
 * rather than being coerced to something plausible — which is the `toContain`/`toHaveKey` family of
 * traps (M30, M43) arriving through a parser instead of through an expectation.
 */
const DOCUMENTED_SETTING_KEY_DEFAULT_VOCABULARY = [
    'true' => true,
    'false' => false,
    'the empty string' => '',
];

/**
 * Every line of §20, keyed by 1-based line number in the document.
 *
 * `explode` and never `preg_split` on the newline class — PCRE's `\R` without `/u` matches the byte
 * 0x85 INSIDE UTF-8 characters, and this document is full of them, which silently shifts every line
 * number after the first (M42).
 *
 * @return array<int, string>
 */
function documentedSettingKeySection(): array
{
    $path = base_path(DOCUMENTED_SETTING_KEY_DOCUMENT);

    expect(is_file($path))->toBeTrue(
        'Discovery floor: '.DOCUMENTED_SETTING_KEY_DOCUMENT.' was not found at '.$path.
        '. A moved or renamed document makes this gate blind, so it fails instead.'
    );

    $lines = explode("\n", (string) file_get_contents($path));

    $start = null;
    foreach ($lines as $index => $line) {
        if (trim($line) === DOCUMENTED_SETTING_KEY_HEADING) {
            $start = $index;

            break;
        }
    }

    expect($start)->not->toBeNull(
        'Discovery floor: the heading "'.DOCUMENTED_SETTING_KEY_HEADING.'" is not in '.
        DOCUMENTED_SETTING_KEY_DOCUMENT.'. If §20 was renumbered, update this constant — do not '.
        'loosen the match, because a heading matched loosely can select the wrong section.'
    );

    $section = [];
    foreach ($lines as $index => $line) {
        if ($index < $start) {
            continue;
        }

        // The next top-level heading ends the section. The heading itself is included so a failure
        // message can quote it.
        if ($index > $start && str_starts_with($line, '## ')) {
            break;
        }

        $section[$index + 1] = $line;
    }

    expect(count($section))->toBeGreaterThanOrEqual(
        DOCUMENTED_SETTING_KEY_MIN_SECTION_LINES,
        'Discovery floor: §20 yielded only '.count($section).' lines. A section that has shrunk '.
        'below the floor is more likely a broken parser than a real edit.'
    );

    return $section;
}

/**
 * The one line of a section matching a predicate, or a loud failure naming how many matched.
 *
 * ⚠️ EXACTLY ONE, NEVER "THE FIRST". A second matching line means the document grew a passage this
 * gate does not know about, and silently reading the first is how a gate ends up asserting against
 * half its subject.
 *
 * @param  array<int, string>  $section
 * @return array{int, string}
 */
function documentedSettingKeyUniqueLine(array $section, callable $matches, string $what): array
{
    $found = [];
    foreach ($section as $lineNumber => $line) {
        if ($matches($line)) {
            $found[$lineNumber] = $line;
        }
    }

    expect(count($found))->toBe(
        1,
        'Discovery floor: expected exactly ONE '.$what.' in §20 of '.
        DOCUMENTED_SETTING_KEY_DOCUMENT.', found '.count($found).
        ' (lines: '.implode(', ', array_keys($found)).'). '.
        'Zero means the parser is blind; two means a passage this gate does not read has appeared.'
    );

    $lineNumber = (int) array_key_first($found);

    return [$lineNumber, $found[$lineNumber]];
}

/**
 * Every dot-namespaced settings key backticked in a fragment of prose.
 *
 * The pattern is deliberately narrow: two lowercase snake_case segments joined by a dot. That
 * admits every real key and excludes `varchar(100)`, `tenant_id`, `modules.*`,
 * `modules.<plan_feature_key>` and every class or file name in the same cell — so the extraction
 * needs no exclusion list that could rot.
 *
 * @return list<string>
 */
function documentedSettingKeyMentions(string $prose): array
{
    preg_match_all('/`([a-z_]+\.[a-z_]+)`/', $prose, $matches);

    return array_values(array_unique($matches[1]));
}

/**
 * §20's `key` column: every documented key, mapped to the side the document puts it on.
 *
 * Sides are attributed PER SEMICOLON-DELIMITED CLAUSE. One cell carries both groups plus a trailing
 * caveat, so a line-level search would attribute every key to whichever label it happened to find
 * first — and that error would present as the gate agreeing with the enum while reading it wrong.
 *
 * @return array<string, SettingScope>
 */
function documentedSettingKeyCatalog(): array
{
    [$lineNumber, $line] = documentedSettingKeyUniqueLine(
        documentedSettingKeySection(),
        static fn (string $candidate): bool => str_starts_with(trim($candidate), '| `key` |'),
        'table row for the `key` column'
    );

    $catalog = [];
    foreach (explode(';', $line) as $clause) {
        $isTenant = str_contains($clause, 'tenant-only');
        $isPlatform = str_contains($clause, 'platform-only');

        if ($isTenant === $isPlatform) {
            // Neither label, or both: the clause is prose rather than a group. A key mentioned
            // there is not being placed on a side, so it must not be silently attributed to one.
            expect(documentedSettingKeyMentions($clause))->toBe(
                [],
                'Line '.$lineNumber.' of '.DOCUMENTED_SETTING_KEY_DOCUMENT.' mentions a settings '.
                'key in a clause carrying neither "tenant-only" nor "platform-only" (or carrying '.
                'both): '.trim($clause).'. Every key in this cell must sit in a clause that says '.
                'which side it is stored on, because a key on the wrong side is invisible rather '.
                'than wrong.'
            );

            continue;
        }

        foreach (documentedSettingKeyMentions($clause) as $key) {
            $catalog[$key] = $isTenant ? SettingScope::Tenant : SettingScope::Platform;
        }
    }

    expect(count($catalog))->toBeGreaterThanOrEqual(
        DOCUMENTED_SETTING_KEY_MIN_KEYS,
        'Discovery floor: only '.count($catalog).' key(s) were extracted from §20\'s `key` row at '.
        'line '.$lineNumber.'. Below the floor this gate is reading the document wrong rather than '.
        'finding it thin.'
    );

    return $catalog;
}

/**
 * Design Note 2's defaults: every documented key, mapped to the value it says a read resolves to.
 *
 * @return array<string, bool|string>
 */
function documentedSettingKeyDefaults(): array
{
    [$lineNumber, $line] = documentedSettingKeyUniqueLine(
        documentedSettingKeySection(),
        static fn (string $candidate): bool => str_contains($candidate, 'the defaults live in PHP'),
        'Design Note recording where the defaults live'
    );

    // `/u` is required: the arrow is U+21D2, and without it the class-based tail match walks into
    // the middle of a multi-byte character.
    preg_match_all('/`([a-z_]+\.[a-z_]+)` ⇒ ([^,)]+)/u', $line, $matches, PREG_SET_ORDER);

    $defaults = [];
    foreach ($matches as $match) {
        $prose = trim($match[2]);

        // ⛔ `array_key_exists` + `toBeTrue`, NOT `toHaveKey($prose, $message)`. `toHaveKey`'s second
        // argument is the expected VALUE, not a message — the `toContain` family of traps (M30, M43)
        // — so the message form asserts that the vocabulary maps this key to that whole sentence.
        // The first draft of this file did exactly that and went red on a document that was CORRECT.
        expect(array_key_exists($prose, DOCUMENTED_SETTING_KEY_DEFAULT_VOCABULARY))->toBeTrue(
            'Line '.$lineNumber.' of '.DOCUMENTED_SETTING_KEY_DOCUMENT.' documents `'.$match[1].
            '` as defaulting to "'.$prose.'", which is not in this gate\'s closed vocabulary ('.
            implode(', ', array_keys(DOCUMENTED_SETTING_KEY_DEFAULT_VOCABULARY)).'). Add the new '.
            'shape to DOCUMENTED_SETTING_KEY_DEFAULT_VOCABULARY rather than widening the parser: a '.
            'default coerced to something plausible is worse than one that fails.'
        );

        $defaults[$match[1]] = DOCUMENTED_SETTING_KEY_DEFAULT_VOCABULARY[$prose];
    }

    expect(count($defaults))->toBeGreaterThanOrEqual(
        DOCUMENTED_SETTING_KEY_MIN_KEYS,
        'Discovery floor: only '.count($defaults).' default(s) were extracted from the Design Note '.
        'at line '.$lineNumber.'. Below the floor this gate is reading the paragraph wrong.'
    );

    return $defaults;
}

it('documents exactly the settings keys the enum defines, in both directions', function (): void {
    $documented = array_keys(documentedSettingKeyCatalog());
    $defined = SettingKey::values();

    sort($documented);
    sort($defined);

    // Equality, not containment. A subset check passes over a document that names a key the enum
    // does not have, which is the direction M56 was bitten in.
    expect($documented)->toBe(
        $defined,
        'The §20 `key` catalog and `SettingKey` disagree. Missing from the document: '.
        (implode(', ', array_diff($defined, $documented)) ?: 'none').'. Documented but not a case: '.
        (implode(', ', array_diff($documented, $defined)) ?: 'none').'.'
    );
});

it('puts every documented key on the side the enum stores it on', function (): void {
    $catalog = documentedSettingKeyCatalog();

    foreach (SettingKey::cases() as $case) {
        // `array_key_exists` for the reason given at the vocabulary check above: `toHaveKey`'s second
        // argument is the expected value, so the message form would assert the catalog maps this key
        // to that sentence.
        expect(array_key_exists($case->value, $catalog))->toBeTrue(
            $case->value.' is not in the §20 `key` catalog.'
        );

        expect($catalog[$case->value])->toBe(
            $case->scope(),
            // `->name`, not `->value`: SettingScope is a PURE enum. PHP builds this argument on
            // every iteration, pass or fail, so `->value` here was an ErrorException on the first
            // case rather than a bad message on a failing one — loud, and only by luck.
            'The document places `'.$case->value.'` on the '.$catalog[$case->value]->name.
            ' side; `SettingKey::scope()` stores it on the '.$case->scope()->name.' side. A key '.
            'read from the wrong side is invisible rather than wrong, because both readers filter '.
            'on tenant_id.'
        );
    }
});

it('documents exactly the defaults the enum resolves to, in both directions', function (): void {
    $documented = documentedSettingKeyDefaults();
    $defined = [];

    foreach (SettingKey::cases() as $case) {
        $defined[$case->value] = $case->default();
    }

    ksort($documented);
    ksort($defined);

    expect(array_keys($documented))->toBe(
        array_keys($defined),
        'The §20 defaults paragraph and `SettingKey::default()` cover different keys. Missing from '.
        'the document: '.(implode(', ', array_diff(array_keys($defined), array_keys($documented))) ?: 'none').
        '. Documented but not a case: '.
        (implode(', ', array_diff(array_keys($documented), array_keys($defined))) ?: 'none').'.'
    );

    // Compared with ===, so `false` and `''` cannot satisfy each other. Two of the five defaults are
    // falsy and one of those is a string, which is precisely the pair a loose comparison merges.
    foreach ($defined as $key => $value) {
        expect($documented[$key])->toBe(
            $value,
            'The document says `'.$key.'` defaults to '.var_export($documented[$key], true).
            ' and `SettingKey::default()` returns '.var_export($value, true).'.'
        );
    }
});
