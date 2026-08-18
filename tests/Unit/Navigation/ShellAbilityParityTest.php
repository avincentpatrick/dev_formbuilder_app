<?php

declare(strict_types=1);

use App\Support\Authorization\ShellAbilities;
use App\Support\Search\DestinationCatalog;

/*
|--------------------------------------------------------------------------
| The shell's ability vocabulary and its two destination lists, held together (Increment J7).
|
| ⚠️ THIS FILE IS CITED BY TWO PRODUCTION DOCBLOCKS AND DID NOT EXIST. `ShellAbilities:26` says it "is what
| holds the two ends together" and `DestinationCatalog:22` says it "holds the two ends together" — and
| `find tests -iname *Parity*` returned seven other files and not this one. Both classes have been asserting
| a guarantee nobody wrote since J1c. The lists agree today; this is the drift guard they already advertise,
| not a repair.
|
| ── TWO CONTRACTS, ONE FILE, AND THE SINGLE FILE IS THE POINT ───────────────────────────────────────────
| Only one file can carry this class name, and the name is the deliverable: splitting the contracts across
| two files would leave one of the two citations still false, which is the exact defect being closed. They
| are nested rather than merely adjacent — a `gate` and an `ability` that BOTH read `manageFormz` agree
| perfectly with each other while naming an ability that does not exist, hiding the nav item and the palette
| row simultaneously and silently. Contract A is what makes Contract B's agreement mean anything.
|
| ── WHY SOURCE TEXT, AND WHY THE PHP SIDE ──────────────────────────────────────────────────────────────
| Nothing in the JS toolchain can read a PHP class constant and nothing in PHP can type-check TypeScript.
| Same idiom as `NotificationTypeParityTest` (whose reporting shape this borrows) and `PdfFieldRoleTest`
| (whose placement this borrows).
|
| ⚠️ NO DATABASE AND NO CONTAINER — `tests/Pest.php:77` binds TestCase to Feature only, so the repo root is
| computed from __DIR__ rather than through base_path(). `ShellAbilities::for(null)` is a documented API (its
| own docblock: "a null user is accepted rather than guarded against") and resolves every key false without
| booting anything, because the nullsafe operator skips argument evaluation entirely and a ::class constant
| does not autoload. IF A FUTURE ABILITY IS COMPUTED FROM ANYTHING OTHER THAN A NULLSAFE can() CALL — a
| config read, a Gate facade — MOVE THIS FILE TO tests/Feature/Navigation/ and swap the dirname() calls for
| base_path(). Do not weaken the assertions to keep it here.
*/

/**
 * Every NavItem `nav-model.ts` declares, in the flat order `navItems` derives.
 *
 * @return list<array{key: ?string, label: ?string, href: string, gate: ?string, feature: ?string}>
 */
function shellNavItems(): array
{
    $path = dirname(__DIR__, 3).'/resources/js/components/shell/nav-model.ts';
    expect(file_exists($path))->toBeTrue("the nav model has moved; update this test's path: {$path}");

    $source = (string) file_get_contents($path);

    // Capture from the declaration to the terminating bracket-semicolon at column 0, so the derived
    // `navItems` below it cannot leak into the match.
    $matched = preg_match('/export const navGroups: NavGroup\[\] = \[(.*?)\n\];/s', $source, $declaration);
    expect($matched)->toBe(1, 'could not find the `navGroups` array literal in nav-model.ts');

    // ⚠️ COMMENTS ARE STRIPPED FIRST, AND IT IS LOAD-BEARING. This file's prose contains an apostrophe
    // (the Send Feedback trigger sentence, :92) that a naive single-quote scan would mis-pair, swallowing
    // the rest of the group. Line comments are removed only where they START a line, so a future
    // `href` holding a protocol-relative URL survives untouched.
    $body = (string) preg_replace('#/\*[\s\S]*?\*/#', '', $declaration[1]);
    $body = (string) preg_replace('#^[ \t]*//.*$#m', '', $body);

    // INNERMOST object literals only. A NavGroup's outer brace contains its items' braces, so a
    // brace-excluding class can never span one — the groups drop out by CONSTRUCTION rather than by
    // filtering on a key name, and the match is indifferent to whether an item is written on one line or
    // five.
    preg_match_all('/\{[^{}]*\}/s', $body, $objects);

    $items = [];

    foreach ($objects[0] as $object) {
        $field = static fn (string $name): ?string => preg_match("/\b{$name}:\s*'([^']*)'/", $object, $m) === 1
            ? $m[1]
            : null;

        // Only a NavItem carries an href; anything else in this array is not a destination.
        if ($field('href') === null) {
            continue;
        }

        $items[] = [
            'key' => $field('key'),
            'label' => $field('label'),
            'href' => $field('href'),
            'gate' => $field('gate'),
            'feature' => $field('feature'),
        ];
    }

    return $items;
}

/** @return list<string> the ability names `inertia.d.ts` declares on AppAbilities, in written order. */
function declaredAppAbilityKeys(): array
{
    $path = dirname(__DIR__, 3).'/resources/js/types/inertia.d.ts';
    expect(file_exists($path))->toBeTrue("the shared prop types have moved; update this test's path: {$path}");

    $matched = preg_match(
        '/export interface AppAbilities \{(.*?)\n\}/s',
        (string) file_get_contents($path),
        $declaration,
    );
    expect($matched)->toBe(1, 'could not find the `AppAbilities` interface declaration in inertia.d.ts');

    // Comments first: the block names a middleware token in prose, which is not a member.
    $block = (string) preg_replace('#^[ \t]*//.*$#m', '', $declaration[1]);
    preg_match_all('/^\s*([A-Za-z][A-Za-z0-9_]*)\s*:\s*boolean;/m', $block, $members);

    return $members[1];
}

/**
 * The catalog's authored rows, read by reflection.
 *
 * ⚠️ REFLECTION IS MANDATORY HERE, NOT A SHORTCUT PAST A PUBLIC API. `visibleTo()` maps every row down to
 * key/label/url/keywords and DROPS `ability` and `feature` — the exact two fields this file compares — and
 * it filters besides, so it can never reveal a row its fixture user cannot reach. It is structurally
 * incapable of answering this question at any entitlement level. Making ROWS public would be widening a
 * class's surface for a test, which `BrandPaletteTokenParityTest` declines to do for the same reason.
 *
 * @return list<array{key: string, label: string, url: string, keywords: string, ability: ?string, feature: ?string}>
 */
function destinationCatalogRows(): array
{
    /** @var list<array{key: string, label: string, url: string, keywords: string, ability: ?string, feature: ?string}> $rows */
    $rows = (new ReflectionClassConstant(DestinationCatalog::class, 'ROWS'))->getValue();

    return $rows;
}

describe('the ability vocabulary', function (): void {
    it('declares the same ability keys on both sides of the wire', function (): void {
        // Set equality, NOT order. Unlike `NotificationTypeParityTest` — where order is load-bearing because
        // the database CHECK constraints are generated from `values()` — nothing consumes the order of these
        // keys: `HandleInertiaRequests` ships them as a JSON object. Pinning it here would invent a
        // constraint and redden on an alphabetical tidy-up.
        expect(declaredAppAbilityKeys())->toEqualCanonicalizing(array_keys(ShellAbilities::for(null)));
    });

    it('catches an ability the server added and the client never declared', function (): void {
        $missing = array_values(array_diff(array_keys(ShellAbilities::for(null)), declaredAppAbilityKeys()));

        expect($missing)->toBe([], 'these ShellAbilities keys are missing from `AppAbilities` in '
            .'resources/js/types/inertia.d.ts, so reading them is a type error at every consumer: '
            .implode(', ', $missing));
    });

    it('catches an ability the client declared that the server does not compute', function (): void {
        // The nastier direction: the prop is `undefined`, which is falsy, so the affordance does not error —
        // it silently vanishes. That is the failure ShellAbilities:24-26 describes in as many words.
        $unknown = array_values(array_diff(declaredAppAbilityKeys(), array_keys(ShellAbilities::for(null))));

        expect($unknown)->toBe([], 'these `AppAbilities` members are not ShellAbilities keys, so they are '
            .'permanently undefined and everything they gate is hidden from everyone: '.implode(', ', $unknown));
    });

    it('refuses a nav gate that names no ability', function (): void {
        // ⚠️ SUBSET, NOT EQUALITY, AND THE ASYMMETRY IS REAL RATHER THAN A CONCESSION. `transferOwnership`
        // and `assignRoles` are spent by a PAGE (Pages/members/Index.vue), not by the nav, so an ability with
        // no nav item is ordinary and asserting the reverse direction would redden correct code. An ability
        // NAME with no ability behind it is not ordinary.
        $unknown = array_values(array_diff(
            array_filter(array_column(shellNavItems(), 'gate')),
            array_keys(ShellAbilities::for(null)),
        ));

        expect($unknown)->toBe([], 'these `gate` values in nav-model.ts are not ShellAbilities keys, so their '
            .'nav items are hidden from everyone: '.implode(', ', $unknown));
    });

    it('refuses a catalog ability that names no ability', function (): void {
        $unknown = array_values(array_diff(
            array_filter(array_column(destinationCatalogRows(), 'ability')),
            array_keys(ShellAbilities::for(null)),
        ));

        expect($unknown)->toBe([], 'these `ability` values in DestinationCatalog are not ShellAbilities keys, '
            .'so their destinations are unreachable from global search for everyone: '.implode(', ', $unknown));
    });
});

describe('the destinations', function (): void {
    it('offers the same destinations on both surfaces', function (): void {
        expect(array_column(destinationCatalogRows(), 'key'))
            ->toEqualCanonicalizing(array_column(shellNavItems(), 'key'));
    });

    it('catches a destination the sidebar offers and global search cannot reach', function (): void {
        $missing = array_values(array_diff(
            array_column(shellNavItems(), 'key'),
            array_column(destinationCatalogRows(), 'key'),
        ));

        expect($missing)->toBe([], 'these sidebar destinations have no DestinationCatalog row, so the command '
            .'palette cannot reach them: '.implode(', ', $missing));
    });

    it('catches a destination global search offers that the sidebar does not', function (): void {
        // Not automatically a defect — J2d deliberately REMOVED the notifications row because it pointed at a
        // JSON endpoint with no page behind it. It is a defect when it is unintentional, which is why this
        // fails with the key named rather than silently.
        $extra = array_values(array_diff(
            array_column(destinationCatalogRows(), 'key'),
            array_column(shellNavItems(), 'key'),
        ));

        expect($extra)->toBe([], 'these DestinationCatalog rows have no sidebar item; if that is deliberate, '
            .'record why in the catalog the way the removed notifications row was: '.implode(', ', $extra));
    });

    it('lists the destinations in the same order', function (): void {
        // ⚠️ UNGROUPED IS NOT UNORDERED, WHICH IS THE OBJECTION THIS CASE ANSWERS. The catalog is flat on
        // purpose and the nav is grouped on purpose, but the `navGroups` docblock says the grouping IS A
        // PARTITION OF THE ORDER THAT WAS ALREADY HERE, NOT ONE ITEM MOVES — and `navItems` exists precisely
        // as the derived flat order. Both ends are ALREADY pinned independently (`Sidebar.test.ts` on one,
        // `DestinationReachabilityTest` on the other); this connects two constraints that already exist in
        // two files that cannot see each other. It is also product-visible: `DestinationSearchArm::matched()`
        // filters `visibleTo()` in place, so ROWS order IS the destination arm's result ranking.
        //
        // Separate from the membership cases above so a failure says WHICH way it drifted — a reorder and a
        // missing destination are very different edits to make.
        expect(array_column(destinationCatalogRows(), 'key'))->toBe(array_column(shellNavItems(), 'key'));
    });

    it('gates every destination identically on both surfaces', function (): void {
        // ⭐ THE ASSERTION BOTH PRODUCTION DOCBLOCKS NAME THIS FILE FOR. Compared as key-to-tuple maps rather
        // than as two ordered lists, so a mismatch prints the destination key beside both readings instead of
        // a positional diff nobody can read.
        $nav = [];
        foreach (shellNavItems() as $item) {
            $nav[(string) $item['key']] = ['ability' => $item['gate'], 'feature' => $item['feature']];
        }

        $catalog = [];
        foreach (destinationCatalogRows() as $row) {
            $catalog[$row['key']] = ['ability' => $row['ability'], 'feature' => $row['feature']];
        }

        // Key-SORTED before comparing, so this case asserts CONTENT only. `toBe` is `===`, which on an
        // associative array compares key order as well -- without the sort a pure reorder reddens this
        // case too, and the order case below stops being the one legible report of a reorder.
        ksort($nav);
        ksort($catalog);

        expect($catalog)->toBe($nav);
    });

    it('points both surfaces at the same url', function (): void {
        $nav = [];
        foreach (shellNavItems() as $item) {
            $nav[(string) $item['key']] = $item['href'];
        }

        $catalog = [];
        foreach (destinationCatalogRows() as $row) {
            $catalog[$row['key']] = $row['url'];
        }

        // Key-SORTED before comparing, so this case asserts CONTENT only. `toBe` is `===`, which on an
        // associative array compares key order as well -- without the sort a pure reorder reddens this
        // case too, and the order case below stops being the one legible report of a reorder.
        ksort($nav);
        ksort($catalog);

        expect($catalog)->toBe($nav);
    });

    it('calls every destination the same thing on both surfaces', function (): void {
        // The softest of the three maps, and deliberately its own case: a user who reads "Audit log" in the
        // sidebar and "Audit" in the palette has two names for one place. If a surface ever legitimately
        // needs a different label, delete THIS case and leave the gate and url cases standing.
        $nav = [];
        foreach (shellNavItems() as $item) {
            $nav[(string) $item['key']] = $item['label'];
        }

        $catalog = [];
        foreach (destinationCatalogRows() as $row) {
            $catalog[$row['key']] = $row['label'];
        }

        // Key-SORTED before comparing, so this case asserts CONTENT only. `toBe` is `===`, which on an
        // associative array compares key order as well -- without the sort a pure reorder reddens this
        // case too, and the order case below stops being the one legible report of a reorder.
        ksort($nav);
        ksort($catalog);

        expect($catalog)->toBe($nav);
    });
});

describe('the parsers themselves', function (): void {
    it('reads a nav model that is not vacuously empty', function (): void {
        // Anti-vacuity. Every map comparison above is satisfied by two empty lists, which is exactly what a
        // renamed declaration inside a file that still exists would produce. `file_exists` catches a MOVED
        // file; this catches a RENAMED symbol.
        $items = shellNavItems();

        expect($items)->toHaveCount(12);
        expect(array_unique(array_column($items, 'key')))->toHaveCount(12);
    });

    it('parses gate and feature as genuinely optional rather than uniformly absent', function (): void {
        // Without this, a field reader that always returned null would make the gate-map comparison pass
        // by agreeing on all-nulls -- the parser failing in the one way its own assertion cannot see.
        //
        // STRUCTURAL rather than value-pinned, deliberately: an earlier draft asserted the literal
        // `manageForms` and `advanced_analytics`, which would have reddened on a legitimate consistent
        // rename -- a parser guard has no business having an opinion about which abilities exist.
        $items = shellNavItems();

        $withGateOnly = array_filter($items, static fn (array $i): bool => $i['gate'] !== null && $i['feature'] === null);
        $withBoth = array_filter($items, static fn (array $i): bool => $i['gate'] !== null && $i['feature'] !== null);
        $withNeither = array_filter($items, static fn (array $i): bool => $i['gate'] === null && $i['feature'] === null);

        expect($withGateOnly)->not->toBeEmpty('the parser never read a gate without a feature');
        expect($withBoth)->not->toBeEmpty('the parser never read a gate and a feature together');
        expect($withNeither)->not->toBeEmpty('the parser never read an item with neither');
    });

    it('keeps navItems DERIVED from navGroups, which is what makes the order case honest', function (): void {
        // ⚠️ THE GUARD THAT IS EASY TO SKIP AND WOULD MATTER. The order case above compares the catalog
        // against `navGroups`. If someone re-authored `navItems` as a hand-written flat list in a different
        // order, the sidebar's real order would diverge while this file kept parsing `navGroups` and stayed
        // green — a parity test that had quietly stopped testing the thing the sidebar renders.
        $path = dirname(__DIR__, 3).'/resources/js/components/shell/nav-model.ts';

        expect(preg_match('/export const navItems: NavItem\[\] = navGroups\.flatMap\(/', (string) file_get_contents($path)))
            ->toBe(1, '`navItems` is no longer derived from `navGroups` by flatMap, so the order assertion in '
                .'this file no longer describes what the sidebar renders');
    });

    it('reads the ROWS shape it thinks it is reading', function (): void {
        // Stops a PHP-side rename of `ability` from turning every read into a silent null.
        expect(array_keys(destinationCatalogRows()[0]))
            ->toBe(['key', 'label', 'url', 'keywords', 'ability', 'feature']);
    });

    it('reads an ability map that is not vacuously empty', function (): void {
        expect(count(ShellAbilities::for(null)))->toBeGreaterThan(5);
        expect(declaredAppAbilityKeys())->not->toBeEmpty();
    });
});
