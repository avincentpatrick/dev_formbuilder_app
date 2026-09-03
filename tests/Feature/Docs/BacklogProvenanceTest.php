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
| ⛔ LIVENESS IS NOW GATED, WHICH REVERSES WHAT THIS FILE SAID ON 2026-09-02, AND THE REVERSAL IS
| ARGUED HERE RATHER THAN SLIPPED IN. The paragraph this replaces read: *"Only some open rows carry
| `**Live.**` / `**Not live**` / `**Latent.**`, and deciding the rest is a judgement against the code
| — the `M37` triage job, six agents' worth — not a text edit. Gating a judgement nobody has made
| would make the marker a formality, which is the decorative-gate mistake `M43` measured."*
|
| That was right about its own tree and is wrong about this one, because its premise was a fact that
| `M65` changed: 30 open rows carried no verdict, and now none does. **The objection was to gating an
| UNDECIDED judgement. The backfill is the deciding.** What the gate holds is not the judgement but
| the NORMALISATION — exactly as the arm above holds the filer clause and explicitly not the filer's
| correctness. ⛔ IT CHECKS THAT A VERDICT IS RECORDED, NEVER THAT IT IS RIGHT. A row marked
| `**Live.**` whose defect is long dead passes every arm here. What defends correctness is the
| read-only fan-out that produced the backfill, the eight rows whose own stated verdicts served as
| known-answer controls on it, and `scripts/mutate.php` for any row somebody actually takes — which
| is what `docs/backlog-triage-m37.md` already says in its closing sentence.
|
| ⚠️ AND `M43` READS ONE NOTCH SOFTER THAN IT WAS CITED FOR, WHICH IS WORTH RECORDING SINCE THE
| CITATION WAS USED TWICE. `M43`'s own conclusion is that the structural half *"is worth having (it
| is what makes a vendor-added route redden the file without the test knowing its name), but it
| cannot stand alone, and only a mutation could show that."* `M43` argued for KEEPING a structural
| gate and ADDING behavioural controls beside it — not for withholding the structural gate. Both
| readings are left on the record here rather than one being quietly dropped.
|
| ⚠️ WHAT THIS COSTS, STATED SO IT IS NOT DISCOVERED: every row filed from now on must carry a
| verdict at the moment it is filed. That is the cheapest moment — the filer has just read the code —
| and it is the whole point. It also makes `scripts/loop.php` refuse MORE rows, not fewer, because
| its only liveness stop is the `**Not live**` marker and silence used to pass it. That is the gate
| working; do not read its eligible count as loop health.
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
 * The liveness vocabulary, in `scripts/state.php`'s exact order. First match wins, and `**Not live**`
 * is tried FIRST — a row carrying both loses its live reading, deliberately.
 *
 * ⛔ THIS IS THE THIRD COPY OF THIS LIST — `scripts/state.php` and `scripts/loop.php` each hold one —
 * AND SAYING SO IS BETTER THAN PRETENDING OTHERWISE. It is copied rather than imported because both
 * of those are top-level scripts that `chdir()` and execute at include time; requiring one into Pest
 * would run it. The copy is acceptable precisely because this arm's job is to go red when the file
 * and the vocabulary disagree, and the order is pinned by an assertion below so a silent divergence
 * is a failing test rather than a drift.
 */
const BACKLOG_LIVENESS_MARKERS = [
    '**Not live**' => 'not-live',
    '**Live.**' => 'live',
    '**Live**' => 'live',
    '**Latent.**' => 'latent',
    '**Latent**' => 'latent',
];

/**
 * The floor for the liveness arm, which needs its OWN and cannot borrow the one above.
 *
 * `M64`'s `MU4` blinded the bullet parser and the per-bullet arm stayed green, passing vacuously
 * over an empty set — only the floor went red. That lesson applies one notch further here: a
 * mutation to the `'open'` filter INSIDE the liveness arm leaves the discovery arm untouched, so a
 * floor living only in that arm would not save this one.
 */
const BACKLOG_LIVENESS_MIN_OPEN = 50;

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

/**
 * The row's liveness verdict, or null.
 *
 * ⛔ INLINE CODE SPANS ARE STRIPPED FIRST AND THAT IS THE LOAD-BEARING LINE IN THIS FUNCTION. A
 * bolded literal inside backticks is still a substring, and this document quotes its own vocabulary
 * constantly — rows discuss liveness in prose, and `M64` filed one whose text reads `` `**Not live**` ``
 * while the row itself says `**Live.**`. It was classified not-live on the first run.
 *
 * ⚠️ AND MEASUREMENT CANNOT TELL YOU THIS MATTERS. On the tree this arm was written against, the
 * stripped and unstripped counts AGREE EXACTLY — 55 marked, 30 unmarked, zero disagreements. A naive
 * implementation is green today and green tomorrow and silently wrong. `MU2` in the release notes is
 * the only thing that can show it: it wraps one row's marker in backticks and this arm must go red.
 */
function backlogProvenanceLiveness(string $body): ?string
{
    $prose = preg_replace('/`[^`]*`/u', '', $body) ?? $body;

    foreach (BACKLOG_LIVENESS_MARKERS as $marker => $verdict) {
        if (str_contains($prose, $marker)) {
            return $verdict;
        }
    }

    return null;
}

/**
 * How many verdicts the row records. EXACTLY ONE is the contract, matching the filer clause above.
 *
 * ⛔ THE ASYMMETRY THIS CLOSES WAS FOUND BY A MUTATION THAT WAS EXPECTED TO SURVIVE, AND IT DID.
 * `MU5` appended a second, contradicting `**Not live**` to a row already marked `**Live**` and the
 * first draft of this arm stayed GREEN, because it asked whether a verdict was present rather than
 * whether exactly one was. That is not a cosmetic difference here: `scripts/state.php` resolves by
 * FIRST MATCH with `**Not live**` tried first, so a stray second verdict does not read as ambiguity
 * — it silently wins, and a row wrongly resolved not-live is one `scripts/loop.php` refuses forever.
 *
 * ⚠️ Tightening it was safe rather than lucky, and the difference was measured before the change:
 * all 85 open rows carry exactly one marker, so the rule is not red on arrival — which `M40`
 * established can never merge.
 */
function backlogProvenanceLivenessCount(string $body): int
{
    $prose = preg_replace('/`[^`]*`/u', '', $body) ?? $body;
    $found = 0;

    foreach (array_keys(BACKLOG_LIVENESS_MARKERS) as $marker) {
        $found += substr_count($prose, $marker);
    }

    return $found;
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

it('records a liveness verdict on every open row', function (): void {
    $violations = [];
    $checked = 0;

    foreach (backlogProvenanceBullets() as $bullet) {
        // ⛔ OPEN ROWS ONLY, AND NOT AS A CONVENIENCE. A struck or CLOSED BY row is a historical
        // record: some carry a verdict, most do not, and requiring one would be red on arrival —
        // which M40 established can never merge. The question this arm asks is only askable of a
        // row somebody might still take.
        if ($bullet['shape'] !== 'open') {
            continue;
        }

        $checked++;
        $found = backlogProvenanceLivenessCount($bullet['body']);

        if ($found === 1) {
            continue;
        }

        $where = BACKLOG_PROVENANCE_DOCUMENT.':'.$bullet['line'].' (`'.$bullet['severity'].'`)';

        $violations[] = $found === 0
            ? $where.' records no liveness verdict. Append one of '.implode(' / ', array_keys(BACKLOG_LIVENESS_MARKERS)).
              ' to the row — **Live** if the defect is reachable in the tree today, **Latent** if it is real '.
              'but needs a stated precondition (say which), **Not live** if it is a stated limit, a '.
              'deliberate decision or already fixed. Decide it against the CODE, not from the row\'s own '.
              'wording; the marker inside backticks is a mention and deliberately does not count.'
            : $where.' records '.$found.' liveness verdicts. Exactly one is the record. `scripts/state.php` '.
              'resolves a row by FIRST MATCH with `**Not live**` tried first, so a second verdict does not '.
              'read as ambiguity — it silently wins, and a row wrongly resolved not-live is one '.
              '`scripts/loop.php` will refuse forever. Quote the other in backticks or delete it.';
    }

    // ⛔ THE FLOOR IS THIS ARM'S OWN AND IT IS ASSERTED FIRST. With zero open bullets discovered the
    // loop above passes over an empty set and reports green, which is exactly M64's MU4 result. The
    // discovery arm's floor cannot cover this one: a mutation to the `'open'` literal HERE leaves
    // that arm entirely untouched.
    expect($checked)->toBeGreaterThanOrEqual(
        BACKLOG_LIVENESS_MIN_OPEN,
        'Discovery floor: the liveness arm saw only '.$checked.' OPEN bullets, under the floor of '.
        BACKLOG_LIVENESS_MIN_OPEN.'. Every per-row check below passes vacuously on an empty set, so '.
        'this fails first and loudly rather than reporting green over nothing.'
    );

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

    // ⛔ THE VOCABULARY AND ITS ORDER ARE PINNED, because this is the third copy of a list that also
    // lives in `scripts/state.php` and `scripts/loop.php`. If one of them grows a verdict and this
    // does not, the gate quietly stops recognising it. Pinning the order matters as much as pinning
    // the set: `**Not live**` must be tried FIRST so a row carrying two verdicts resolves to the
    // conservative one rather than to whichever appears earlier in the prose.
    expect(array_keys(BACKLOG_LIVENESS_MARKERS))->toBe([
        '**Not live**', '**Live.**', '**Live**', '**Latent.**', '**Latent**',
    ]);

    expect(backlogProvenanceLiveness('the defect is reachable. **Live.** Filed by `M1`.'))->toBe('live');
    expect(backlogProvenanceLiveness('**Latent.** It needs a precondition. Filed by `M1`.'))->toBe('latent');
    expect(backlogProvenanceLiveness('**Not live** — a stated limit. Filed by `M1`.'))->toBe('not-live');

    // ⛔ A MENTION IS NOT A RECORD, AND THIS IS THE ONE CASE MEASUREMENT CANNOT REACH. On the tree
    // this was written against, stripping code spans and not stripping them give the SAME counts,
    // so an implementation missing the strip is green forever. These three assertions and `MU2` are
    // the only things standing between that and a decorative gate.
    expect(backlogProvenanceLiveness('the row quotes `**Not live**` as vocabulary. Filed by `M1`.'))->toBeNull();
    expect(backlogProvenanceLiveness('a row that says `**Live.**` in backticks only. Filed by `M1`.'))->toBeNull();
    expect(backlogProvenanceLiveness('quotes `**Not live**` and then says **Live.** itself.'))->toBe('live');

    // The near-misses. The vocabulary is a literal set and not a fuzzy match: a lowercase marker and
    // a full stop outside the bold are both REJECTED. Both shapes exist in this corpus today, which
    // is why they are asserted rather than assumed.
    expect(backlogProvenanceLiveness('**not live** — lowercase. Filed by `M1`.'))->toBeNull();
    expect(backlogProvenanceLiveness('**Live**. the stop is outside the bold. Filed by `M1`.'))->toBe('live');
    expect(backlogProvenanceLiveness('**Not live — a maintenance trap.** Filed by `M1`.'))->toBeNull();

    // ⚠️ AND THE PRECEDENCE RULE, ASSERTED RATHER THAN TRUSTED. A row carrying both resolves
    // not-live whichever order the two appear in the prose.
    expect(backlogProvenanceLiveness('**Live.** … and later **Not live** too.'))->toBe('not-live');
    expect(backlogProvenanceLiveness('**Not live** … and later **Live.** too.'))->toBe('not-live');

    // ⛔ WHICH IS EXACTLY WHY THE COUNT IS GATED AND NOT ONLY THE PRESENCE. The two lines above are
    // the hazard stated as behaviour: a second verdict does not read as ambiguity anywhere in this
    // repository, it silently wins. `MU5` proved the first draft of the arm blind to it.
    expect(backlogProvenanceLivenessCount('**Live.** Filed by `M1`.'))->toBe(1);
    expect(backlogProvenanceLivenessCount('**Live.** … and later **Not live** too.'))->toBe(2);
    expect(backlogProvenanceLivenessCount('nothing here. Filed by `M1`.'))->toBe(0);

    // A quoted verdict is not a second verdict — the strip applies to the count as well, or every
    // row that discusses the vocabulary would fail the exactly-one rule.
    expect(backlogProvenanceLivenessCount('**Live.** and the row quotes `**Not live**` as vocabulary.'))->toBe(1);

    // `**Live.**` must not also register as `**Live**`, or every single row would count two and the
    // rule would be red on arrival for the whole corpus.
    expect(backlogProvenanceLivenessCount('**Live.**'))->toBe(1);
    expect(backlogProvenanceLivenessCount('**Latent.**'))->toBe(1);
    expect(backlogProvenanceLivenessCount('**Not live**'))->toBe(1);
});
