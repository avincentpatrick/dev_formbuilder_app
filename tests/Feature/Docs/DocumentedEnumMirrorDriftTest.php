<?php

declare(strict_types=1);

use App\Enums\AccentToken;
use App\Enums\AnalyticsAxis;
use App\Enums\AnalyticsFormSelection;
use App\Enums\AnalyticsGranularity;
use App\Enums\ComparisonOperator;
use App\Enums\DomainVerificationFailure;
use App\Enums\FontSizeScale;
use App\Enums\FormBotChallenge;
use App\Enums\GraphNoticeKind;
use App\Enums\LogicOperator;
use App\Enums\PrefillSource;
use App\Enums\RequiredMode;
use App\Enums\SsoConnectionStatus;
use App\Enums\ThemeMode;
use App\Enums\ValidationRuleType;
use App\Services\Expressions\ArithmeticOperator;
use App\Services\Expressions\LiteralKind;
use App\Services\Expressions\NodeKind;
use App\Services\Expressions\TokenType;

/*
|--------------------------------------------------------------------------
| A TypeScript union that mirrors a PHP enum is a SECOND COPY, and nothing compared them (M89).
|--------------------------------------------------------------------------
| Filed as docs/feature-backlog.md:8811 after M88 corrected `ComparisonOperator` in the data
| dictionary and found the public runtime holding a third copy of the same vocabulary. M88's
| DocumentedEnumCatalogDriftTest compares the DOCUMENT to PHP, so a TypeScript mirror going stale in
| the other direction is invisible to it.
|
| ⛔ THE ROW SAID THE INSTRUMENT WAS NOT OBVIOUS AND THAT CHOOSING ONE WAS THE WORK. IT WAS ALREADY
| CHOSEN. Increment J3a shipped tests/Feature/Notifications/NotificationTypeParityTest.php, which
| regex-reads a union off disk and compares it to an enum; tests/Unit/Forms/PdfFieldRoleTest.php does
| the same for a `new Set` literal, and tests/Unit/Navigation/ShellAbilityParityTest.php for an
| interface. The parser won three times before this row was filed. This gate generalises those.
|
| ⛔ A GENERATOR WAS RECONSIDERED AND IS STILL WRONG HERE, for reasons measured rather than assumed:
| four of the mirrors diverge from their enum DELIBERATELY (see MIRROR_DIVERGENCES), the mirror files
| carry hand-written rationale docblocks a generator would destroy, and the mirrors are spread across
| single lines inside larger hand-authored contract files that no generator can own.
|
| ⛔ WHY THERE ARE TWO GRAMMARS AND A ROW MUST MATCH EXACTLY ONE. Four live mirrors — including the
| `bot_challenge` copy the row itself cites — are not `export type` declarations at all but unions
| inline in an object property, which the J3a grammar returns NO MATCH for. Silently skipping them is
| the vacuous-pass shape this repository keeps paying for, so an unmatched row is a failure.
|
| ⚠️ COMMENTS ARE STRIPPED BEFORE THE MEMBERS ARE READ, and that is not tidiness. A `;` inside a `//`
| comment in a union body truncates the non-greedy capture: resources/public-runtime/lib/types.ts's
| `ErrorKind` carries `` `error.code`; `` in a comment and a naive read returns 6 of its 12 members —
| an under-read no anti-vacuity floor would catch, because 6 is not 0.
|
| ⚠️ CASE COLLECTION BRANCHES ON BackedEnum. `LiteralKind` is a PURE enum; `(string) $case->value` on
| it raises a fatal Error rather than failing an assertion.
|
| Files are read with explode("\n") and never preg_split on a newline class without /u — the M42 trap;
| resources/public-runtime/composables/useFormRuntime.ts alone carries ~1,900 high bytes, enough that
| grep reports it as binary.
|
| Helper names are prefixed `enumMirror*`: Pest loads a directory into one process, so a same-named
| file-scope helper is a fatal redeclaration.
*/

/**
 * The mirrors, declared rather than discovered.
 *
 * grammar `type`     — `export type NAME = 'a' | 'b';`, including multi-line leading-pipe form.
 * grammar `property` — `NAME: 'a' | 'b';` inline in an object type or interface.
 *
 * @var array<int, array{0: string, 1: string, 2: class-string, 3: string}>
 */
const ENUM_MIRRORS = [
    ['resources/public-runtime/engine/enums.ts', 'ComparisonOperator', ComparisonOperator::class, 'type'],
    ['resources/public-runtime/engine/enums.ts', 'ArithmeticOperator', ArithmeticOperator::class, 'type'],
    ['resources/public-runtime/engine/enums.ts', 'LogicOperator', LogicOperator::class, 'type'],
    ['resources/public-runtime/engine/enums.ts', 'ValidationRuleType', ValidationRuleType::class, 'type'],
    ['resources/public-runtime/engine/enums.ts', 'RequiredMode', RequiredMode::class, 'type'],
    ['resources/public-runtime/engine/enums.ts', 'LiteralKind', LiteralKind::class, 'type'],
    ['resources/public-runtime/engine/enums.ts', 'NodeKind', NodeKind::class, 'type'],
    ['resources/public-runtime/engine/tokens.ts', 'TokenType', TokenType::class, 'type'],
    ['resources/js/types/inertia.d.ts', 'ThemeMode', ThemeMode::class, 'type'],
    ['resources/js/types/inertia.d.ts', 'AccentToken', AccentToken::class, 'type'],
    ['resources/js/types/inertia.d.ts', 'FontSizeScale', FontSizeScale::class, 'type'],
    ['resources/js/components/analytics/types.ts', 'Axis', AnalyticsAxis::class, 'type'],
    ['resources/js/components/analytics/types.ts', 'Granularity', AnalyticsGranularity::class, 'type'],
    ['resources/js/components/analytics/types.ts', 'Selection', AnalyticsFormSelection::class, 'type'],
    ['resources/js/components/sso/types.ts', 'SsoStatus', SsoConnectionStatus::class, 'type'],
    ['resources/public-runtime/lib/prefill.ts', 'PrefillSource', PrefillSource::class, 'type'],
    ['resources/js/components/forms/types.ts', 'bot_challenge', FormBotChallenge::class, 'property'],
    ['resources/public-runtime/lib/types.ts', 'bot_challenge', FormBotChallenge::class, 'property'],
    ['resources/js/components/domains/types.ts', 'failure_reason', DomainVerificationFailure::class, 'property'],
    ['resources/js/components/builder/logic-rail.ts', 'kind', GraphNoticeKind::class, 'property'],
];

/**
 * Mirrors that deliberately disagree with their enum. Each entry is a FINDING, not a convenience, and
 * the arm below asserts the divergence is still the one described — a stale exception is the failure
 * mode an exception map has.
 *
 * @var array<string, array{php_only: list<string>, ts_only: list<string>, why: string}>
 */
const MIRROR_DIVERGENCES = [
    // resources/public-runtime/lib/prefill.ts:10 states it: absent/`none` is expressed as `null` here.
    'PrefillSource' => [
        'php_only' => ['none'],
        'ts_only' => [],
        'why' => 'prefill.ts expresses "no prefill" as null rather than as a member',
    ],
];

/**
 * Vocabularies already gated elsewhere, so this file does not hold a second copy of the assertion.
 * The arm below asserts the gating file still exists — that is what stops this list becoming a
 * silent exemption once the gate it names is deleted or renamed.
 *
 * @var array<string, string>
 */
const MIRRORS_GATED_ELSEWHERE = [
    'NotificationTypeKey' => 'tests/Feature/Notifications/NotificationTypeParityTest.php',
    'RENDERS_NOTHING' => 'tests/Unit/Forms/PdfFieldRoleTest.php',
];

/** Read a repository file, or fail loudly naming it. */
function enumMirrorRead(string $relative): string
{
    $path = base_path($relative);

    expect(is_file($path))->toBeTrue("declared mirror file is missing: {$relative}");

    return (string) file_get_contents($path);
}

/**
 * Strip `//` line comments so a `;` inside one cannot truncate a union body. Block comments are left
 * alone: no live mirror puts one inside a declaration, and removing them naively would eat a block
 * terminator occurring inside a string literal.
 */
function enumMirrorStripComments(string $source): string
{
    $out = [];

    foreach (explode("\n", $source) as $line) {
        $at = strpos($line, '//');
        $out[] = $at === false ? $line : substr($line, 0, $at);
    }

    return implode("\n", $out);
}

/**
 * The declared body of one mirror, or null when the grammar does not match.
 */
function enumMirrorBody(string $source, string $name, string $grammar): ?string
{
    $source = enumMirrorStripComments($source);

    $pattern = $grammar === 'type'
        ? '/export\s+type\s+'.preg_quote($name, '/').'\s*=(.*?);/s'
        : '/(?:^|[{;\n])\s*'.preg_quote($name, '/').'\??\s*:(.*?);/s';

    return preg_match($pattern, $source, $m) === 1 ? $m[1] : null;
}

/**
 * The single-quoted members of a union body, in declaration order.
 *
 * @return list<string>
 */
function enumMirrorMembers(string $body): array
{
    preg_match_all("/'([A-Za-z0-9_.:-]+)'/", $body, $m);

    /** @var list<string> $found */
    $found = $m[1];

    return $found;
}

/**
 * A PHP enum's vocabulary. A pure enum has no ->value, so its lowercased case NAME is compared —
 * which is the convention the one pure mirror in the tree (LiteralKind) actually uses.
 *
 * @param  class-string  $enum
 * @return list<string>
 */
function enumMirrorPhpCases(string $enum): array
{
    /** @var list<UnitEnum> $cases */
    $cases = $enum::cases();

    return array_map(
        static fn (UnitEnum $case): string => $case instanceof BackedEnum
            ? (string) $case->value
            : strtolower($case->name),
        $cases
    );
}

it('declares a mirror set that has not shrunk', function (): void {
    // A discovery FLOOR, not a pinned count: a row deleted from the map is how a gate quietly stops
    // covering what it was written for, and this is the only arm that would notice.
    expect(count(ENUM_MIRRORS))->toBeGreaterThanOrEqual(20)
        ->and(count(array_unique(array_map(
            static fn (array $row): string => $row[0].'::'.$row[1],
            ENUM_MIRRORS
        ))))->toBe(count(ENUM_MIRRORS), 'a mirror is declared twice');
});

it('matches every declared mirror with exactly its declared grammar', function (): void {
    foreach (ENUM_MIRRORS as [$path, $name, $enum, $grammar]) {
        $body = enumMirrorBody(enumMirrorRead($path), $name, $grammar);

        expect($body)->not->toBeNull(
            "{$path}: the `{$grammar}` grammar found no declaration of `{$name}`. Do not delete the row "
            .'to make this pass — either the declaration moved, or it changed to the other grammar.'
        );

        // Anti-vacuity, per mirror rather than in total: a union that reads as zero members compares
        // equal to nothing and would pass every assertion below by being empty.
        expect(enumMirrorMembers((string) $body))->not->toBeEmpty(
            "{$path}: `{$name}` parsed to ZERO members. A union that references another type rather "
            .'than restating its literals is not a mirror and must not be declared as one.'
        );
    }
});

it('agrees with its PHP enum in both directions, member for member', function (): void {
    foreach (ENUM_MIRRORS as [$path, $name, $enum, $grammar]) {
        $ts = enumMirrorMembers((string) enumMirrorBody(enumMirrorRead($path), $name, $grammar));
        $php = enumMirrorPhpCases($enum);

        $divergence = MIRROR_DIVERGENCES[$name] ?? ['php_only' => [], 'ts_only' => []];

        $phpOnly = array_values(array_diff($php, $ts));
        $tsOnly = array_values(array_diff($ts, $php));

        // Set equality BOTH ways, never containment: a mirror that has grown a member the enum never
        // had is the same defect as one that has lost a member, and only the second is a subset miss.
        expect($phpOnly)->toBe($divergence['php_only'],
            "{$path}: `{$name}` is missing "./** @scrutinizer ignore-type */ implode(', ', $phpOnly)
            ." which {$enum} declares.")
            ->and($tsOnly)->toBe($divergence['ts_only'],
                "{$path}: `{$name}` declares ".implode(', ', $tsOnly)." which {$enum} does not.");
    }
});

it('keeps every declared divergence describing a mirror that still exists', function (): void {
    // The failure mode of an exception map is outliving its subject. DocumentedEnumCatalogDriftTest
    // carries the same arm for the same reason.
    $declared = array_map(static fn (array $row): string => $row[1], ENUM_MIRRORS);

    foreach (array_keys(MIRROR_DIVERGENCES) as $name) {
        // in_array, NOT expect()->toContain($name, $message): Pest reads toContain's second argument
        // as a SECOND NEEDLE rather than a message, so the assertion silently widens and then fails on
        // the message text. Recorded against this repository twice before this file was written.
        expect(in_array($name, $declared, true))->toBeTrue(
            "MIRROR_DIVERGENCES still excuses `{$name}`, which is no longer a declared mirror.");
    }

    foreach (MIRROR_DIVERGENCES as $name => $entry) {
        expect(array_merge($entry['php_only'], $entry['ts_only']))->not->toBeEmpty(
            "`{$name}` is listed as diverging and declares no divergence at all.");
        expect($entry['why'])->not->toBe('');
    }
});

it('keeps every already-gated vocabulary actually gated somewhere else', function (): void {
    foreach (MIRRORS_GATED_ELSEWHERE as $vocabulary => $gate) {
        expect(is_file(base_path($gate)))->toBeTrue(
            "`{$vocabulary}` is exempted here because {$gate} gates it, and that file does not exist. "
            .'An exemption whose gate is gone is an ungated vocabulary, not an exemption.');
    }
});
