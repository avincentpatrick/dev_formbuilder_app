<?php

declare(strict_types=1);

/*
 * Pre-push guard (M52).
 *
 * WHY THIS EXISTS. Two rules in Standing Rule 7 have each been broken by a real push, and both were
 * discovered afterwards:
 *
 *   - M14 wrote a perfect claim that was never pushed, so nobody could see it. Rule 7(g) — "a claim is
 *     a PUSHED commit" — exists because of that. `preflight` already asserts it, but preflight runs at
 *     session open and is advisory; it cannot stop the push that breaks the rule.
 *   - M48 put a tracker surgery on the trunk with no squash merge, because `git push origin HEAD:main`
 *     — the command Rule 7(g) itself prescribes — PUSHES THE WHOLE BRANCH, and by then the branch
 *     carried the work.
 *
 * ⛔ THIS IS DELIBERATELY NOT A CI STEP, and not part of `composer run quality`. Inside a runner there
 * is no local branch, no unpushed claim and no remote-tracking state, so registering it there would
 * gate nothing — the same argument `ci.yml` makes about preflight. It is a pre-push hook or it is
 * nothing.
 *
 * ⚠️ AND IT IS OPT-IN PER CLONE, WHICH CANNOT BE FIXED FROM INSIDE THE REPOSITORY. `core.hooksPath` is
 * LOCAL git configuration; a repository cannot enable its own hooks, by design, because that would let
 * a clone execute code on checkout. So this guard does nothing until someone runs the one-line install,
 * and `--no-verify` bypasses it on purpose. It guards MISTAKES, not intent. The server-side control is
 * M51's ruleset; this is the cheap local half. `preflight` reports when it is not installed, so an
 * unguarded checkout says so at session open rather than at the moment of the push it would have caught.
 *
 * Usage (as a hook, arguments and stdin supplied by git):
 *   php scripts/pre-push-guard.php <remote-name> <remote-url>   < <refs on stdin>
 *
 * stdin lines are:  <local ref> <local sha> <remote ref> <remote sha>
 *
 * Exit 0 = allow. Exit 1 = refuse, with the rule named. Exit 2 = COULD NOT MEASURE, which also
 * refuses — a guard that cannot read its own inputs must not wave the push through.
 */

const CLAIM_FILE = 'docs/claims/lane-a.md';
const WORKFLOW = '.github/workflows/ci.yml';
const TRUNK = 'main';
const ZERO = '0000000000000000000000000000000000000000';

// ⛔ ONE COMMIT. Rule 7(g)'s claim is a single commit, a claim EXTENSION is a single commit, and a
//    close-out is a single commit. M48's deviation was a branch carrying four. This is the whole of
//    hook (b), and the number is 1 rather than "the claim commit" because the guard cannot know which
//    commit is the claim — only how many are being added to the trunk.
const MAX_DIRECT_TRUNK_COMMITS = 1;

$root = dirname(__DIR__);
chdir($root);

$failures = [];
$notes = [];

$stdin = stream_get_contents(STDIN);

if ($stdin === false) {
    refuse('could not read the ref list from stdin.');
}

$lines = array_values(array_filter(array_map('trim', explode("\n", (string) $stdin)), fn ($l) => $l !== ''));

// A push with nothing on stdin is git telling us there is nothing to do (e.g. everything up to date).
if ($lines === []) {
    exit(0);
}

$docPatterns = documentation_patterns();

foreach ($lines as $line) {
    $parts = preg_split('/\s+/', $line);

    if (! is_array($parts) || count($parts) < 4) {
        refuse("could not parse the ref line from git: {$line}");
    }

    [$localRef, $localSha, $remoteRef, $remoteSha] = $parts;

    // A deletion. Nothing is being added, so neither rule can apply.
    if ($localSha === ZERO) {
        continue;
    }

    $targetBranch = str_starts_with($remoteRef, 'refs/heads/')
        ? substr($remoteRef, strlen('refs/heads/'))
        : $remoteRef;

    // The LOCAL branch name. `git push origin HEAD:main` passes "HEAD" as the local ref, so fall back
    // to the checked-out branch rather than reporting the literal string HEAD.
    $branch = str_starts_with($localRef, 'refs/heads/')
        ? substr($localRef, strlen('refs/heads/'))
        : trim(sh('git rev-parse --abbrev-ref HEAD'));

    // The range actually being added. On a brand-new remote branch git sends a zero remote sha, and
    // there is no "what was there before" — so measure against the trunk instead, which is what the
    // branch will be merged into anyway.
    $base = $remoteSha !== ZERO
        ? $remoteSha
        : trim(sh('git rev-parse --verify --quiet origin/'.TRUNK));

    if ($base === '') {
        cannot_measure('no base to compare against: the remote sha is zero and origin/'.TRUNK.' does not resolve.');
    }

    $status = 0;
    $rangePaths = sh('git diff --name-only '.escapeshellarg($base.'..'.$localSha).' 2>&1', $status);

    if ($status !== 0) {
        cannot_measure("could not diff {$base}..{$localSha} — is the base commit present in this clone?");
    }

    $paths = array_values(array_filter(array_map('trim', explode("\n", $rangePaths)), fn ($p) => $p !== ''));
    $docOnly = $paths !== [] && every_path_is_documentation($paths, $docPatterns);

    // ── Rule B. A direct push to the trunk may carry at most one commit. ─────────────────────────
    //    Checked FIRST because it is the rule that catches the worse incident, and because it applies
    //    even to a documentation-only push: M48's branch carried the surgery AND its documentation.
    if ($targetBranch === TRUNK) {
        $countRaw = sh('git rev-list --count '.escapeshellarg($base.'..'.$localSha).' 2>&1', $status);

        if ($status !== 0 || ! preg_match('/^\d+$/', trim($countRaw))) {
            cannot_measure("could not count the commits in {$base}..{$localSha}.");
        }

        $count = (int) trim($countRaw);

        if ($count > MAX_DIRECT_TRUNK_COMMITS) {
            $failures[] = sprintf(
                "REFUSED — pushing %d commits directly to '%s'.\n".
                "    `git push origin HEAD:main` PUSHES THE WHOLE BRANCH, not the commit you just wrote.\n".
                "    That is how M48 put a tracker surgery on the trunk with no squash merge and no gate.\n".
                "    A claim, a claim extension and a close-out are each ONE commit; %d is work.\n".
                '    Open a pull request, or push just the one commit: git push origin <sha>:%s',
                $count, TRUNK, $count, TRUNK);
        } else {
            $notes[] = sprintf("rule B ok — %d commit(s) to '%s', at or under the limit of %d",
                $count, TRUNK, MAX_DIRECT_TRUNK_COMMITS);
        }
    }

    // ── Rule A. Work may not be pushed before the claim naming this branch is on the trunk. ──────
    //    ⛔ THE DOCUMENTATION EXEMPTION IS NOT A CONVENIENCE, IT IS WHAT MAKES THE RULE POSSIBLE.
    //    The claim push ITSELF cannot satisfy rule A — at that moment the claim is in the commit being
    //    pushed and is not yet on origin/main — and a close-out runs on an m<n>-closeout branch the
    //    claim could not have named, because that branch did not exist when the claim was written.
    //    M50, M51 and M51's correction were all pushed that way. A hook that refuses those gets
    //    --no-verify'd on its first outing, and a bypassed guard is furniture.
    if ($docOnly) {
        $notes[] = 'rule A skipped — documentation-only push ('.count($paths).' path(s)), per '.WORKFLOW."'s paths-ignore";
    } else {
        $claim = sh('git show '.escapeshellarg('origin/'.TRUNK.':'.CLAIM_FILE).' 2>&1', $status);

        if ($status !== 0) {
            cannot_measure('could not read '.CLAIM_FILE.' from origin/'.TRUNK.' — fetch first.');
        }

        if (! str_contains($claim, $branch)) {
            $failures[] = sprintf(
                "REFUSED — %s on origin/%s does not name '%s'.\n".
                "    An unpushed claim does not exist (M14 wrote a perfect one nobody could see).\n".
                "    Write the claim, then: git push origin HEAD:%s — BEFORE the first file.\n".
                '    This push changes %d path(s) that are not documentation, so it is work.',
                CLAIM_FILE, TRUNK, $branch, TRUNK, count($paths));
        } else {
            $notes[] = 'rule A ok — '.CLAIM_FILE.' on origin/'.TRUNK." names '{$branch}'";
        }
    }
}

foreach ($notes as $note) {
    fwrite(STDERR, "pre-push: {$note}\n");
}

if ($failures !== []) {
    fwrite(STDERR, "\n");

    foreach ($failures as $failure) {
        fwrite(STDERR, "pre-push: {$failure}\n\n");
    }

    fwrite(STDERR, "pre-push: refusing the push. This guards mistakes, not intent — --no-verify still works,\n");
    fwrite(STDERR, "          and if you use it, say so in the claim.\n");
    exit(1);
}

fwrite(STDERR, "pre-push: ok\n");
exit(0);

/**
 * ⛔ ONE AUTHORITY, REFERENCED RATHER THAN COPIED. `ci.yml`'s paths-ignore is already this
 * repository's definition of "a change that cannot affect the product", and it is the list a
 * close-out is built to stay inside. A second hand-maintained copy here would drift, which is the
 * defect Rule 7(b), docs/gate-baselines.md and docs/claims/TEMPLATE.md each record separately.
 *
 * @return list<string>
 */
function documentation_patterns(): array
{
    if (! is_file(WORKFLOW)) {
        cannot_measure(WORKFLOW.' is missing, so the documentation-only exemption cannot be derived.');
    }

    $body = (string) file_get_contents(WORKFLOW);
    $patterns = [];
    $inBlock = false;

    // Deliberately a line scan and not a YAML parse: this needs no dependency, and the shape it reads
    // is asserted below rather than assumed. explode() rather than a newline regex — PCRE's \R without
    // /u matches a byte INSIDE a UTF-8 character and silently shifts every line after the first.
    foreach (explode("\n", $body) as $line) {
        $trimmed = trim($line);

        if ($trimmed === 'paths-ignore:') {
            $inBlock = true;

            continue;
        }

        if ($inBlock) {
            if (preg_match("/^-\s*'([^']+)'\s*$/", $trimmed, $m) === 1) {
                $patterns[] = $m[1];

                continue;
            }

            if (preg_match('/^-\s*"([^"]+)"\s*$/', $trimmed, $m) === 1) {
                $patterns[] = $m[1];

                continue;
            }

            // Anything else ends the block — including a blank line or the next key.
            $inBlock = false;
        }
    }

    // ⚠️ A FLOOR, BECAUSE AN EMPTY LIST WOULD SILENTLY EXEMPT NOTHING AND MAKE RULE A FIRE ON EVERY
    //    CLAIM PUSH. That is the "operation that succeeds on empty input" family this project has now
    //    hit in three separate scripts, so it fails closed instead.
    if (count($patterns) < 3) {
        cannot_measure(sprintf(
            'parsed only %d paths-ignore entr(ies) from %s; expected at least 3. '.
            'Refusing rather than exempting nothing.', count($patterns), WORKFLOW));
    }

    return $patterns;
}

/**
 * @param  list<string>  $paths
 * @param  list<string>  $patterns
 */
function every_path_is_documentation(array $paths, array $patterns): bool
{
    foreach ($paths as $path) {
        $matched = false;

        foreach ($patterns as $pattern) {
            if (str_ends_with($pattern, '/**')) {
                $prefix = substr($pattern, 0, -2);

                if (str_starts_with($path, $prefix)) {
                    $matched = true;

                    break;
                }

                continue;
            }

            if ($path === $pattern) {
                $matched = true;

                break;
            }
        }

        if (! $matched) {
            return false;
        }
    }

    return true;
}

function sh(string $command, ?int &$status = null): string
{
    $output = [];
    exec($command, $output, $code);
    $status = $code;

    return implode("\n", $output);
}

function refuse(string $message): never
{
    fwrite(STDERR, "pre-push: {$message}\n");
    exit(1);
}

function cannot_measure(string $message): never
{
    fwrite(STDERR, "pre-push: CANNOT MEASURE — {$message}\n");
    fwrite(STDERR, "pre-push: refusing, because a guard that cannot read its inputs must not wave a push through.\n");
    exit(2);
}
