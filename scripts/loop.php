<?php

declare(strict_types=1);

/*
 * The loop driver (M52).
 *
 * WHAT THE USER ASKED FOR, IN THEIR WORDS: "What I want removed is ME being the trigger for the next
 * session, not human judgement." `scripts/next.php` already generates the hand-off; the missing piece
 * was something that continues to the next planned row on its own — and, more importantly, something
 * that STOPS on its own when the next row is not one an unattended run may take.
 *
 * ⛔ WHAT THIS IS NOT. It does not write the increment. The row's actual work — reading the citations,
 * verifying the remedy, designing the fix, writing the controls — is judgement, and the user asked for
 * the trigger to go, not the judgement. What this removes is the part that is already scripted and is
 * re-typed every session: preflight, the numbers, picking the row, the gate sequence, the pull
 * request, waiting for six COMPLETED checks, the merge, and the whole close-out choreography.
 *
 * ⛔ AND THE HALF THAT MATTERS IS THE REFUSAL. This project's recurring failure is a gate that is green
 * while blind, and routing around one is how a 1,086-line deletion merged green. So `assess` is
 * deliberately CONSERVATIVE: it refuses on anything it does not positively recognise as mechanical,
 * and every refusal names the rule that produced it. A driver that guesses is worse than no driver.
 *
 * Usage:
 *   php scripts/loop.php assess              # classify the next candidate rows; exit 3 if a human is needed
 *   php scripts/loop.php gates [--closeout]  # the gate sequence; ANY exit 2 or red stops the loop
 *   php scripts/loop.php status              # where the series stands against D5's bar
 *
 * Exit 0 = clear to proceed. Exit 1 = a gate failed. Exit 2 = could not measure. Exit 3 = STOP, a human
 * decision is required — which is a normal outcome and not an error.
 */

const BACKLOG = 'docs/feature-backlog.md';
const DECISIONS = 'docs/claims/decisions.md';

// ⛔ THE STOP LIST IS THE POINT OF THIS FILE. Every entry is the user's, verbatim in intent:
//    "any `major` row · any tracker surgery · anything touching ci.yml, decisions.md or repository
//     settings · any NEW module or feature · any held row · any row whose evidence turns out not to
//     match the code · ANY gate exiting 2 or going red."
//
// ⚠️ HELD ROWS ARE OUT OF SCOPE, NOT PENDING. They must never be auto-started, and the standing
//    instruction is that they are not to be reported, counted or scheduled either. The list is closed:
//    OCR, all uploading/import, payments, Track B, GDPR/legal/pricing.
const HELD_TOPICS = [
    'ocr', 'upload', 'uploading', 'import', 'payment', 'payments', 'stripe',
    'billing', 'track b', 'gdpr', 'legal', 'pricing',
];

// Files an unattended run may never touch. ci.yml because it is the gate definition itself;
// decisions.md because a decision is the user's; anything under .github for the same reason as ci.yml.
const FORBIDDEN_PATHS = ['.github/', 'ci.yml', 'docs/claims/decisions.md'];

// ⛔ POSITIVE RECOGNITION, BECAUSE THE ABSENCE OF A STOP REASON IS NOT A REASON TO GO.
//    The user scoped unattended work narrowly: "mechanical rows — citation-liveness,
//    documentation-truth rows with a clear remedy — and the whole close-out choreography." A driver
//    that runs anything merely because no stop rule matched has inverted that: it treats the stop
//    list as exhaustive when it is a floor. ⚠️ THE FIRST VERSION OF THIS FILE DID EXACTLY THAT — its
//    own docblock claimed "auto-eligible only if positively recognised as mechanical" while the code
//    admitted every minor row that tripped nothing. 68 of 78 rows came back eligible. That is M43's
//    lesson in this file's own logic: a check can read as strict and behave as decorative.
const MECHANICAL_MARKERS = [
    'citation', 'cites ', 'file:line', 'stale reference', 'dead link',
    'documents only', 'docblock', 'comment claims', 'comment says',
    'the comment', 'prose says', 'documentation says', 'README',
    'is wrong about itself', 'asserts what the code refutes', 'no longer true',
];

// ⛔ A ROW THAT SAYS IT IS NOT A DEFECT. This corpus marks liveness explicitly -- 11 rows carry
//    `**Not live**` and 13 carry `**Live**` -- and M52's assess had no notion of it. The first
//    unattended run offered three rows and TWO of them were `**Not live**`: one a pair of stated
//    limits filed so they could not be forgotten, whose own text says the gate "must never be widened
//    into that shape", and one a missing gate rather than a defect. Taking the first would have built
//    the thing the row forbids.
//
// ⚠️ ANCHORED ON THE BOLDED LITERAL, NEVER A SUBSTRING. This file quotes its own vocabulary: rows
//    discuss liveness in prose, and one uses the word about a gate's SUBJECT rather than about itself.
//    A bare contains('Not live') would stop rows that merely mention it.
//
// ⚠️ AND SILENCE IS NOT A STOP. Only 24 of 78 rows carry a marker at all, so treating an absent one as
//    "not live" would stop nearly everything and make the driver useless rather than careful.
const NOT_LIVE_MARKER = '**Not live**';

// Phrases that mark work an unattended run may not start on its own judgement.
const STOP_PHRASES = [
    'tracker surgery', 'tracker-surgery', 'surgery marker',
    'branch protection', 'repository setting', 'ruleset',
    'new module', 'new feature', 'net-new',
];

$root = dirname(__DIR__);
chdir($root);

$command = $argv[1] ?? 'assess';

match ($command) {
    'assess' => cmd_assess(),
    'gates' => cmd_gates(),
    'status' => cmd_status(),
    default => usage(),
};

function usage(): never
{
    fwrite(STDERR, "loop: unknown command. Use: assess | gates | status\n");
    exit(2);
}

/**
 * ⛔ THE TERMINAL CONDITION IS D5's BAR AND IT IS READ FROM decisions.md, NEVER RESTATED HERE.
 *
 * ⛔ AND IT IS NOT RECOMPUTED HERE EITHER, WHICH IS THE POINT OF THIS REWRITE (M64). This function
 * used to count provenance itself and then print a hard-coded "Clause 2 UNMEASURABLE". Both were
 * defects waiting to happen: a second parser drifts from the first — the note at this file's parser
 * boundary says so in terms — and a hard-coded verdict survives the day the underlying fact changes.
 * M64 normalised provenance to one form across all 161 severity bullets and backfilled it from
 * history, so state.php now DERIVES both clauses and this reads them.
 *
 * ⚠️ IT STILL DOES NOT DECIDE ANYTHING. Both clauses reading met is an input to a conversation with
 * the user, not a stop signal a driver may act on: D5's own warning is that a measurable bar is one
 * somebody will declare met, and the exit status below deliberately does not encode "stop".
 */
function cmd_status(): never
{
    $state = state();
    $backlog = is_array($state['backlog'] ?? null) ? $state['backlog'] : [];
    $d5 = is_array($state['d5'] ?? null) ? $state['d5'] : [];
    $majors = (int) ($backlog['by_severity']['major'] ?? -1);

    if ($majors < 0 || $d5 === []) {
        cannot_measure('state.php returned no backlog severity counts or no D5 block.');
    }

    if (! is_file(DECISIONS)) {
        cannot_measure(DECISIONS.' is missing; the terminal condition cannot be read.');
    }

    if (! str_contains((string) file_get_contents(DECISIONS), '### D5')) {
        cannot_measure(DECISIONS.' has no D5 entry; the bar this reports against does not exist.');
    }

    line('Series status against D5, the terminal condition');
    line('');
    line(sprintf('  open `major` rows                 %d', $majors));
    line(sprintf('  open rows total                   %d', (int) ($backlog['open'] ?? -1)));
    line(sprintf('  severity bullets, open + closed   %d', (int) ($backlog['severity_bullets'] ?? -1)));
    line(sprintf('  highest released                  M%d', (int) $state['increment']['highest_released']));
    line(sprintf('  bullets with no provenance clause %d', (int) ($d5['bullets_without_a_clause'] ?? -1)));
    line('');

    line(sprintf('  Clause 1 %s — %s (%d open).', $d5['clause_1_met'] ? 'MET' : 'NOT met', $d5['clause_1'], $majors));
    line(sprintf('  Clause 2 %s — %s.', $d5['clause_2_met'] ? 'MET' : 'NOT met', $d5['clause_2']));

    $window = [];

    foreach ((array) ($d5['window'] ?? []) as $increment => $filed) {
        $window[] = $increment.'='.$filed;
    }

    line('    majors filed in the window: '.implode('  ', $window));

    if ((int) ($d5['majors_unattributed'] ?? 0) > 0) {
        line(sprintf('  ⛔ %d `major` bullet(s) record no filer, so clause 2 has a HOLE: an unknown filer', (int) $d5['majors_unattributed']));
        line('     cannot be shown NOT to be one of the increments in the window.');
    }

    line('');
    line('  ⛔ A bar that CAN be measured is still not a decision this driver may take. Both clauses');
    line('     reading met goes to the user with the numbers, and Standing Rule 5 is unchanged until');
    line('     they answer: the next row is taken and built.');

    exit($majors === 0 ? 0 : 3);
}

/**
 * The gate sequence, in the order a push needs it. ⛔ ANY exit 2 or any red STOPS THE LOOP — the user
 * was explicit that this one is not negotiable, because routing around a gate is how the 1,086-line
 * deletion merged green.
 */
function cmd_gates(): never
{
    // Deliberately the HOST gates only. PHPStan is container-only and Vitest cannot run on the host at
    // all, so a driver that pretended to run them here would report a green it never measured — which
    // is the exact defect this project keeps finding.
    // ⛔ --closeout IS PASSED THROUGH, NOT GUESSED. preflight's Rule 7(g) check has no true answer on
    //    a close-out branch — the claim could not have named a branch that did not exist when it was
    //    written — so `gates` was RED BY CONSTRUCTION on the one step this driver most exists to
    //    automate. M52 shipped that defect and its own close-out found it. Inferring it from the
    //    branch name was rejected: a check anyone can switch off by renaming a branch is not a check.
    $closeout = in_array('--closeout', array_slice($GLOBALS['argv'], 2), true);
    $preflight = 'php scripts/preflight.php --lane=a --with-gates --with-pint'.($closeout ? ' --closeout' : '');

    if ($closeout) {
        line('Running in --closeout mode: the claim check is expected not to name this branch.');
        line('');
    }

    $gates = [
        'preflight' => $preflight,
        'tracker-lint' => 'php scripts/tracker-lint.php',
        'tracker-lint-controls' => 'php scripts/tracker-lint-controls.php',
        'citation-liveness' => 'php scripts/citation-liveness-lint.php',
        'state --check' => 'php scripts/state.php --check',
    ];

    $red = [];
    $unmeasured = [];

    foreach ($gates as $name => $command) {
        $status = 0;
        $output = [];
        exec($command.' 2>&1', $output, $status);
        $tail = trim((string) end($output));

        if ($status === 0) {
            line(sprintf('  [ok]    %-22s %s', $name, clip($tail)));

            continue;
        }

        if ($status === 2) {
            $unmeasured[] = $name;
            line(sprintf('  [EXIT2] %-22s %s', $name, clip($tail)));

            continue;
        }

        $red[] = $name;
        line(sprintf('  [RED]   %-22s %s', $name, clip($tail)));
    }

    line('');

    if ($unmeasured !== []) {
        line('⛔ STOP — '.implode(', ', $unmeasured).' could not measure (exit 2).');
        line('   An unmeasured gate is not a passed gate. This is the failure mode the whole series');
        line('   exists to end, so the loop halts here and does not proceed to a push.');
        exit(2);
    }

    if ($red !== []) {
        line('⛔ STOP — '.implode(', ', $red).' is RED.');
        line('   Not negotiable and not retryable by the driver: fix it, or hand it to a human.');
        exit(1);
    }

    line('All host gates green. Container gates (PHPStan, Vitest) and CI remain the authority for');
    line('what cannot run here — this command does not claim to have measured them.');
    exit(0);
}

/**
 * Classify the open rows. ⛔ CONSERVATIVE BY CONSTRUCTION: a row is auto-eligible only if it is
 * positively recognised as mechanical AND trips no stop rule. Anything else stops.
 */
function cmd_assess(): never
{
    if (! is_file(BACKLOG)) {
        cannot_measure(BACKLOG.' is missing.');
    }

    $rows = open_rows();

    if ($rows === []) {
        line('No open rows parsed from '.BACKLOG.'.');
        line('⛔ STOP — that is either a finished backlog or a broken parser, and the driver cannot');
        line('   tell which. A human should look before anything is claimed.');
        exit(3);
    }

    line(sprintf('Parsed %d open row(s) from %s.', count($rows), BACKLOG));
    line('');

    $eligible = [];

    foreach ($rows as $row) {
        $stops = stop_reasons($row);

        if ($stops === []) {
            $eligible[] = $row;
            line(sprintf('  [AUTO] %s', clip($row['title'], 96)));

            continue;
        }

        line(sprintf('  [STOP] %s', clip($row['title'], 96)));

        foreach ($stops as $reason) {
            line(sprintf('         - %s', $reason));
        }
    }

    line('');
    line(sprintf('%d row(s) an unattended run may take; %d need a human.',
        count($eligible), count($rows) - count($eligible)));

    if ($eligible === []) {
        line('');
        line('⛔ STOP — nothing here is mechanical enough to start unattended.');
        line('   That is a normal outcome, not an error. Hand the top row to a human.');
        exit(3);
    }

    line('');
    line('⚠️ Eligibility is NOT a verdict on the row. The driver has not opened a single citation.');
    line('   Rule still stands: verify the row\'s evidence AND its remedy separately, and if the');
    line('   evidence does not match the code, STOP — that is on the user\'s stop list too and no');
    line('   parser can see it.');
    exit(0);
}

/**
 * @param  array{title: string, severity: string, body: string}  $row
 * @return list<string>
 */
function stop_reasons(array $row): array
{
    $reasons = [];
    $haystack = strtolower($row['title'].' '.$row['body']);

    if ($row['severity'] === 'major') {
        $reasons[] = 'a `major` row — the user\'s list, first entry';
    }

    foreach (HELD_TOPICS as $topic) {
        if (str_contains($haystack, $topic)) {
            $reasons[] = sprintf('touches a HELD topic (%s) — out of scope, not pending, never auto-started', $topic);

            break;
        }
    }

    foreach (FORBIDDEN_PATHS as $path) {
        if (str_contains($haystack, strtolower($path))) {
            $reasons[] = sprintf('names %s — gate definitions, decisions and repository settings are the user\'s', $path);

            break;
        }
    }

    foreach (STOP_PHRASES as $phrase) {
        if (str_contains($haystack, $phrase)) {
            $reasons[] = sprintf('mentions "%s"', $phrase);

            break;
        }
    }

    // ⛔ A ROW WAITING ON AN OPEN DECISION IS THE USER'S, BY DEFINITION. Taking it unattended would
    //    answer the decision by building one of its options — which is the D6 failure exactly: a
    //    deadline passing and the default winning by silence.
    foreach (open_decision_ids() as $id) {
        if (preg_match('/\b'.strtolower($id).'\b/', $haystack) === 1) {
            $reasons[] = sprintf('names %s, an OPEN decision — that answer belongs to the user', strtoupper($id));

            break;
        }
    }

    // ⛔ THE ROW SAYS IT IS NOT A DEFECT. Checked against the BODY, because that is where the marker
    //    lives -- it is always the row's closing sentence -- and because a stop rule should be as
    //    sensitive as possible. See NOT_LIVE_MARKER.
    if (str_contains($row['body'], NOT_LIVE_MARKER)) {
        $reasons[] = 'the row marks itself `Not live` -- it is a stated limit or a missing gate, not a defect';
    }

    // ⛔ THE POSITIVE HALF MATCHES THE TITLE ONLY, AND THE ASYMMETRY IS DELIBERATE.
    //    Stop rules scan the title AND the body, because a stop should be as sensitive as possible.
    //    The go-signal scans the TITLE alone, because the body is discussion that quotes everything —
    //    measured: matching the body admitted a CSS overflow row, a missing-middleware security row
    //    and a row that IS an open decision, each on a keyword buried in its own commentary, and put
    //    68 of 78 rows in scope. The title is the row's CLAIM; the body is its argument.
    $mechanical = false;
    $title = strtolower($row['title']);

    foreach (MECHANICAL_MARKERS as $marker) {
        if (str_contains($title, strtolower($marker))) {
            $mechanical = true;

            break;
        }
    }

    if (! $mechanical) {
        $reasons[] = 'not positively recognised as mechanical — the stop list is a floor, not a census';
    }

    return $reasons;
}

/**
 * ⛔ THE ROWS COME FROM scripts/state.php, NOT FROM A PARSER OF MY OWN. The first draft of this file
 * hand-rolled a line scan of the backlog — and state.php already parses it, is already the authority
 * this project tells every session to trust for counting the tree, and already returns each row's
 * severity, line and provenance. A second parser would have drifted from the first, which is the
 * defect Rule 7(b), docs/gate-baselines.md, docs/claims/TEMPLATE.md and this increment's own pre-push
 * guard each record separately. One authority, referenced rather than copied.
 *
 * The row TEXT is still read locally, because state.php returns a clipped title and the stop rules
 * have to match against the whole row. It is taken by the line index state.php reports.
 *
 * @return list<array{title: string, severity: string, body: string}>
 */
function open_rows(): array
{
    $state = state();
    $backlog = is_array($state['backlog'] ?? null) ? $state['backlog'] : [];
    $rows = is_array($backlog['rows'] ?? null) ? $backlog['rows'] : [];

    if ($rows === []) {
        cannot_measure('state.php returned no backlog rows.');
    }

    $lines = explode('
', (string) file_get_contents(BACKLOG));
    $out = [];

    foreach ($rows as $row) {
        $severity = (string) ($row['severity'] ?? '');
        $index = (int) ($row['line'] ?? 0) - 1;

        // ⚠️ FAIL CLOSED on anything unreadable: an unknown severity is treated as `major`, which is
        //    the most restrictive class, rather than quietly becoming eligible.
        if (! in_array($severity, ['major', 'minor', 'nit'], true)) {
            $severity = 'major';
        }

        $body = '';

        if ($index >= 0 && $index < count($lines)) {
            // The row plus its continuation lines, stopping at the next bullet.
            $body = $lines[$index];

            for ($i = $index + 1; $i < count($lines); $i++) {
                if (preg_match('/^- /', $lines[$i]) === 1) {
                    break;
                }

                $body .= ' '.$lines[$i];
            }
        } else {
            $body = '[row text unreadable at the reported line — treated as needing a human]';
            $severity = 'major';
        }

        $out[] = [
            'title' => strip_markup((string) ($row['title'] ?? '(untitled)')),
            'severity' => $severity,
            'body' => $body,
        ];
    }

    return $out;
}

function strip_markup(string $text): string
{
    return trim(preg_replace('/[*`~]/', '', $text) ?? $text);
}

/**
 * The OPEN decision ids, from state.php — never a literal list here, because decisions.md is the
 * authority and a copy would go stale the first time one is answered.
 *
 * @return list<string>
 */
function open_decision_ids(): array
{
    static $ids = null;

    if ($ids === null) {
        $state = state();
        $open = $state['decisions']['open'] ?? [];
        $ids = is_array($open) ? array_values(array_map('strval', $open)) : [];
    }

    return $ids;
}

/** @return array<string, mixed> */
function state(): array
{
    $status = 0;
    $output = [];
    exec('php scripts/state.php --json 2>&1', $output, $status);
    $decoded = json_decode(implode("\n", $output), true);

    if ($status !== 0 || ! is_array($decoded)) {
        cannot_measure('scripts/state.php --json exited '.$status.'; the numbers cannot be derived.');
    }

    return $decoded;
}

function clip(string $text, int $max = 84): string
{
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

    return strlen($text) > $max ? substr($text, 0, $max - 1).'…' : $text;
}

function line(string $text): void
{
    fwrite(STDOUT, $text."\n");
}

function cannot_measure(string $message): never
{
    fwrite(STDERR, "loop: CANNOT MEASURE — {$message}\n");
    fwrite(STDERR, "loop: halting. An unmeasured precondition is not a satisfied one.\n");
    exit(2);
}
