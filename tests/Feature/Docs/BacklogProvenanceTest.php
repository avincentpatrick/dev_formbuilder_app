<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Every backlog row records the increment that filed it, in ONE form (M64).
|--------------------------------------------------------------------------
| `D5` is the answered decision that ends the M-series: zero open `major` rows, plus N consecutive
| increments filing no new `major`. Its second clause needs to know which increment FILED each
| `major`, and `D5`'s own entry recorded that this could not be evaluated at all — provenance
| appeared in at least fifteen free-text shapes and most bullets carried none. `D5` wrote down what
| that costs: *"a bar that cannot be measured is a bar that will be declared met by whoever wants to
| stop."* This gate is the half that keeps the file normalised once it has been.
|
| ⛔ WHY THIS IS A TEST AND NOT A LINT SCRIPT, and the reason is `M42`'s rather than a preference.
| `D5`'s precondition says *"with a lint gate holding it there"* — Lane A's own phrasing in `M36`,
| not the user's answer. `scripts/mutate.php` drives Pest in a container and nothing else, so a
| `scripts/*-lint.php` sibling would have to reimplement its discipline by hand at the call site,
| which `M42` measured as the weaker form, AND would need a `composer.json` alias plus its own
| `ci.yml` step because no CI job runs the `quality` aggregate. The four controls in the release
| notes were run through `mutate.php` because of this choice. The departure was put to the user and
| confirmed before the first file was opened.
|
| ⛔ IT CHECKS THAT A FILER IS RECORDED, NEVER THAT IT IS CORRECT. A bullet claiming the wrong
| increment passes every arm below. This is the same limit `scripts/citation-liveness-lint.php`
| states about itself — it checks a cited line is ALIVE, not that it says what the citation claims —
| and it is written here so nobody sells this gate as more than it is. What defends correctness is
| the archaeology that produced the backfill, which resolved every one of the 161 bullets against
| all 135 historical versions of the file and asserted three hand-established answers as controls.
|
| ⛔ IT IS A FLOOR, NOT A DELTA, AND THAT IS DELIBERATE. It requires the clause of EVERY severity
| bullet rather than only of bullets a diff adds. A delta-based rule needs a base commit, and this
| repository's `R7` history is one long account of what base resolution costs: `fetch-depth: 2`
| blinded two gates for the repository's whole life and neither reported it. A floor has no base.
|
| ⚠️ LIVENESS IS DELIBERATELY NOT GATED. Only some open rows carry `**Live.**` / `**Not live**` /
| `**Latent.**`, and deciding the rest is a judgement against the code — the `M37` triage job, six
| agents' worth — not a text edit. `scripts/state.php` reports the coverage; the backfill is filed as
| its own row. Gating a judgement nobody has made would make the marker a formality, which is the
| decorative-gate mistake `M43` measured.
|
| ⚠️ Reads one named file and iterates no directory, so the partial `RecursiveDirectoryIterator`
| descent of the Windows bind mount that `docs/gate-baselines.md` records for the lint gates cannot
| reach it. It is equally correct on the host and in the container.
|
| Helper names are prefixed `backlogProvenance*` deliberately: Pest loads every file in a directory
| into one process, so a same-named file-scope helper is a fatal redeclaration.
*/

/** The queue whose rows are gated. */
const BACKLOG_PROVENANCE_DOCUMENT = 'docs/feature-backlog.md';

/**
 * Plausible floors for the discovery pass.
 *
 * Measured on the tree this gate was written against: 161 severity bullets — 84 open, 32 `CLOSED BY`
 * and 45 struck. These sit well below that so ordinary closing and filing does not trip them, and
 * well above zero so a renamed file, a changed bullet shape or a broken parser fails LOUDLY instead
 * of reporting green over nothing.
 *
 * ⛔ THE FLOOR IS THE ARM THAT CATCHES THE FAILURE THIS INCREMENT ACTUALLY HIT. Building the
 * backfill, `git log -S$needle` — the argument glued to the flag — returned zero hits at exit 0,
 * which is indistinguishable from an honest "nothing found" and would have marked all 161 bullets
 * `(unattributed)`. An operation that succeeds on empty input is this repository's most repeated
 * defect (`M48`'s three splices, `M49`'s eaten `$`), and a per-bullet arm cannot see it: with zero
 * bullets discovered, every per-bullet assertion passes vacuously.
 */
const BACKLOG_PROVENANCE_MIN_BULLETS = 120;

const BACKLOG_PROVENANCE_MIN_OPEN = 50;

const BACKLOG_PROVENANCE_MIN_CLOSED = 50;

/**
 * The one canonical form. `(unattributed)` is the explicit escape for a bullet whose filer was looked
 * for and not found — it is NOT the same as silence, and `state.php` keeps the two apart.
 */
const BACKLOG_PROVENANCE_CLAUSE = '/Filed by `([A-Z]\d{1,3}[a-z]?\d?|\(unattributed\))`/u';

/**
 * A severity bullet exists in exactly three shapes, and all three carry the severity token followed
 * by the U+00B7 MIDDLE DOT on the FIRST line:
 *
 *     open       - **`minor` · Title**
 *     struck     - ~~**`major` · Title**~~
 *     closed-by  - ✅ **CLOSED BY `M17` (2026-08-26) — `major` · ~~Title~~**
 *
 * ⛔ THE SEPARATOR IS A MIDDLE DOT AND NOT AN EM DASH. The same rows use an em dash elsewhere, so
 * splitting on the dash a reader notices first mis-fires on nearly every row.
 *
 * ⛔ AND IT MUST BE ON THE FIRST LINE. A row MOVED OUT to `decisions.md` keeps the original bullet
 * verbatim inside its body, so a parser that reads the whole body counts a row that is deliberately
 * no longer one — measured: it found a 162nd bullet that `state.php` does not and must not count.
 *
 * @return list<array{line: int, shape: string, severity: string, first: string, body: string}>
 */
function backlogProvenanceBullets(): array
{
    $path = base_path(BACKLOG_PROVENANCE_DOCUMENT);

    expect(is_file($path))->toBeTrue(
        'Discovery floor: '.BACKLOG_PROVENANCE_DOCUMENT.' was not found at '.$path.
        '. A moved or renamed queue makes this gate blind, so it fails instead.'
    );

    // `explode` and never `preg_split` on `\R` — PCRE's `\R` without `/u` matches the byte 0x85
    // INSIDE UTF-8 characters, and this document is full of them, which silently shifts every line
    // number after the first (M42).
    $lines = explode("\n", (string) file_get_contents($path));

    $bullets = [];
    $current = null;

    foreach ($lines as $index => $line) {
        if (str_starts_with($line, '- ')) {
            if ($current !== null) {
                $bullets[] = $current;
            }

            $current = ['line' => $index + 1, 'first' => $line, 'body' => $line];

            continue;
        }

        // A heading ends a bullet. Rows are frequently NOT separated by a blank line, so a paragraph
        // split is simply wrong — the same reason `scripts/state.php` does it this way.
        if ($line !== '' && $line[0] === '#') {
            if ($current !== null) {
                $bullets[] = $current;
                $current = null;
            }

            continue;
        }

        if ($current !== null) {
            $current['body'] .= "\n".$line;
        }
    }

    if ($current !== null) {
        $bullets[] = $current;
    }

    $severity = [];

    foreach ($bullets as $bullet) {
        if (preg_match('/`(major|minor|nit)`\s*\x{00B7}/u', $bullet['first'], $match) !== 1) {
            continue;
        }

        $severity[] = [
            'line' => $bullet['line'],
            'shape' => backlogProvenanceShape($bullet['first']),
            'severity' => $match[1],
            'first' => $bullet['first'],
            'body' => $bullet['body'],
        ];
    }

    return $severity;
}

function backlogProvenanceShape(string $first): string
{
    if (str_contains($first, 'CLOSED BY')) {
        return 'closed-by';
    }

    return str_starts_with($first, '- ~~') ? 'struck' : 'open';
}

it('discovers enough of the backlog to be able to fail', function (): void {
    $bullets = backlogProvenanceBullets();

    $shapes = ['open' => 0, 'struck' => 0, 'closed-by' => 0];

    foreach ($bullets as $bullet) {
        $shapes[$bullet['shape']]++;
    }

    $closed = $shapes['struck'] + $shapes['closed-by'];

    expect(count($bullets))->toBeGreaterThanOrEqual(
        BACKLOG_PROVENANCE_MIN_BULLETS,
        'Discovery floor: only '.count($bullets).' severity bullets were parsed out of '.
        BACKLOG_PROVENANCE_DOCUMENT.', under the floor of '.BACKLOG_PROVENANCE_MIN_BULLETS.
        '. Either the bullet shape changed or this parser stopped matching. Every per-bullet arm '.
        'below passes vacuously on an empty set, so this fails first and loudly.'
    );

    expect($shapes['open'])->toBeGreaterThanOrEqual(
        BACKLOG_PROVENANCE_MIN_OPEN,
        'Discovery floor: '.$shapes['open'].' OPEN bullets, under the floor of '.BACKLOG_PROVENANCE_MIN_OPEN.'.'
    );

    // ⛔ CLOSED BULLETS ARE HALF THE POINT AND ARE THE HALF A PARSER LOSES SILENTLY. D5's second
    // clause asks which increment FILED each `major`, and nearly every `major` in this file is
    // closed — a `major` filed and closed inside one increment was still filed by it. Until M64
    // `state.php`'s row parser matched only the open shape, so 77 bullets existed nowhere.
    expect($closed)->toBeGreaterThanOrEqual(
        BACKLOG_PROVENANCE_MIN_CLOSED,
        'Discovery floor: '.$closed.' CLOSED bullets (struck + CLOSED BY), under the floor of '.
        BACKLOG_PROVENANCE_MIN_CLOSED.'. A parser that sees only open rows cannot evaluate D5 at all.'
    );
});

it('records exactly one filer on every severity bullet, open and closed', function (): void {
    $violations = [];

    foreach (backlogProvenanceBullets() as $bullet) {
        $found = preg_match_all(BACKLOG_PROVENANCE_CLAUSE, $bullet['body'], $matches);

        if ($found === 1) {
            continue;
        }

        $where = BACKLOG_PROVENANCE_DOCUMENT.':'.$bullet['line'].' ('.$bullet['shape'].', `'.$bullet['severity'].'`)';

        $violations[] = $found === 0
            ? $where.' records no filer. Append "Filed by `<increment>`." to the bullet\'s last line — '.
              'or "Filed by `(unattributed)`." if history genuinely cannot say which increment filed it. '.
              'Resolve it against the file\'s own history rather than guessing; do not leave it silent.'
            : $where.' records '.$found.' filers: '.implode(', ', $matches[0]).
              '. Exactly one is the record; the others are quotations, and a quotation is what made the '.
              'free-text form unreliable in the first place.';
    }

    expect($violations)->toBe([], implode("\n", $violations));
});

it('discriminates between a record, a quotation and a legacy shape', function (): void {
    // The discriminating control, kept as an assertion rather than a comment. Without it every arm
    // above could be green because the pattern matches nothing at all.
    //
    // ⛔ THE SECOND CASE IS THE DEFECT THAT MOTIVATED THE CANONICAL FORM AND IT IS NOT HYPOTHETICAL.
    // The maintenance-fan-out row quotes the row it superseded under a "THE ROW AS FILED FOLLOWS"
    // heading, and the loose parser this replaced read `M32` out of that QUOTATION while the row's
    // own first paragraph says M44 filed it. The canonical form requires backticks, which is exactly
    // what separates a record somebody wrote as a record from prose that mentions a filing.
    expect(preg_match(BACKLOG_PROVENANCE_CLAUSE, 'Filed by `M44`.'))->toBe(1);
    expect(preg_match(BACKLOG_PROVENANCE_CLAUSE, 'Filed by `(unattributed)`.'))->toBe(1);

    expect(preg_match(BACKLOG_PROVENANCE_CLAUSE, '**Filed by M32 (2026-08-28), which fixed the other two**'))->toBe(0);
    expect(preg_match(BACKLOG_PROVENANCE_CLAUSE, 'Found by `M32`'))->toBe(0);
    expect(preg_match(BACKLOG_PROVENANCE_CLAUSE, 'Filed in `M5`'))->toBe(0);
    expect(preg_match(BACKLOG_PROVENANCE_CLAUSE, 'Filed by `m44`'))->toBe(0);
    expect(preg_match(BACKLOG_PROVENANCE_CLAUSE, 'Filed by `M44xyz`'))->toBe(0);
    expect(preg_match(BACKLOG_PROVENANCE_CLAUSE, 'Filed by `1234`'))->toBe(0);
    expect(preg_match(BACKLOG_PROVENANCE_CLAUSE, 'Filed by M44'))->toBe(0);

    // ⚠️ AND ONE THAT LOOKS MALFORMED AND IS NOT, RECORDED BECAUSE THIS CONTROL CAUGHT THE AUTHOR
    // RATHER THAN THE CODE. `M999x9` was written here expecting a refusal; the id grammar accepts it,
    // because it is the same shape as the real ids `J4b1` and `P3a` and the vocabulary is deliberately
    // not M-only. The control was wrong and the pattern was right — which is the whole argument for
    // running one at all, and the reason this line stays as an assertion instead of being deleted.
    expect(preg_match(BACKLOG_PROVENANCE_CLAUSE, 'Filed by `M999x9`'))->toBe(1);
    expect(preg_match(BACKLOG_PROVENANCE_CLAUSE, 'Filed by `J4b1`'))->toBe(1);
    expect(preg_match(BACKLOG_PROVENANCE_CLAUSE, 'Filed by `P3a`'))->toBe(1);

    // And the shape classifier must actually separate the three, or the floors above count one
    // bucket three times and the closed-bullet floor is met by open rows.
    expect(backlogProvenanceShape('- **`minor` · A title**'))->toBe('open');
    expect(backlogProvenanceShape('- ~~**`major` · A title**~~'))->toBe('struck');
    expect(backlogProvenanceShape('- ✅ **CLOSED BY `M17` (2026-08-26) — `major` · ~~A title~~**'))->toBe('closed-by');
});
