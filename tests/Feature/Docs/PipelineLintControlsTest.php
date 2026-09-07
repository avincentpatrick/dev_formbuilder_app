<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Positive controls for scripts/pipeline-lint.php (M81).
|--------------------------------------------------------------------------
| ⛔ A GREEN GATE PROVES NOTHING ABOUT A GATE YOU HAVE JUST WRITTEN. Only a deliberate defect that
| turns it red does. Every case below is such a defect, plus the refusals the gate must make instead
| of passing, and each asserts an EXIT CODE and a STRING — never an exit code alone, because 1 and 2
| mean opposite things here and an exit-code-only control calls both of them "not zero".
|
| ⛔ WHY THIS IS A PEST FILE AND NOT A `scripts/*-controls.php` SIBLING, WHICH IS THE OPPOSITE OF
| WHAT `scripts/tracker-lint-controls.php` DECIDED. That file could not be a test because R7's input
| is the COMMIT GRAPH and `scripts/mutate.php` drives Pest and nothing else. This gate's input is a
| JSON document plus a handful of named files, so `mutate.php` CAN drive it — and being drivable is
| the whole point, since the rules below are the ones a future edit is most likely to quietly break.
|
| ⚠️ AND THE FIXTURE LIVES IN THE SYSTEM TEMP DIRECTORY, WHICH IS NOT A CONVENIENCE. Pest runs in the
| app container, where `RecursiveDirectoryIterator` descends the Windows bind mount only partially —
| `controller-gate` saw 49 of 97 files there, `M77` pinned 87 of 114 migrations, `D17` measured 40
| whole test files lost. The gate under test walks a directory, so a fixture on the bind mount would
| truncate and the controls would measure the mount instead of the code. Container-local storage does
| not truncate. This is also why the gate itself is a HOST script and never `docker exec`ed.
|
| HOW IT WORKS. `scripts/pipeline-lint.php` opens with `chdir(dirname(__DIR__))`, so a copy of its
| SHIPPED BYTES at `<fixture>/scripts/` makes the fixture its repository root and no `--root=` surface
| has to be added to the gate. That is `tracker-lint-controls.php`'s mechanism exactly. The generator
| it shells is a STUB at `<fixture>/scripts/pipeline.php` which prints whatever JSON the case wants —
| `MutateHarnessTest`'s device for the container runtime, applied to a different seam.
|
| ⚠️ ONE FIXTURE, PERTURBED AND RESTORED, RATHER THAN ONE PER CASE. The base tree must satisfy every
| shipped floor honestly — 600 corpus files, 450 documented columns, 30 column tables — so rebuilding
| it per case would dominate the run. Each case writes one file, runs, and restores by BYTE
| COMPARISON, and `pipelineLintPerturb()` asserts the baseline was green before it wrote and is green
| again after it restored. A red proves nothing if you cannot show it was green.
|
| Helper names are prefixed `pipelineLint*` deliberately: Pest loads every file in a directory into
| one process, so a same-named file-scope helper is a fatal redeclaration.
*/

/** The three-way contract the gate publishes. Exit 2 is a REFUSAL, never a pass and never a failure. */
const PIPELINE_LINT_CLEAN = 0;

const PIPELINE_LINT_FAILED = 1;

const PIPELINE_LINT_CANNOT_MEASURE = 2;

/**
 * Build the fixture once and cache it.
 *
 * Every number here is chosen to clear a SHIPPED floor rather than to be round: the gate's own
 * constants are the specification, and a fixture that cleared them by luck would stop clearing them
 * the day a floor moved.
 */
function pipelineLintRoot(): string
{
    static $root = null;

    if ($root !== null) {
        return $root;
    }

    $root = sys_get_temp_dir().'/m81-pipeline-lint-'.getmypid();

    foreach (['scripts', 'docs', 'docs/adr', 'docs/claims', 'database/migrations', 'app/Generated'] as $dir) {
        if (! is_dir($root.'/'.$dir)) {
            mkdir($root.'/'.$dir, 0o777, true);
        }
    }

    // The gate under test, by its SHIPPED BYTES. A control that runs a copied-and-edited gate is
    // testing something nobody ships.
    copy(base_path('scripts/pipeline-lint.php'), $root.'/scripts/pipeline-lint.php');

    pipelineLintWrite('scripts/pipeline.php', pipelineLintStub());
    pipelineLintWrite('scripts/loop.php', pipelineLintLoop(['alpha', 'beta', 'gamma', 'delta', 'epsilon', 'zeta']));
    pipelineLintWrite('PROGRESS.md', pipelineLintTracker());
    pipelineLintWrite('CLAUDE.md', pipelineLintFiller(120));
    pipelineLintWrite('docs/pipeline.md', "# The pipeline\n\nGenerated. Do not hand-edit.\n");
    pipelineLintWrite('docs/feature-backlog.md', "# Backlog\n\n- nothing filed.\n");
    pipelineLintWrite('docs/claims/decisions.md', "# Decisions\n\n- nothing decided.\n");
    pipelineLintWrite('docs/adr/0001-example.md', "# ADR 1\n\nNothing rejected here.\n");

    // ⚠️ NAMED so they cannot collide with the REAL `docs/data-dictionary.md`, which the coverage
    // corpus below copies in. A synthetic file overwriting a real one would make the fixture measure
    // itself.
    pipelineLintWrite('docs/fixture-dictionary-a.md', pipelineLintDictionary(0, 25, 15));
    pipelineLintWrite('docs/fixture-dictionary-b.md', pipelineLintDictionary(25, 10, 15));

    pipelineLintCopyCoverageCorpus();

    // The schema declares every documented column, so term 1 never masks a later term.
    $schema = "<?php\n\n// Fixture schema.\n";

    for ($i = 0; $i < 40; $i++) {
        for ($j = 0; $j < 15; $j++) {
            $schema .= "\$table->string('".pipelineLintColumn($i, $j)."');\n";
        }
    }

    pipelineLintWrite('database/migrations/2026_01_01_000001_create_fixture.php', $schema);

    // A corpus above MIN_APP_FILES that USES every documented column except the ones a case makes
    // dormant. One file per column keeps each read small.
    for ($i = 0; $i < 40; $i++) {
        for ($j = 0; $j < 15; $j++) {
            $column = pipelineLintColumn($i, $j);
            pipelineLintWrite(
                'app/Generated/Use'.$i.'x'.$j.'.php',
                "<?php\n\n\$row->update(['".$column."' => \$value]);\n"
            );
        }
    }

    // Padding to clear the corpus floor without adding column noise.
    for ($k = 0; $k < 40; $k++) {
        pipelineLintWrite('app/Generated/Pad'.$k.'.php', "<?php\n\n// padding\n");
    }

    return $root;
}

/**
 * Copy the LIVE markdown corpus into the fixture, and remember what was copied.
 *
 * ⛔ THIS IS THE ONE PLACE THE FIXTURE MIRRORS THE REPOSITORY INSTEAD OF INVENTING IT, AND THE REASON
 * IS THE DIGEST. P2a, P2b, P2c and P2e pin a digest over site identities, and an identity carries the
 * file path — so a synthetic corpus can reproduce the shipped COUNTS and can never reproduce the
 * shipped DIGESTS. The choice was between weakening the gate to a count, which is blind to a swap and
 * is the exact hole `scripts/tracker-surgery.php` exists to fill, and pointing the controls at the
 * real prose. The real prose is also the better evidence: these four predicates are then measured
 * against the documents they will actually rule over, not against text written to satisfy them.
 *
 * ⚠️ THE COROLLARY IS WORTH STATING. If the trunk itself violates one of these four rules, the base
 * fixture is red and every case below reports "the baseline was not green before the perturbation"
 * rather than its own failure. That is the honest reading — a control cannot prove a red gate reddens
 * — and the gate's own CI step is where that failure is meant to be read.
 *
 * The list is taken from the GENERATOR, never re-derived: `corpus()` is one definition, and a second
 * walk here would be a second definition that agrees until it does not. It is asked through
 * `--corpus` rather than `--json` because this runs in a container with no git remote, and `--json`
 * needs the defect ranking and the trunk sha — the corpus needs neither.
 *
 * ⚠️ AND THE BIND MOUNT DOES TRUNCATE HERE — IT JUST DOES NOT TRUNCATE THIS HALF, WHICH WAS MEASURED
 * RATHER THAN HOPED. Run from the host the walk reaches 869 files; run inside the app container it
 * reaches 774. All 95 of the lost files are under `app/`, and the markdown half is 55 in both, path
 * for path. That is why these four rules can be driven from a container test at all while the gate
 * itself still cannot be — P2d walks `app/` and would go blind. The floor below is what turns a
 * change in that finding into a loud failure instead of four quietly wrong counts.
 *
 * @return list<string>
 */
function pipelineLintCoverageCorpus(): array
{
    static $paths = null;

    if ($paths !== null) {
        return $paths;
    }

    $output = [];
    $status = 0;
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(base_path('scripts/pipeline.php')).' --corpus 2>&1', $output, $status);

    expect($status)->toBe(0, "the generator could not be read for its corpus:\n".implode("\n", $output));

    $paths = array_values(array_filter(
        array_map(static fn (string $line): string => trim($line), $output),
        static fn (string $path): bool => str_ends_with($path, '.md') && $path !== 'PROGRESS.md'
    ));

    expect(count($paths))->toBeGreaterThanOrEqual(
        50,
        'the markdown corpus reached '.count($paths).' file(s). It is 55 on the host AND inside the '
        .'container; a smaller number here means the bind-mount truncation has reached the half these '
        .'rules read, and every count below would be wrong rather than merely different.'
    );

    return $paths;
}

function pipelineLintCopyCoverageCorpus(): void
{
    foreach (pipelineLintCoverageCorpus() as $path) {
        pipelineLintWrite($path, (string) file_get_contents(base_path($path)));
    }
}

/** A documented column name, stable across the schema, the corpus and the dictionary. */
function pipelineLintColumn(int $table, int $index): string
{
    return 'fx'.$table.'_col'.$index;
}

function pipelineLintWrite(string $relative, string $contents): void
{
    $path = pipelineLintRoot().'/'.$relative;

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0o777, true);
    }

    file_put_contents($path, $contents);
}

/**
 * The generator stub.
 *
 * It answers `--json` from a document the case can replace and `--check` from a file holding the
 * exit code, so P1 is provable without a real pipeline, a real backlog or a real git repository.
 */
function pipelineLintStub(): string
{
    return <<<'PHP'
        <?php

        $root = dirname(__DIR__);

        if (in_array('--check', $argv, true)) {
            $code = is_file($root.'/fixture-check-code') ? (int) trim(file_get_contents($root.'/fixture-check-code')) : 0;

            if ($code !== 0) {
                fwrite(STDERR, "stub: drifted\n");
            }

            exit($code);
        }

        if (in_array('--json', $argv, true)) {
            echo file_get_contents($root.'/fixture-document.json');

            exit(0);
        }

        exit(0);
        PHP;
}

/** A document clearing every row floor, with three held rows matching the stub stop-list. */
function pipelineLintDocument(array $overrides = []): string
{
    $rows = [];

    foreach (['alpha', 'beta', 'gamma', 'delta', 'epsilon', 'zeta'] as $topic) {
        $rows[] = [
            'id' => $topic.'-work',
            'class' => 'plan',
            'state' => 'held',
            'blocker' => 'user: the '.$topic.' decision is theirs',
            'title' => 'The '.$topic.' feature',
            'phase' => '4',
            'size' => 'L',
        ];
    }

    for ($i = 0; $i < 10; $i++) {
        $rows[] = [
            'id' => 'plan-row-'.$i,
            'class' => 'plan',
            'state' => 'ready',
            'blocker' => '',
            'title' => 'Plan row '.$i,
            'phase' => '4',
            'size' => 'M',
        ];
    }

    for ($i = 0; $i < 95; $i++) {
        $rows[] = [
            'id' => 'R-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
            'class' => 'defect',
            'state' => 'ready',
            'blocker' => '',
            'headline' => 'Defect row '.$i,
            'phase' => 'n/a',
            'size' => '',
        ];
    }

    $document = [
        'sha' => str_repeat('a', 40),
        'files_scanned' => 869,
        // The coverage rules read THIS list and never walk a tree, which is what lets one case add a
        // heading to one copied document without disturbing any other rule.
        'corpus' => array_merge(['PROGRESS.md'], pipelineLintCoverageCorpus()),
        'rows' => $rows,
        'off_the_line' => [[
            'id' => 'finished-row',
            'class' => 'plan',
            'state' => 'done',
            'blocker' => '',
            'title' => 'Something that landed',
            'phase' => '4',
            'size' => 'S',
        ]],
    ];

    return (string) json_encode(array_replace($document, $overrides), JSON_PRETTY_PRINT);
}

/** The stop-list, in the shape the gate parses out of the driver. */
function pipelineLintLoop(array $topics): string
{
    return "<?php\n\nconst HELD_TOPICS = [\n    '".implode("', '", $topics)."',\n];\n";
}

/** A tracker whose roadmap clears the row floor and whose in-flight row cites a live id. */
function pipelineLintTracker(string $phaseFourStatus = '🚧 **BUILDING** — carried by `plan-row-0`.'): string
{
    $out = "# Fixture tracker\n\n".pipelineLintFiller(120)."\n## Roadmap Phases (from the approved plan)\n\n";
    $out .= "| Phase | Contents | Status |\n|---|---|---|\n";

    for ($i = 0; $i < 6; $i++) {
        $out .= '| **Phase '.$i." — fixture** | things | ✅ **COMPLETE** (PRs) |\n";
    }

    $out .= '| **Phase 6 — fixture** | things | '.$phaseFourStatus." |\n\n";

    return $out;
}

function pipelineLintFiller(int $lines): string
{
    $out = '';

    for ($i = 0; $i < $lines; $i++) {
        $out .= 'Filler line '.$i." carrying no queue shape and no marker.\n";
    }

    return $out;
}

/** A dictionary document carrying `$tables` column tables of `$perTable` rows each. */
function pipelineLintDictionary(int $first, int $tables, int $perTable): string
{
    $out = "# Fixture dictionary\n\n";

    for ($t = $first; $t < $first + $tables; $t++) {
        $out .= "\n## `fixture_table_".$t."`\n\n";
        $out .= "| Column | Type | Nullable | Default | PII? | Description |\n";
        $out .= "|---|---|---|---|---|---|\n";

        for ($c = 0; $c < $perTable; $c++) {
            $out .= '| `'.pipelineLintColumn($t, $c)."` | `text` | Yes | — | No | A documented column. |\n";
        }
    }

    return $out;
}

/**
 * Run the gate inside the fixture.
 *
 * @return array{0: int, 1: string}
 */
function pipelineLintRun(): array
{
    $root = pipelineLintRoot();
    $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/scripts/pipeline-lint.php').' --verbose';

    $output = [];
    $status = 0;
    exec($command.' 2>&1', $output, $status);

    return [$status, implode("\n", $output)];
}

/** Put the base fixture into its known-good state. */
function pipelineLintReset(): void
{
    pipelineLintWrite('fixture-document.json', pipelineLintDocument());
    pipelineLintWrite('fixture-check-code', '0');
    pipelineLintWrite('scripts/loop.php', pipelineLintLoop(['alpha', 'beta', 'gamma', 'delta', 'epsilon', 'zeta']));
    pipelineLintWrite('PROGRESS.md', pipelineLintTracker());
    pipelineLintWrite('docs/pipeline.md', "# The pipeline\n\nGenerated. Do not hand-edit.\n");
    pipelineLintWrite('docs/feature-backlog.md', "# Backlog\n\n- nothing filed.\n");
}

/**
 * Perturb one file, run, and restore by byte comparison.
 *
 * ⛔ THE BASELINE ASSERTION IS NOT CEREMONY. A case that reddens a gate which was ALREADY red has
 * measured nothing, and this repository has shipped that reading twice.
 */
function pipelineLintPerturb(string $relative, string $contents, callable $assert): void
{
    pipelineLintReset();

    [$baseline, $baselineOutput] = pipelineLintRun();
    expect($baseline)->toBe(PIPELINE_LINT_CLEAN, "the baseline was not green before the perturbation:\n".$baselineOutput);

    $path = pipelineLintRoot().'/'.$relative;
    $original = is_file($path) ? (string) file_get_contents($path) : null;

    pipelineLintWrite($relative, $contents);

    try {
        $assert(...pipelineLintRun());
    } finally {
        $original === null ? @unlink($path) : file_put_contents($path, $original);
    }

    [$restored, $restoredOutput] = pipelineLintRun();
    expect($restored)->toBe(PIPELINE_LINT_CLEAN, "the fixture did not return to green after the restore:\n".$restoredOutput);
}

// ═══════════════════════════════════════════════════════════════════════════════════════════════

it('is GREEN on a well-formed fixture, which every case below is measured against', function (): void {
    pipelineLintReset();

    [$status, $output] = pipelineLintRun();

    expect($status)->toBe(PIPELINE_LINT_CLEAN, $output);
    expect($output)->toContain('passed (11 rule groups');
});

it('P1 — reddens when the generator reports the committed line has drifted', function (): void {
    pipelineLintPerturb('fixture-check-code', '1', function (int $status, string $output): void {
        expect($status)->toBe(PIPELINE_LINT_FAILED, $output);
        expect($output)->toContain('has DRIFTED from the tree');
    });
});

it('P1 — REFUSES rather than passing when the generator cannot answer at all', function (): void {
    pipelineLintPerturb('fixture-check-code', '2', function (int $status, string $output): void {
        expect($status)->toBe(PIPELINE_LINT_CANNOT_MEASURE, $output);
        expect($output)->toContain('drift is UNKNOWN rather than absent');
    });
});

it('P3 — reddens when a phase claims work in flight and cites no row at all', function (): void {
    pipelineLintPerturb('PROGRESS.md', pipelineLintTracker('🚧 **BUILDING** — lots left to do.'), function (int $status, string $output): void {
        expect($status)->toBe(PIPELINE_LINT_FAILED, $output);
        expect($output)->toContain('cites no pipeline id');
    });
});

it('P3 — reddens when every row a phase cites has already left the line', function (): void {
    // The second direction, and the one a containment check cannot see: the citation RESOLVES, and
    // it resolves to work that is finished.
    pipelineLintPerturb('PROGRESS.md', pipelineLintTracker('🚧 **BUILDING** — carried by `finished-row`.'), function (int $status, string $output): void {
        expect($status)->toBe(PIPELINE_LINT_FAILED, $output);
        expect($output)->toContain('is off the line');
    });
});

it('P3 — reddens when a status speaks no word of the closed vocabulary', function (): void {
    pipelineLintPerturb('PROGRESS.md', pipelineLintTracker('🚧 **Coming along nicely.**'), function (int $status, string $output): void {
        expect($status)->toBe(PIPELINE_LINT_FAILED, $output);
        expect($output)->toContain('closed vocabulary');
    });
});

it('P3 — does NOT read a status cell QUOTING the in-flight token as claiming it', function (): void {
    // ⛔ THE OVER-COLLECTING DIRECTION, AND IT IS NOT HYPOTHETICAL: this gate shipped it on its first
    // run against the real tree. A cell recording that it USED to claim work in flight is a repair,
    // and a rule that demands a citation from it punishes the fix.
    pipelineLintPerturb(
        'PROGRESS.md',
        pipelineLintTracker('✅ **COMPLETE** — this cell read `BUILDING` until it was corrected.'),
        function (int $status, string $output): void {
            expect($status)->toBe(PIPELINE_LINT_CLEAN, $output);
        }
    );
});

it('P3 — REFUSES rather than passing when the roadmap table cannot be found', function (): void {
    pipelineLintPerturb('PROGRESS.md', "# Fixture tracker\n\n".pipelineLintFiller(220), function (int $status, string $output): void {
        expect($status)->toBe(PIPELINE_LINT_CANNOT_MEASURE, $output);
        expect($output)->toContain('under the floor');
    });
});

it('P3c — reddens on a line carrying the struck-through shape of a retired queue', function (): void {
    $tracker = pipelineLintTracker()."\nQueue: ~~one~~ ~~two~~ ~~three~~ four\n";

    pipelineLintPerturb('PROGRESS.md', $tracker, function (int $status, string $output): void {
        expect($status)->toBe(PIPELINE_LINT_FAILED, $output);
        expect($output)->toContain('shape of a retired queue');
    });
});

it('P3c — does NOT fire on a GENERATED line, which cannot go stale', function (): void {
    $tracker = pipelineLintTracker()."\n**LANE A NEXT PROMPT →** ~~one~~ ~~two~~ ~~three~~ four\n";

    pipelineLintPerturb('PROGRESS.md', $tracker, function (int $status, string $output): void {
        expect($status)->toBe(PIPELINE_LINT_CLEAN, $output);
    });
});

it('P4 — reddens when a held row names no blocker', function (): void {
    $rows = json_decode(pipelineLintDocument(), true)['rows'];
    $rows[0]['blocker'] = '';

    pipelineLintPerturb('fixture-document.json', pipelineLintDocument(['rows' => $rows]), function (int $status, string $output): void {
        expect($status)->toBe(PIPELINE_LINT_FAILED, $output);
        expect($output)->toContain('names no blocker');
    });
});

it('P4 — reddens when a held row is dropped from the line but the stop-list still refuses it', function (): void {
    $rows = array_values(array_filter(
        json_decode(pipelineLintDocument(), true)['rows'],
        static fn (array $r): bool => $r['id'] !== 'beta-work'
    ));

    pipelineLintPerturb('fixture-document.json', pipelineLintDocument(['rows' => $rows]), function (int $status, string $output): void {
        expect($status)->toBe(PIPELINE_LINT_FAILED, $output);
        expect($output)->toContain('NO held row in the line covers it');
    });
});

it('P4 — reddens IN THE OTHER DIRECTION when the stop-list drops a topic the line still holds', function (): void {
    // ⛔ TWO CASES BECAUSE THEY ARE DIFFERENT FAILURES. Above, work becomes invisible. Here, an
    // unattended run stops refusing held work and may START it. A one-directional rule sees one.
    pipelineLintPerturb('scripts/loop.php', pipelineLintLoop(['alpha', 'gamma', 'delta', 'epsilon', 'zeta']), function (int $status, string $output): void {
        expect($status)->toBe(PIPELINE_LINT_FAILED, $output);
        expect($output)->toContain('is matched by no keyword');
    });
});

it('P4 — REFUSES rather than passing when the stop-list cannot be parsed at all', function (): void {
    pipelineLintPerturb('scripts/loop.php', "<?php\n\n// the list moved somewhere else\n", function (int $status, string $output): void {
        expect($status)->toBe(PIPELINE_LINT_CANNOT_MEASURE, $output);
        expect($output)->toContain('half a two-way check passes for free');
    });
});

it('P2d — reddens on a documented column that exists, is used by nothing and is scheduled nowhere', function (): void {
    pipelineLintPerturb('app/Generated/Use0x0.php', "<?php\n\n// the writer was deleted\n", function (int $status, string $output): void {
        expect($status)->toBe(PIPELINE_LINT_FAILED, $output);
        expect($output)->toContain('is scheduled nowhere');
        expect($output)->toContain(pipelineLintColumn(0, 0));
    });
});

it('P2d — does NOT count a cast entry as a use, which is how a dormant column hides', function (): void {
    // The column appears, in an Eloquent shape, and is still dormant.
    $body = "<?php\n\nprotected function casts(): array\n{\n    return [\n        '".pipelineLintColumn(0, 0)."' => 'datetime',\n    ];\n}\n";

    pipelineLintPerturb('app/Generated/Use0x0.php', $body, function (int $status, string $output): void {
        expect($status)->toBe(PIPELINE_LINT_FAILED, $output);
        expect($output)->toContain(pipelineLintColumn(0, 0));
    });
});

it('P2d — DOES count an array-literal write as a use, which a shape-only rule cannot tell from a cast', function (): void {
    // ⛔ THE OVER-COLLECTING DIRECTION. The first draft of this arm accepted any right-hand side and
    // reported ten live columns as dormant, two of them written one frame from where it looked.
    $body = "<?php\n\n\$row->update(['".pipelineLintColumn(0, 0)."' => Carbon::now()]);\n";

    pipelineLintPerturb('app/Generated/Use0x0.php', $body, function (int $status, string $output): void {
        expect($status)->toBe(PIPELINE_LINT_CLEAN, $output);
    });
});

it('P2d — a dormant column is DISCHARGED by filing it in the ledger', function (): void {
    pipelineLintReset();
    pipelineLintWrite('app/Generated/Use0x0.php', "<?php\n\n// the writer was deleted\n");

    [$before, $beforeOutput] = pipelineLintRun();
    expect($before)->toBe(PIPELINE_LINT_FAILED, $beforeOutput);

    pipelineLintWrite('docs/feature-backlog.md', "# Backlog\n\n- `".pipelineLintColumn(0, 0)."` has no writer; filed.\n");

    [$after, $afterOutput] = pipelineLintRun();
    expect($after)->toBe(PIPELINE_LINT_CLEAN, $afterOutput);

    pipelineLintWrite('app/Generated/Use0x0.php', "<?php\n\n\$row->update(['".pipelineLintColumn(0, 0)."' => \$value]);\n");
    pipelineLintReset();
});

it('P5 — REFUSES rather than passing when the generator scan has gone blind', function (): void {
    pipelineLintPerturb('fixture-document.json', pipelineLintDocument(['files_scanned' => 40]), function (int $status, string $output): void {
        expect($status)->toBe(PIPELINE_LINT_CANNOT_MEASURE, $output);
        expect($output)->toContain('returns a SHORT LIST rather than an error');
    });
});

it('P5 — REFUSES rather than passing when the line itself has collapsed', function (): void {
    pipelineLintPerturb('fixture-document.json', pipelineLintDocument(['rows' => []]), function (int $status, string $output): void {
        expect($status)->toBe(PIPELINE_LINT_CANNOT_MEASURE, $output);
        expect($output)->toContain('under the floor');
    });
});

it('P6 — reddens when the generated line carries a marker that would arm the next run', function (): void {
    $body = "# The pipeline\n\n<!-- pipeline: id=harvested title=\"x\" phase=4 state=ready size=S -->\n";

    pipelineLintPerturb('docs/pipeline.md', $body, function (int $status, string $output): void {
        expect($status)->toBe(PIPELINE_LINT_FAILED, $output);
        expect($output)->toContain('would be harvested on the next run');
    });
});

it('P6 — does NOT fire on an INDENTED marker, which the generator emits by design', function (): void {
    $body = "# The pipeline\n\nAn example of the grammar:\n\n    <!-- pipeline: id=example title=\"x\" phase=4 state=ready size=S -->\n";

    pipelineLintPerturb('docs/pipeline.md', $body, function (int $status, string $output): void {
        expect($status)->toBe(PIPELINE_LINT_CLEAN, $output);
    });
});

// ═══════════════════════════════════════════════════════════════════════════════════════════════
// The coverage rules (M82). Every case below perturbs a COPY of a real document, so the predicate
// under test is measured against the prose it will actually rule over.
//
// ⚠️ SEVERAL CASES REDDEN TWO RULES AT ONCE AND THAT IS NOT SLOPPINESS. The four corpora overlap in
// `docs/PRD.md` by construction — a feature heading is P2a's site and the anchor P2e attributes its
// bullets to — so removing one moves both. Each case asserts the rule it is about; the second
// failure is a true consequence of the same edit, and avoiding it would mean writing a perturbation
// nobody would ever make.
// ═══════════════════════════════════════════════════════════════════════════════════════════════

/** Read a copied corpus document out of the fixture, for a case that edits real prose. */
function pipelineLintDoc(string $relative): string
{
    return (string) file_get_contents(pipelineLintRoot().'/'.$relative);
}

it('P2a — reddens when a newly documented feature appears with nothing scheduling it', function (): void {
    $prd = pipelineLintDoc('docs/PRD.md')."\n### Feature #15 — A newly documented feature\n";

    pipelineLintPerturb('docs/PRD.md', $prd, function (int $status, string $output): void {
        expect($status)->toBe(PIPELINE_LINT_FAILED, $output);
        expect($output)->toContain('holds 15 documented feature heading(s)');
        expect($output)->toContain('pinned expectation is 14');
    });
});

it('P2a — reddens IN THE OTHER DIRECTION when a documented feature quietly disappears', function (): void {
    // ⛔ THE HALF A ONE-WAY RULE CANNOT SEE. Above, work is added and nobody schedules it. Here a
    // stated product commitment is deleted, and no other gate in this repository would notice —
    // a deleted heading is simply a smaller file, which is how M79 destroyed a backlog row and
    // merged green.
    $prd = str_replace('### Feature #14 — ', '### Two-Factor Authentication — ', pipelineLintDoc('docs/PRD.md'));

    pipelineLintPerturb('docs/PRD.md', $prd, function (int $status, string $output): void {
        expect($status)->toBe(PIPELINE_LINT_FAILED, $output);
        expect($output)->toContain('holds 13 documented feature heading(s)');
    });
});

it('P2a — reddens on a SWAP, which no count can see and which is why a digest is pinned', function (): void {
    // One feature renumbered: the corpus still holds fourteen, and it is not the same fourteen.
    $prd = str_replace('### Feature #14 — ', '### Feature #99 — ', pipelineLintDoc('docs/PRD.md'));

    pipelineLintPerturb('docs/PRD.md', $prd, function (int $status, string $output): void {
        expect($status)->toBe(PIPELINE_LINT_FAILED, $output);
        expect($output)->toContain('SET of documented feature heading(s) has changed while its SIZE has not');
    });
});

it('P2a — does NOT fire when a feature is RETITLED, which is prose and not an obligation', function (): void {
    // ⛔ THE OVER-COLLECTING DIRECTION. A rule keyed on heading text turns every copy-edit into a
    // merge failure, and a gate that cries on ordinary editing is a gate somebody deletes.
    $prd = str_replace(
        '### Feature #12 — Audit trail (user-facing)',
        '### Feature #12 — Audit trail, as the user sees it',
        pipelineLintDoc('docs/PRD.md')
    );

    pipelineLintPerturb('docs/PRD.md', $prd, function (int $status, string $output): void {
        expect($status)->toBe(PIPELINE_LINT_CLEAN, $output);
    });
});

it('P2b — reddens when a document grows a new Out of Scope section', function (): void {
    $guide = pipelineLintDoc('docs/TESTING-GUIDE.md')."\n## 12. Out of Scope / Deferred\n\n- A newly deferred thing.\n";

    pipelineLintPerturb('docs/TESTING-GUIDE.md', $guide, function (int $status, string $output): void {
        expect($status)->toBe(PIPELINE_LINT_FAILED, $output);
        expect($output)->toContain('holds 24 section(s) declaring a disposition');
    });
});

it('P2b — does NOT fire on a heading that NARRATES a deferral rather than declaring one', function (): void {
    // ⛔ THE MEASURED FALSE POSITIVE, REPRODUCED AS A CONTROL. A substring test over headings collects
    // "(resolving the deferred question)" — a section CLOSING a deferral, read as one opening it.
    // That is the mention-versus-declaration trap, which this repository has now shipped five times,
    // twice inside the gate built to catch it.
    $guide = pipelineLintDoc('docs/TESTING-GUIDE.md')
        ."\n## 12. Import Target: an Existing Draft (resolving the deferred question)\n";

    pipelineLintPerturb('docs/TESTING-GUIDE.md', $guide, function (int $status, string $output): void {
        expect($status)->toBe(PIPELINE_LINT_CLEAN, $output);
    });
});

it('P2b — does NOT fire when a section is RENUMBERED, which shifts every heading below it', function (): void {
    $strategy = str_replace(
        '## 7. Out of Scope / Deferred',
        '## 8. Out of Scope / Deferred',
        pipelineLintDoc('docs/testing-strategy.md')
    );

    pipelineLintPerturb('docs/testing-strategy.md', $strategy, function (int $status, string $output): void {
        expect($status)->toBe(PIPELINE_LINT_CLEAN, $output);
    });
});

it('P2c — reddens when a document gains a sentence saying something is not built', function (): void {
    $guide = pipelineLintDoc('docs/TESTING-GUIDE.md')."\nThe export runner is still unbuilt.\n";

    pipelineLintPerturb('docs/TESTING-GUIDE.md', $guide, function (int $status, string $output): void {
        expect($status)->toBe(PIPELINE_LINT_FAILED, $output);
        expect($output)->toContain('holds 9 deferral sentence(s)');
    });
});

it('P2c — does NOT fire on the phrase inside a QUOTATION, which is a record of a repair', function (): void {
    // ⛔ THIS WAS LIVE ON THE TRUNK, in the multi-tenancy RBAC design: a line stating that it USED to
    // say the feature was not built, and no longer does. Without the quotation arm the gate counts
    // the repair as an obligation — the same failure P3 shipped on its first run.
    $guide = pipelineLintDoc('docs/TESTING-GUIDE.md')
        ."\nThis line read *\"the export runner is still unbuilt\"* until the runner shipped.\n";

    pipelineLintPerturb('docs/TESTING-GUIDE.md', $guide, function (int $status, string $output): void {
        expect($status)->toBe(PIPELINE_LINT_CLEAN, $output);
    });
});

it('P2c — does NOT fire on "is not built ON", which is a sentence about composition', function (): void {
    // The negative lookahead, and the phrase it protects is live: the design pass found
    // "is not built on StepProjection" and read it as a declaration that something was missing.
    $guide = pipelineLintDoc('docs/TESTING-GUIDE.md')
        ."\nThe submissions projection is not built on the step projection, deliberately.\n";

    pipelineLintPerturb('docs/TESTING-GUIDE.md', $guide, function (int $status, string $output): void {
        expect($status)->toBe(PIPELINE_LINT_CLEAN, $output);
    });
});

it('P2e — reddens when a new acceptance criterion carries no disposition', function (): void {
    $anchor = '- A user can upload a single-page image or PDF, associated with a specific form and its currently published version.';
    $prd = str_replace(
        $anchor,
        $anchor."\n- A newly promised capability with nothing recording what became of it.",
        pipelineLintDoc('docs/PRD.md')
    );

    pipelineLintPerturb('docs/PRD.md', $prd, function (int $status, string $output): void {
        expect($status)->toBe(PIPELINE_LINT_FAILED, $output);
        expect($output)->toContain('states 94 acceptance criteria');
    });
});

it('P2e — reddens when a criterion GAINS a disposition, because the constant moves with the work', function (): void {
    // ⚠️ NOT A FALSE POSITIVE — IT IS THE MECHANISM. Dispositioning a bullet is progress, and the
    // increment that makes it lowers the constant in the same commit. tracker-lint records the same
    // discipline for its own residue: a gate whose expectation an increment invalidates must be
    // updated BY that increment, or main merges red.
    $anchor = '- A user can upload a single-page image or PDF, associated with a specific form and its currently published version.';
    $prd = str_replace($anchor, $anchor.' *(Shipped: a fixture increment.)*', pipelineLintDoc('docs/PRD.md'));

    pipelineLintPerturb('docs/PRD.md', $prd, function (int $status, string $output): void {
        expect($status)->toBe(PIPELINE_LINT_FAILED, $output);
        expect($output)->toContain('holds 88 undispositioned acceptance bullet(s)');
    });
});

it('P2e — reddens when a DISPOSITIONED criterion is deleted, which the residue alone cannot see', function (): void {
    // ⛔ WHY P2e PINS THE CORPUS AS WELL AS THE RESIDUE. Deleting a bullet that already carried its
    // disposition leaves the residue at exactly 89 — a rule watching only the residue reports green
    // while a stated product commitment has been removed from the document.
    $lines = explode("\n", pipelineLintDoc('docs/PRD.md'));

    foreach ($lines as $i => $line) {
        if (str_starts_with($line, '- ') && str_contains($line, '*(')) {
            unset($lines[$i]);

            break;
        }
    }

    pipelineLintPerturb('docs/PRD.md', implode("\n", $lines), function (int $status, string $output): void {
        expect($status)->toBe(PIPELINE_LINT_FAILED, $output);
        expect($output)->toContain('states 92 acceptance criteria');
    });
});

it('the coverage rules REFUSE rather than passing when the generator publishes no corpus', function (): void {
    // ⛔ THE ANSWER TO M81's FORWARD CAUTION. A gate that falls back to walking its own tree can
    // demand a disposition in a file the generator never opens, and any marker added to satisfy it
    // would be INVISIBLE to the generator — a lint-green pipeline still missing the row.
    $document = json_decode(pipelineLintDocument(), true);
    unset($document['corpus']);

    pipelineLintPerturb(
        'fixture-document.json',
        (string) json_encode($document, JSON_PRETTY_PRINT),
        function (int $status, string $output): void {
            expect($status)->toBe(PIPELINE_LINT_CANNOT_MEASURE, $output);
            expect($output)->toContain('the generator published no corpus');
        }
    );
});

it('the coverage rules REFUSE rather than passing when the published corpus has gone blind', function (): void {
    pipelineLintPerturb('fixture-document.json', pipelineLintDocument(['corpus' => ['PROGRESS.md']]), function (int $status, string $output): void {
        expect($status)->toBe(PIPELINE_LINT_CANNOT_MEASURE, $output);
        expect($output)->toContain('under the floor of 10');
    });
});

it('the coverage rules REFUSE rather than passing when a published corpus path is unreadable', function (): void {
    // A skipped file is a silently smaller corpus, and a smaller corpus here reads as work having
    // been dispositioned rather than as a gate that cannot see.
    $corpus = array_merge(['PROGRESS.md'], pipelineLintCoverageCorpus(), ['docs/a-document-that-is-not-there.md']);

    pipelineLintPerturb('fixture-document.json', pipelineLintDocument(['corpus' => $corpus]), function (int $status, string $output): void {
        expect($status)->toBe(PIPELINE_LINT_CANNOT_MEASURE, $output);
        expect($output)->toContain('not readable from here');
    });
});
