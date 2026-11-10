<?php

declare(strict_types=1);

/*
 * Reading `docs/pipeline.md`'s Next section, and saying what in it is work (M215, `R-98b2d568`).
 *
 * WHY THIS EXISTS. `scripts/pipeline.php` prints each Next bullet's class as its last words: `· ready`,
 * `· blocked (…)` or `· waiting on you`. `scripts/state.php` read the ids and threw the class away, so
 * `scripts/next.php`, `scripts/loop.php status` and `state.php` itself all said "take work from" a list
 * holding twelve rows nobody could start, `ocr-linelist` among them, and the sentence went into
 * `PROGRESS.md` on the trunk. A decision or a precondition is not an increment's to move.
 *
 * ⛔ PURE, AND THAT IS WHY IT IS ITS OWN FILE. `state.php` and `next.php` run top to bottom and shell `git`
 * and `gh` when loaded, so a test cannot require them: `M99` had to pin its seam by reading their source.
 * This file does nothing on load, so `tests/Feature/Docs/NextSectionClassTest.php` calls the real parser
 * and `scripts/mutate.php` can prove those tests.
 *
 * ⛔ IT NEVER REFUSES, because `derive_testing_gate()` must not. `scripts/pipeline.php` shells
 * `backlog-triage.php --json`, which shells `state.php`, so a reader that exited on a section it could not
 * read would stop the generator from ever writing the section.
 *
 * ⚠️ MEASURED BEFORE IT WAS WRITTEN. These patterns classified all 4,186 Next bullets across the 299
 * versions of `docs/pipeline.md` on the trunk, 330 of them `waiting on you`, and read all 179 cut notes.
 */

/** A Next bullet, and its id. A later line of prose never starts this way. */
const NEXT_SECTION_BULLET = '/^- `([^`]+)` — /u';

/**
 * Its class. ⛔ ANCHORED AT THE END, because the class is the bullet's last words and a headline or a
 * blocker can hold the same text: `… · ready · blocked (x)` is a blocked row, and only the anchor says so.
 */
const NEXT_SECTION_CLASS = '/ · (ready|blocked \(.*\)|waiting on you)$/u';

/** The cut note `render_next()` writes when the section leaves part of its tier out. */
const NEXT_SECTION_CUT = '/^⚠️ \*\*This section is CUT\b.*?(\d+) more ready and (\d+) more$/u';

/**
 * @param  array<int, string>  $lines  the whole document, one line each
 * @return array{tier: ?string, ready: list<string>, blocked: list<string>, waiting: list<string>, unclassified: list<string>, ready_more: int, blocked_more: int}
 */
function parse_next_section(array $lines): array
{
    $next = ['tier' => null, 'ready' => [], 'blocked' => [], 'waiting' => [], 'unclassified' => [], 'ready_more' => 0, 'blocked_more' => 0];
    $inNext = false;

    foreach ($lines as $line) {
        $line = rtrim($line, "\r");

        if (str_starts_with($line, '## ')) {
            if ($inNext) {
                break;
            }

            $inNext = $line === '## Next';

            continue;
        }

        if (! $inNext) {
            continue;
        }

        if ($next['tier'] === null && preg_match('/^From \*\*([a-z-]+)\*\*/u', $line, $tier) === 1) {
            $next['tier'] = $tier[1];

            continue;
        }

        if (preg_match(NEXT_SECTION_CUT, $line, $cut) === 1) {
            $next['ready_more'] = (int) $cut[1];
            $next['blocked_more'] = (int) $cut[2];

            continue;
        }

        if (preg_match(NEXT_SECTION_BULLET, $line, $bullet) !== 1) {
            continue;
        }

        $class = preg_match(NEXT_SECTION_CLASS, $line, $found) === 1 ? $found[1] : '';

        // ⛔ A BULLET WITH NO READABLE CLASS IS NEVER WORK, AND IT IS NEVER DROPPED. The two halves of this
        //    seam share no code, so a reworded suffix must surface here rather than empty the ready list.
        $next[match (true) {
            $class === 'ready' => 'ready',
            $class === 'waiting on you' => 'waiting',
            str_starts_with($class, 'blocked') => 'blocked',
            default => 'unclassified',
        }][] = $bullet[1];
    }

    return $next;
}

/**
 * The ids, and how many the section left out of them. `M99` made the document state its cut; a reader that
 * prints twelve ids as if they were the tier undoes that.
 *
 * @param  list<string>  $ids
 */
function next_section_list(array $ids, int $more, string $class): string
{
    $list = implode(', ', $ids);

    return $more > 0
        ? sprintf('%s (%d of %d %s; the rest are in The line)', $list, count($ids), count($ids) + $more, $class)
        : $list;
}

/**
 * The section as labelled rows, for the two reports a terminal prints: `state.php` and `loop.php status`.
 *
 * @param  array<string, mixed>  $next  what parse_next_section() returned
 * @return array<string, string>
 */
function next_section_rows(array $next): array
{
    if ($next['tier'] === null) {
        return ['next work' => '(the pipeline lists none)'];
    }

    $rows = ['next work' => $next['tier'].' — '.($next['ready'] === []
        ? 'nothing here is ready to start'
        : next_section_list($next['ready'], $next['ready_more'], 'ready'))];

    if ($next['blocked'] !== []) {
        $rows['blocked, not work'] = next_section_list($next['blocked'], $next['blocked_more'], 'blocked');
    }

    if ($next['waiting'] !== []) {
        $rows['waiting on you, not work'] = implode(', ', $next['waiting']);
    }

    return $rows;
}

/**
 * The hand-off's sentence, written into `PROGRESS.md` by `scripts/next.php --write`.
 *
 * @param  array<string, mixed>  $next  what parse_next_section() returned
 */
function next_section_sentence(array $next): string
{
    if ($next['tier'] === null) {
        return 'The Next section lists no work outside held rows and open questions.';
    }

    $out = $next['ready'] === []
        ? 'The Next section holds no ready '.$next['tier'].' row, so nothing in it can be started: read The line.'
        : 'Take work from the Next section — '.$next['tier'].': '.next_section_list($next['ready'], $next['ready_more'], 'ready')
            .' — and group 3-4 rows of ONE tier by D13\'s file-overlap rule, which since D15 reads the files a'
            .' row\'s REMEDY EDITS rather than the files it cites: no two rows may edit the same file, and at most'
            .' one may touch a hub. The close-out artefacts every increment touches by procedure are excluded, so'
            .' compose the batch AFTER verifying the rows rather than before.';

    $notWork = [];

    if ($next['blocked'] !== []) {
        $notWork[] = 'blocked '.next_section_list($next['blocked'], $next['blocked_more'], 'blocked');
    }

    if ($next['waiting'] !== []) {
        $notWork[] = 'waiting on the user '.implode(', ', $next['waiting']);
    }

    if ($notWork !== []) {
        $out .= ' Not work, and shown so it stays visible: '.implode('; ', $notWork).'.';
    }

    $unreadable = next_section_unreadable($next);

    return $unreadable === null ? $out : $out.' '.$unreadable;
}

/**
 * What to say when a bullet carries no class this file can read, or null when every bullet does.
 *
 * @param  array<string, mixed>  $next  what parse_next_section() returned
 */
function next_section_unreadable(array $next): ?string
{
    return $next['unclassified'] === []
        ? null
        : 'The Next section has bullets with no readable class, so they are not named as work: '
            .implode(', ', $next['unclassified']).'. Regenerate it with php scripts/pipeline.php; if they remain,'
            .' scripts/pipeline.php and scripts/next-section.php have drifted apart.';
}
