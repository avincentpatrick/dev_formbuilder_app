<?php

declare(strict_types=1);

use App\Enums\NotificationType;

/*
|--------------------------------------------------------------------------
| The PHP enum and the TypeScript union are one vocabulary in two languages (Increment J3a).
|
| ⚠️ THIS EXISTS BECAUSE THE DRIFT ALREADY HAPPENED AND NOTHING NOTICED FOR TWO INCREMENTS. I11b added
| `impersonation_started` to `App\Enums\NotificationType` and never touched
| `resources/js/components/notifications/types.ts`, so every impersonation notification rendered with the
| generic bell fallback. That fallback is deliberate — `NotificationRow.type` is typed `string` precisely so
| the enum can grow server-first without crashing a shell that is mounted on every page — which is exactly
| what made the omission invisible: the correct behaviour and the broken behaviour look identical.
|
| ── WHY IT IS A SOURCE-TEXT TEST, AND WHY IT LIVES ON THE PHP SIDE ──────────────────────────────────────
| Nothing in the JS toolchain can read a PHP enum, and nothing in PHP can type-check TypeScript. The union
| is the only machine-readable statement of what the client believes the vocabulary is, so reading it off
| disk is the only way to compare the two. Same idiom as `SegmentedControl.test.ts` and
| `token-references.test.ts`, applied across the language boundary instead of within one.
|
| It is the SECOND of two guards and closes the half the first cannot see:
|   · `NOTIFICATION_VISUAL` is typed `Record<NotificationTypeKey, …>`, so a case in the union with no visual
|     is a `npm run type-check` failure. That guards union → map.
|   · this guards enum → union, which is where the real drift happened.
*/

/** @return list<string> the union members declared by `NotificationTypeKey`, in written order. */
function declaredNotificationTypeKeys(): array
{
    $path = base_path('resources/js/components/notifications/types.ts');
    expect(file_exists($path))->toBeTrue("the client type contract has moved; update this test's path: {$path}");

    $source = (string) file_get_contents($path);

    // Capture from the declaration to the terminating semicolon, so a later union in the same file (or a
    // doc comment mentioning a type name) cannot leak into the match.
    $matched = preg_match('/export type NotificationTypeKey\s*=(.*?);/s', $source, $declaration);
    expect($matched)->toBe(1, 'could not find `export type NotificationTypeKey = …;` in types.ts');

    // Single-quoted string literals only. A `|` separator, whitespace and line comments are all ignored by
    // construction rather than by stripping, which keeps this immune to reformatting.
    preg_match_all("/'([a-z_]+)'/", $declaration[1], $members);

    return $members[1];
}

it('declares exactly the NotificationType cases the server has, in the same order', function (): void {
    // ⚠️ ORDER, NOT JUST MEMBERSHIP. `NotificationType`'s own docblock records that its case order is
    // load-bearing (the database CHECK constraints are generated from `values()`), and `NotificationTypeTest`
    // pins it. Asserting order here too means the union reads as a faithful mirror of the enum rather than as
    // a set that happens to contain the same words — so the next person adding a case appends in both files,
    // which is the habit that keeps them together.
    expect(declaredNotificationTypeKeys())->toBe(NotificationType::values());
});

it('catches a case the server added and the client never heard about', function (): void {
    // The I11b shape, stated as a property rather than as a fixture: nothing may be in the enum and missing
    // from the union. Separate from the assertion above so a failure says WHICH direction drifted — an
    // order-only mismatch and a missing case are very different edits to make.
    $missing = array_values(array_diff(NotificationType::values(), declaredNotificationTypeKeys()));

    expect($missing)->toBe([], 'these NotificationType cases are missing from `NotificationTypeKey` in '
        .'resources/js/components/notifications/types.ts, so they will render with the generic bell fallback: '
        .implode(', ', $missing));
});

it('catches a case the client invented that the server does not send', function (): void {
    $unknown = array_values(array_diff(declaredNotificationTypeKeys(), NotificationType::values()));

    expect($unknown)->toBe([], 'these `NotificationTypeKey` members are not NotificationType cases, so their '
        .'visual entries are unreachable: '.implode(', ', $unknown));
});

it('reads a union that is not vacuously empty', function (): void {
    // Anti-vacuity. Every assertion above is satisfied by a regex that matched nothing if `values()` were
    // also empty — and more realistically, by a refactor that renames the type and leaves this test
    // comparing two empty lists. `expect(file_exists(...))` above catches a MOVED file; this catches a
    // RENAMED type inside a file that still exists.
    expect(declaredNotificationTypeKeys())->not->toBeEmpty();
    expect(count(NotificationType::values()))->toBeGreaterThan(5);
});
