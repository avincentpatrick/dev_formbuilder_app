<?php

declare(strict_types=1);

/*
 * Mail-attribute gate (M57) — a Blade echo inside a quoted HTML attribute in a markdown-mail view.
 *
 * WHY THIS EXISTS, AND WHY IT IS NOT A `{!!` COUNTER. `app/Providers/AppServiceProvider.php` calls
 * `Markdown::withSecuredEncoding()`. Read what that does in the framework version installed:
 * `Illuminate\Mail\Markdown::render()` swaps `EncodedHtmlString`'s encoder, for the whole render, for a
 * THREE-CHARACTER map — `[`, `<`, `>`. No `"`. No `'`. No `&`.
 *
 * So on this surface, and only on this surface, a Blade echo is NOT `htmlspecialchars`. `{{ }}` and
 * `{!! !!}` escape identically here, which means the raw-output directive is not what separates safe
 * from unsafe — the CONTEXT is. A gate that counted `{!!` would have been fully green against the
 * defect that produced this file:
 *
 *     alt="{!! trim($slot) !!}"        <- tenant name, straight into a quoted attribute
 *
 * A tenant named `Acme" onerror="alert(1)` closed the attribute and appended a live event handler to
 * the <img>. MEASURED ON THE RENDERED OUTPUT, not deduced: the injected attribute SURVIVES
 * `CssToInlineStyles`, which re-parses and re-serialises the document — because the break-out happens
 * at string concatenation, before any parser sees the markup. The inliner normalises it INTO a proper
 * attribute rather than repairing it.
 *
 * RULES
 *   R1  A quoted HTML attribute value containing a Blade echo must pass it through
 *       `MailAttribute::escape()`. Anything else is a violation.
 *   R2  The scan must find files, and it must find at least one attribute interpolation. An empty
 *       scan FAILS.
 *
 * ⛔ R2 IS DELIBERATE AND ITS ABSENCE IS A FILED DEFECT IN THIS SCRIPT'S OWN NEIGHBOURS.
 * `docs/feature-backlog.md` carries a live `minor`: neither structural lint gate fails on an empty
 * scan — `constraint-boundary-lint.php` and `migration-lint.php` print the file count and `exit(0)`
 * regardless, so a moved directory or a mistyped iterator root is indistinguishable from a clean run.
 * `component-import-lint.php` (M28) is the sibling that got this right and this file follows it.
 *
 * The SECOND floor is the one specific to this gate: a file count alone would not notice the attribute
 * regex silently matching nothing, which is the failure mode with no symptom. The tree holds three
 * attribute interpolations today, all in one file.
 *
 * ⚠️ BLADE COMMENTS ARE STRIPPED FIRST, AND THIS GATE WOULD BE UNRELIABLE WITHOUT IT.
 * `resources/views/vendor/mail/html/header.blade.php` carries a long comment explaining exactly this
 * defect, and that comment QUOTES the markup it discusses. A scanner that does not strip comments reads
 * the explanation as the code. This is the project's standing "NAME THE THING, NEVER QUOTE IT" lesson
 * arriving from the same direction it reached `component-import-lint.php`'s exemption (a) — a comment
 * that quotes the construct it discusses booby-traps the tool that reads it.
 *
 * ⚠️ WHAT THIS DELIBERATELY DOES NOT COVER, STATED RATHER THAN DISCOVERED.
 *
 *   (a) THE FRAMEWORK'S OWN MAIL COMPONENTS ARE OUT OF REACH. We publish exactly one override; the
 *       rest render from `vendor/laravel/framework`, and `button.blade.php` interpolates into an
 *       `href` the same way. Every `->action()` call site in `app/Notifications/` passes an
 *       application-built URL, so it is not reachable today — see the `minor` filed in
 *       `docs/feature-backlog.md` for the reachability measurement and why publishing them to gain
 *       coverage was priced and not taken.
 *
 *   (b) TEXT CONTEXT IS NOT AN ATTRIBUTE AND IS NOT CHECKED. The secured map escapes `<` and `>`, so
 *       no tag can be opened in a text echo, and escaping one again would double-encode. The bare
 *       `{!! $slot !!}` in the published header is correct and must stay.
 *
 *   (c) IT IS A TEXT SCAN. An attribute assembled across lines, or built in PHP and echoed whole, is
 *       invisible to it. A false NEGATIVE is acceptable here; a false POSITIVE would make the gate
 *       untrustworthy, which is why the rule is anchored on a quoted value rather than on proximity.
 *
 * ✅ PROVED RED BEFORE IT WAS TRUSTED, FOUR WAYS. `scripts/mutate.php` cannot drive a gate that is not
 * Pest-in-a-container (M42), so its discipline was reimplemented at the call site — sha256 asserted to
 * MOVE before each run (a mutation that never applied reports success), the SPECIFIC failure message
 * asserted rather than the shared prefix (M49 — a cannot-measure control can pass through a different
 * branch than the one it names), and the restore verified by byte comparison rather than
 * `git checkout --`:
 *
 *   C1   the unescaped `alt` put back                    -> exit 1, R1, names the attribute
 *   C1b  the same defect written as `{{ }}` instead      -> exit 1, R1  (the whole point: both
 *                                                            directives escape identically here)
 *   C2   both scan roots pointed at moved directories    -> exit 1, R2 file floor
 *   C3   the attribute regex made to match nothing       -> exit 1, R2 attribute-echo floor
 *
 * C3 is the one worth keeping in mind: files still found, rules still run, nothing matched. That is the
 * failure with no symptom, and a file count alone cannot see it.
 *
 * Usage: php scripts/mail-attribute-lint.php
 */

/** Directories scanned. Every tree whose Blade renders through `Markdown::render()`. */
const SCAN_ROOTS = [
    'resources/views/mail',
    'resources/views/vendor/mail',
];

/** R2's file floor — well below today's three, well above zero. */
const MIN_EXPECTED_VIEWS = 2;

/**
 * R2's second floor. Zero attribute interpolations means the regex matched nothing, which is the
 * failure mode a file count cannot see. Lowering it is a deliberate act, not a tidy-up.
 */
const MIN_EXPECTED_ATTRIBUTE_ECHOES = 1;

/** The one approved escaper. `App\Support\Mail\MailAttribute` carries why a Blade echo is not one. */
const REQUIRED_ESCAPER = 'MailAttribute::escape(';

$root = dirname(__DIR__);
$violations = [];
$scanned = 0;
$attributeEchoes = 0;

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
        if (! $file instanceof SplFileInfo || ! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        $scanned++;
        $path = relative_path($file->getPathname(), $root);
        $source = (string) file_get_contents($file->getPathname());

        foreach (attribute_echoes(strip_blade_comments($source)) as $echo) {
            $attributeEchoes++;

            if (! str_contains($echo['value'], REQUIRED_ESCAPER)) {
                $violations[] = sprintf(
                    '%s — %s=%s is a Blade echo in a quoted attribute and does not pass through %s)',
                    $path,
                    $echo['name'],
                    $echo['value'],
                    REQUIRED_ESCAPER
                );
            }
        }
    }
}

if ($scanned < MIN_EXPECTED_VIEWS) {
    fwrite(STDERR, sprintf(
        "Mail-attribute linter FAILED (R2): found %d mail view(s), expected at least %d.\n".
        "An empty or near-empty scan is a moved directory or a broken iterator, not a clean tree.\n",
        $scanned,
        MIN_EXPECTED_VIEWS
    ));
    exit(1);
}

if ($violations === [] && $attributeEchoes < MIN_EXPECTED_ATTRIBUTE_ECHOES) {
    fwrite(STDERR, sprintf(
        "Mail-attribute linter FAILED (R2): scanned %d view(s) but matched %d attribute interpolation(s),\n".
        "expected at least %d. A file count cannot see the attribute regex matching nothing; this can.\n",
        $scanned,
        $attributeEchoes,
        MIN_EXPECTED_ATTRIBUTE_ECHOES
    ));
    exit(1);
}

if ($violations !== []) {
    fwrite(STDERR, "Mail-attribute linter FAILED:\n");

    foreach ($violations as $violation) {
        fwrite(STDERR, "  - {$violation}\n");
    }

    fwrite(STDERR, "\nMarkdown::withSecuredEncoding() (AppServiceProvider) replaces the echo encoder for the\n");
    fwrite(STDERR, "whole render with a three-character map — [ < > — so on this surface a Blade echo does\n");
    fwrite(STDERR, "NOT escape quotes and cannot hold an attribute closed. Wrap the value in\n");
    fwrite(STDERR, "App\\Support\\Mail\\MailAttribute::escape(), which is that map's missing half.\n");

    exit(1);
}

fwrite(STDOUT, sprintf(
    "Mail-attribute linter passed (%d mail view(s), %d attribute interpolation(s) checked).\n",
    $scanned,
    $attributeEchoes
));
exit(0);

/**
 * Remove Blade comments before any rule runs. See the header note — the published header explains this
 * very defect and quotes the markup while doing it.
 */
function strip_blade_comments(string $source): string
{
    return (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);
}

/**
 * Quoted HTML attribute values that contain a Blade echo.
 *
 * A Blade component prop binding (`:url="$brand[...]"`) is PHP rather than an interpolation and carries
 * no echo, so it never reaches the caller's check — verified against the tree before this gate shipped,
 * which is how `component-import-lint.php` found its exemptions instead of discovering them as red CI.
 *
 * @return list<array{name: string, value: string}>
 */
function attribute_echoes(string $template): array
{
    $pattern = '/([A-Za-z_][-A-Za-z0-9_:.]*)\s*=\s*("[^"]*"|\'[^\']*\')/';

    if (preg_match_all($pattern, $template, $matches, PREG_SET_ORDER) === false) {
        return [];
    }

    $found = [];

    foreach ($matches as $match) {
        if (! str_contains($match[2], '{{') && ! str_contains($match[2], '{!!')) {
            continue;
        }

        $found[] = ['name' => $match[1], 'value' => $match[2]];
    }

    return $found;
}

function relative_path(string $path, string $root): string
{
    return str_replace('\\', '/', ltrim(str_replace($root, '', $path), '\\/'));
}
