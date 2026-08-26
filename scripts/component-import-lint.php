<?php

declare(strict_types=1);

/*
 * Component-import gate (M28) — a component USED in a template but never IMPORTED.
 *
 * WHY THIS EXISTS, AND WHY NEITHER EXISTING GATE CATCHES IT. `resources/js/app.ts` registers no
 * components globally (`.use(plugin)` is Inertia's own plugin, not a component registry), so a
 * `<script setup>` SFC can only resolve a PascalCase tag from its own imports, its own filename, or
 * Vue's built-ins. Anything else resolves to NOTHING and Vue emits a runtime
 * "Failed to resolve component" warning that nothing in this repository reads.
 *
 * MEASURED, NOT ASSUMED (M9, re-confirmed by M28): with a real import deleted, `vue-tsc --noEmit`
 * exits 0 AND `vite build` exits 0. The one historical instance —
 * `resources/js/Pages/invitations/Show.vue` rendering `<MdsBanner>` with no import from J3b until M9 —
 * meant the expired-invitation error banner rendered as nothing at all, in production, silently, for
 * four increments. No Vitest test mounts that page and the e2e console assertion never visits it in
 * the state where the banner renders. That is the whole gap this gate closes.
 *
 * RULES
 *   R1  Every PascalCase tag in a template resolves to an import, a local declaration, the file's own
 *       name (recursive self-reference), or the built-in allow-list. Otherwise it is a violation.
 *   R2  The scan must find files. An empty scan FAILS — see the note below.
 *
 * ⛔ R2 IS DELIBERATE AND ITS ABSENCE IS A FILED DEFECT IN THIS SCRIPT'S OWN NEIGHBOURS.
 * `docs/feature-backlog.md` carries a live `minor`: "Neither structural lint gate fails on an empty
 * scan" — `scripts/constraint-boundary-lint.php:296-304` and `scripts/migration-lint.php:140` print
 * the file count and `exit(0)` regardless, so a moved directory or a mistyped iterator root is
 * indistinguishable from a clean run. Writing a fifth instance of a defect filed one bullet away from
 * this row would be indefensible, so this script asserts a plausible floor instead. That sibling row
 * is NOT closed by this file; fixing those two scripts is its own change.
 *
 * ⚠️ TWO EXEMPTIONS THAT LOOK LIKE LOOPHOLES AND ARE NOT. Both were found by prototyping the naive
 * rule against the tree BEFORE this script was written, which is the only reason they are here rather
 * than being discovered as a red CI run:
 *
 *   (a) TEMPLATE COMMENTS ARE STRIPPED FIRST. `packages/design-system/src/components/Badge/Badge.vue`
 *       carries a long `<!-- … -->` explaining a fail-closed guard, and that comment QUOTES MARKUP:
 *       `<Badge :variant :icon :label />`. A scanner that does not strip comments reads prose as a
 *       usage. This is the project's standing "NAME THE THING, NEVER QUOTE IT" lesson arriving from a
 *       new direction — a comment that quotes the construct it discusses booby-traps the tool that
 *       reads it.
 *
 *   (b) A COMPONENT MAY REFERENCE ITSELF BY FILENAME. `resources/js/components/builder/ConditionRows.vue`
 *       renders `<ConditionRows>` inside its own template to walk a nested condition group. Vue
 *       resolves a `<script setup>` SFC's own basename with no import, so this is correct code, and a
 *       rule without this exemption would fail a legitimate recursive component.
 *
 * With both exemptions the baseline across all 180 SFCs is ZERO, which is what lets this gate ship
 * merge-blocking with no KNOWN_* quarantine list.
 *
 * ⚠️ THIS IS A TEXT SCAN AND ITS LIMITS ARE STATED RATHER THAN DISCOVERED. It cannot see a tag built
 * by `<component :is="…">` (dynamic, and `component` is allow-listed for exactly that reason), nor a
 * component registered by a plugin at runtime. It is a FLOOR — the same caveat Standing Rule 7(b-bis)
 * attaches to its own paired-file sweep. A false NEGATIVE is acceptable here; a false POSITIVE would
 * make the gate untrustworthy, which is why the two exemptions above are precise rather than broad.
 *
 * Usage: php scripts/component-import-lint.php
 */

/** Directories scanned. Every front-end tree that ships `.vue`, both lanes'. */
const SCAN_ROOTS = [
    'resources/js',
    'resources/public-runtime',
    'packages/design-system/src',
];

/**
 * A plausible floor for R2. The tree held 180 SFCs when this gate was written; the floor is set well
 * below that so ordinary deletion does not trip it, and well above zero so a broken iterator does.
 */
const MIN_EXPECTED_SFCS = 100;

/**
 * Tags that resolve without a local import.
 *
 * Vue built-ins, plus Inertia's `Link` and `Head` — those two ARE globally registered, by
 * `createInertiaApp`'s plugin, which is what `.use(plugin)` in `resources/js/app.ts` installs.
 */
const ALLOWED_GLOBALS = [
    'Component', 'Template', 'Slot', 'Transition', 'TransitionGroup',
    'KeepAlive', 'Teleport', 'Suspense', 'Link', 'Head',
];

$root = dirname(__DIR__);
$violations = [];      // everything that fails the run
$tagViolations = [];   // the R1 subset only — see the footer note at the bottom
$scanned = 0;

foreach (SCAN_ROOTS as $relativeRoot) {
    $absoluteRoot = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativeRoot);

    if (! is_dir($absoluteRoot)) {
        // A missing root is a discovery regression, not an empty directory. Loud, per R2's reasoning.
        $violations[] = "scan root is missing: {$relativeRoot}";

        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($absoluteRoot, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'vue') {
            continue;
        }

        $path = $file->getPathname();
        $source = file_get_contents($path);

        if ($source === false) {
            $violations[] = 'unreadable file: '.relative_path($path, $root);

            continue;
        }

        $scanned++;

        $template = template_section($source);

        if ($template === null) {
            continue;
        }

        $known = declared_names(script_section($source));
        $known[] = $file->getBasename('.vue');           // (b) recursive self-reference
        $known = array_merge($known, ALLOWED_GLOBALS);

        foreach (used_component_tags($template) as $tag) {
            if (! in_array($tag, $known, true)) {
                $tagViolations[] = sprintf(
                    '%s renders <%s> with no import, no local declaration and no matching filename',
                    relative_path($path, $root),
                    $tag
                );
            }
        }
    }
}

$violations = array_merge($violations, $tagViolations);

if ($scanned < MIN_EXPECTED_SFCS && $violations === []) {
    // R2. A gate nobody can tell is blind is a gate nobody is running.
    fwrite(STDERR, sprintf(
        "Component-import linter FAILED: scanned only %d SFC(s), expected at least %d.\n".
        "  This is a DISCOVERY regression, not a clean run — a scan root has moved or been renamed.\n",
        $scanned,
        MIN_EXPECTED_SFCS
    ));
    exit(1);
}

if ($violations !== []) {
    fwrite(STDERR, "Component-import linter FAILED:\n");
    foreach ($violations as $violation) {
        fwrite(STDERR, "  - {$violation}\n");
    }
    // ⚠️ THE FOOTER EXPLAINS R1 AND MUST NOT BE HANDED TO AN R2 FAILURE, WHICH HAS A DIFFERENT
    // REMEDY ENTIRELY. Found by this gate's OWN R2 control: renaming a scan root reddened it
    // correctly and then printed advice about adding an import, for a directory that had moved.
    // A gate whose failure message points at the wrong fix costs more than the bug it caught.
    if ($tagViolations !== []) {
        fwrite(STDERR, "\nA PascalCase tag with no import resolves to NOTHING at runtime and renders as\n");
        fwrite(STDERR, "nothing. vue-tsc and vite build both exit 0 in that state — that is why this gate\n");
        fwrite(STDERR, "exists. Add the import, or add the tag to ALLOWED_GLOBALS if it is truly global.\n");
    }
    exit(1);
}

fwrite(STDOUT, sprintf("Component-import linter passed (%d SFC(s) scanned).\n", $scanned));
exit(0);

/**
 * The `<template>` section, with HTML comments stripped.
 *
 * Exemption (a): a comment that quotes markup must not read as a usage.
 */
function template_section(string $source): ?string
{
    $start = strpos($source, '<template');

    if ($start === false) {
        return null;
    }

    $end = strrpos($source, '</template>');
    $template = $end !== false && $end > $start
        ? substr($source, $start, $end - $start)
        : substr($source, $start);

    return preg_replace('/<!--.*?-->/s', '', $template) ?? $template;
}

/** The `<script setup>` (or plain `<script>`) section. */
function script_section(string $source): string
{
    $start = strpos($source, '<script');

    if ($start === false) {
        return '';
    }

    $end = strrpos($source, '</script>');

    return $end !== false && $end > $start
        ? substr($source, $start, $end - $start)
        : substr($source, $start);
}

/**
 * Every name the script section brings into scope: default imports, named imports (honouring
 * `as` aliases), and local const/let/var/function/class declarations that start with a capital.
 *
 * @return list<string>
 */
function declared_names(string $script): array
{
    $names = [];

    if (preg_match_all('/^\s*import\s+([A-Za-z_$][\w$]*)\s*(?:,|\s+from)/m', $script, $m) !== false) {
        $names = array_merge($names, $m[1]);
    }

    if (preg_match_all('/^\s*import\s*(?:[A-Za-z_$][\w$]*\s*,\s*)?\{([^}]*)\}\s*from/ms', $script, $m) !== false) {
        foreach ($m[1] as $clause) {
            foreach (explode(',', $clause) as $part) {
                $part = trim($part);

                if ($part === '') {
                    continue;
                }

                if (str_contains($part, ' as ')) {
                    $part = trim(substr($part, strrpos($part, ' as ') + 4));
                }

                $names[] = $part;
            }
        }
    }

    if (preg_match_all('/^\s*(?:const|let|var|function|class)\s+([A-Z][\w$]*)/m', $script, $m) !== false) {
        $names = array_merge($names, $m[1]);
    }

    return array_values(array_unique($names));
}

/**
 * PascalCase tags opened in a template.
 *
 * @return list<string>
 */
function used_component_tags(string $template): array
{
    if (preg_match_all('/<([A-Z][A-Za-z0-9_]*)/', $template, $m) === false) {
        return [];
    }

    return array_values(array_unique($m[1]));
}

function relative_path(string $path, string $root): string
{
    return str_replace('\\', '/', ltrim(str_replace($root, '', $path), '\\/'));
}
