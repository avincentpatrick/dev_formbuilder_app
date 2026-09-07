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
    pipelineLintWrite('docs/data-dictionary.md', pipelineLintDictionary(0, 25, 15));
    pipelineLintWrite('docs/second-dictionary.md', pipelineLintDictionary(25, 10, 15));

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
    expect($output)->toContain('passed (7 rule groups');
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
