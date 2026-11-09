<?php

declare(strict_types=1);

/*
 * An answered retier, checked to have reached the row it names (M231, `R-f6eab58d`).
 *
 * WHY THIS EXISTS. A decision that asks "which tier?" is answered in `docs/claims/decisions.md`, and its
 * PAYLOAD is a change to another file: the row in `docs/feature-backlog.md` has to move to the answered
 * tier. `M105` answered `D51` and `D53`, stripped both Awaits tokens, and left both rows at their old
 * tiers; `pipeline-lint` passed it green, and the only record was a paragraph in a lane ledger. Nothing
 * read a decision's answer for the row it moves, so nothing would notice the next one.
 *
 * WHAT IT READS — two shapes, both measured on the trunk before this was written (12 pairs):
 *     on an answer's **What the answer files, in the same push:** line (9 pairs, since `D104`)
 *         `R-x` retiered `early-testing`
 *         `R-x`, `R-y` and `R-z` retiered `early-testing`      ← every id in the run
 *         `R-x` (a parenthetical) is retiered `during-testing`
 *         `R-x` is retiered `after-launch` → `during-testing`   ← the tier after the arrow
 *     on a decision heading that asks for one row's tier (3 pairs, `D51` to `D53`)
 *         ### Dn — `R-x`, … Which tier? **A — `before-launch`.**   (or **C — keep `early-testing`.**)
 * Only the ANSWERED half is read: an open question has no payload yet.
 *
 * WHAT IT JUDGES. When several answers name one row, the latest decision's tier is the one judged.
 *     an open row at the answered tier, or a closed row       → fine
 *     an open row at another tier, with a retier recorded AT THE ROW (a bold `Retiered up from …`,
 *       `Retiered down from …` or a bold RETIERED a → b)          → fine: it moved again, and said why
 *     an open row at another tier, and nothing at the row      → FAILURE, the payload was never performed
 *     a tier outside the vocabulary                            → FAILURE
 *     an id no row carries                                     → UNRESOLVED, listed and never failed
 *
 * ⛔ AN UNRESOLVED ID IS NEVER A FAILURE, BECAUSE NOTHING ON DISK CAN SAY WHY IT IS UNRESOLVED. A row id
 *    hashes the row's headline (`state.php`'s `finish_row()`), and striking a row through with `~~…~~`
 *    changes that headline, so a struck row's id stops resolving exactly as a reworded open row's does.
 *    `D52`'s `R-e6a10f97` and `D53`'s `R-e7d6f223` are both struck rows. That join key is `R-7d0853f5`'s
 *    point; this file reports the count instead of guessing.
 *
 * ⚠️ WHAT IT CANNOT REACH, said here rather than discovered later:
 *    - a payload that names no id: `D128`'s "the sixteen UI rows retiered `early-testing`";
 *    - a plan marker's retier: `D24`'s `deferral-site-disposition` keeps its tier in its marker, and
 *      `state.php` does not parse markers;
 *    - ANY retier recorded at the row passes, the record of the answer being judged included, because the
 *      row's records carry no common machine-readable date or destination to order them by. So a row
 *      that recorded the answer's retier and was later moved again WITHOUT a record is missed: measured
 *      on `R-5ecfa6cd`, whose own bold RETIERED record of `D51` keeps it green at any tier.
 *
 * ⛔ PURE, AND THAT IS WHY IT IS ITS OWN FILE. `state.php` runs top to bottom and shells `git` and `gh`
 *    when loaded, so a test cannot require it. This file does nothing on load, so
 *    `tests/Feature/Docs/RetierPayloadTest.php` calls the real parser and `scripts/mutate.php` can prove
 *    it. The tier vocabulary is PASSED IN, never copied here: `PipelineLintControlsTest` pins every copy
 *    of `TIERS`, and an unpinned fifth copy is two facts that drift.
 *
 * ⛔ IT NEVER REFUSES. `state.php` decides what a failure costs (`--check` exits 1 on one); a document
 *    with no ANSWERED heading yields no payloads here, and `state.php`'s `derive_decisions()` already
 *    refuses that document by name.
 */

/** The answer line that carries a decision's payload. */
const RETIER_ANSWER_LINE = '**What the answer files, in the same push:**';

/**
 * A run of backticked row ids, an optional parenthetical, then "retiered" and the tier. The tier is the one
 * after an arrow when there is one.
 */
const RETIER_ANSWER_PAYLOAD = '/((?:`R-[0-9a-f]{8}`(?:,\s+and\s+|,\s+|\s+and\s+)?)+)(?:\s*\([^()]*\))?\s+(?:is\s+|are\s+)?retiered\s+(?:`[a-z-]+`\s*→\s*)?`([a-z-]+)`/u';

/** A decision heading that asks for one row's tier, and its answer. */
const RETIER_HEADING_PAYLOAD = '/^### (D\d+) — `(R-[0-9a-f]{8})`, .*Which tier\? \*\*[A-Z] — (?:keep )?`([a-z-]+)`\.\*\*/u';

/** A retier recorded at the row itself, read with code spans stripped. */
const RETIER_RECORDED_AT_ROW = '/\*\*\s*re-?tiered\b/iu';

/**
 * Every retier an answered decision commissions, in document order.
 *
 * @return list<array{decision: string, id: string, tier: string, shape: string}>
 */
function retier_payloads(string $decisions): array
{
    $parts = preg_split('/^## ANSWERED$/mu', $decisions, 2);

    if (! is_array($parts) || ! isset($parts[1])) {
        return [];
    }

    $payloads = [];
    $decision = '';

    foreach (explode("\n", $parts[1]) as $line) {
        $line = rtrim($line, "\r");

        if (preg_match('/^### (D\d+) — /u', $line, $heading) === 1) {
            $decision = $heading[1];

            if (preg_match(RETIER_HEADING_PAYLOAD, $line, $m) === 1) {
                $payloads[] = ['decision' => $decision, 'id' => $m[2], 'tier' => $m[3], 'shape' => 'heading'];
            }

            continue;
        }

        if (! str_contains($line, RETIER_ANSWER_LINE)) {
            continue;
        }

        preg_match_all(RETIER_ANSWER_PAYLOAD, $line, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            preg_match_all('/`(R-[0-9a-f]{8})`/u', $match[1], $ids);

            foreach ($ids[1] as $id) {
                $payloads[] = ['decision' => $decision, 'id' => $id, 'tier' => $match[2], 'shape' => 'answer'];
            }
        }
    }

    return $payloads;
}

/**
 * Whether a row's own text records that its tier was moved. Code spans are stripped first, so a row that
 * QUOTES the record form does not count as carrying one.
 */
function row_records_retier(string $body): bool
{
    $prose = preg_replace('/`[^`]*`/u', '', $body) ?? $body;

    return preg_match(RETIER_RECORDED_AT_ROW, $prose) === 1;
}

/**
 * The payloads, judged against the rows.
 *
 * @param  list<array{decision: string, id: string, tier: string, shape: string}>  $payloads
 * @param  list<array<string, mixed>>  $rows  each with `id`, `state` (open|closed), `tier` and `records_retier`
 * @param  list<string>  $tiers  the tier vocabulary, passed in rather than copied
 * @return array{pairs: int, judged: int, failures: list<string>, unresolved: list<string>}
 */
function judge_retiers(array $payloads, array $rows, array $tiers): array
{
    $byId = [];

    foreach ($rows as $row) {
        $byId[(string) $row['id']] = $row;
    }

    // The latest answer for a row is the one its tier must match; an earlier one was superseded by it.
    $latest = [];

    foreach ($payloads as $payload) {
        $held = $latest[$payload['id']] ?? null;

        if ($held === null || (int) substr($payload['decision'], 1) >= (int) substr($held['decision'], 1)) {
            $latest[$payload['id']] = $payload;
        }
    }

    $failures = [];
    $unresolved = [];
    $judged = 0;

    foreach ($latest as $id => $payload) {
        if (! in_array($payload['tier'], $tiers, true)) {
            $failures[] = sprintf(
                '%s answers %s with `%s`, which is not a tier (%s).',
                $payload['decision'], $id, $payload['tier'], implode(', ', $tiers),
            );

            continue;
        }

        $row = $byId[$id] ?? null;

        if ($row === null) {
            $unresolved[] = $payload['decision'].' '.$id;

            continue;
        }

        $judged++;

        if (($row['state'] ?? '') !== 'open' || ($row['tier'] ?? '') === $payload['tier'] || ($row['records_retier'] ?? false) === true) {
            continue;
        }

        $failures[] = sprintf(
            '%s answered %s `%s`, and the row is open at `%s` with no retier recorded at the row. Move it to the '
            .'answered tier, or record at the row why it moved again.',
            $payload['decision'], $id, $payload['tier'], ($row['tier'] ?? '') === '' ? 'no tier' : $row['tier'],
        );
    }

    return [
        'pairs' => count($payloads),
        'judged' => $judged,
        'failures' => $failures,
        'unresolved' => $unresolved,
    ];
}
