<?php

declare(strict_types=1);

use App\Services\Ocr\Bakeoff\OcrBakeoffScan;
use App\Services\Ocr\Bakeoff\OcrBakeoffScorer;

/*
|--------------------------------------------------------------------------
| M136 — the bake-off's arithmetic: fields needing correction (PRD G9) and silent errors, per threshold.
|--------------------------------------------------------------------------
| Every expected figure below was worked out by hand from the twelve fields of `ocrBakeoffScorerScan()`, field by
| field, before the scorer was run — the comment beside each field says what it is. Since `D108` (M156) nothing is withheld:
| every value read is shown, below the review threshold marked "may be wrong", so a value read where the answer is blank is a
| correction at every confidence, and a right one below the threshold is not.
|
| ⚠️ Helpers are prefixed `ocrBakeoffScorer*`: Pest loads every test file into one process.
*/

/** @return array<string, mixed> one field as the matcher reports it, matched at zero thresholds */
function ocrBakeoffScorerField(string $type, string $state, mixed $value = null, ?int $confidence = null): array
{
    return ['type' => $type, 'state' => $state, 'value' => $value, 'text' => is_string($value) ? $value : null, 'confidence' => $confidence, 'tier' => null, 'page' => 0, 'anchored_by' => 'key'];
}

function ocrBakeoffScorerScan(): OcrBakeoffScan
{
    $fields = [
        'a' => ocrBakeoffScorerField('short_text', 'read', 'JUAN', 95),        // right, auto (case ignored)
        'b' => ocrBakeoffScorerField('integer', 'read', '34', 80),             // right, flagged
        'c' => ocrBakeoffScorerField('long_text', 'read', 'MILD FEVER', 50),   // right, below review: shown, marked may be wrong
        'd' => ocrBakeoffScorerField('short_text', 'read', 'XYZ', 50),         // shown below review, answer blank: a correction
        'e' => ocrBakeoffScorerField('integer', 'read', '35', 95),             // wrong, not flagged: silent
        'f' => ocrBakeoffScorerField('integer', 'read', '36', 75),             // wrong, flagged
        'g' => ocrBakeoffScorerField('date', 'not_found'),                     // answered, never found
        'h' => ocrBakeoffScorerField('time', 'blank'),                         // blank, answer blank: right
        'i' => ocrBakeoffScorerField('time', 'blank'),                         // blank, but answered: missed
        'j' => ocrBakeoffScorerField('single_select', 'read', 'f', 99),        // a value where the answer is blank: silent
        'k' => ocrBakeoffScorerField('date', 'read', '2026-03-10', 95),        // day and month swapped: silent
        'l' => ocrBakeoffScorerField('short_text', 'unreadable', null, 40),    // answered, unreadable
        'm' => ocrBakeoffScorerField('short_text', 'read', 'NOT SCORED', 99),  // no answer column: not scored
    ];

    return new OcrBakeoffScan('sheet1.jpg', ['sheet1.jpg'], 'read', null, 'clean', 1, 'stamp', $fields, [
        'a' => 'Juan', 'b' => '034', 'c' => 'mild  fever', 'd' => null, 'e' => '34', 'f' => '34', 'g' => '2026-10-03',
        'h' => null, 'i' => '14:05', 'j' => null, 'k' => '2026-10-03', 'l' => 'SANTOS',
    ]);
}

/**
 * @param  list<array<string, int|float|null>>|list<array<string, int>>  $rows
 * @return array<string, int|float|null>|array<string, int>
 */
function ocrBakeoffScorerRow(array $rows, string $column, int $at): array
{
    foreach ($rows as $row) {
        if ($row[$column] === $at) {
            return $row;
        }
    }

    throw new RuntimeException("no {$column} row at {$at}");
}

it('counts a field as needing correction exactly when the reviewer is shown something other than the answer', function (): void {
    $result = (new OcrBakeoffScorer)->score([ocrBakeoffScorerScan()], ['auto' => 90, 'review' => 70]);
    $headline = $result['headline'];

    // d, e, f, g, i, j, k, l — eight of the twelve with an answer column.
    expect($headline['fields'])->toBe(12)
        ->and($headline['needs_correction'])->toBe(8)
        ->and($headline['rate'])->toBe(8 / 12)
        ->and($headline['g9_pass'])->toBeFalse()
        ->and($headline['silent'])->toBe(3)    // e, j, k
        ->and($headline['flagged'])->toBe(4)   // b and f for review, c and d below it
        ->and($headline['day_swapped'])->toBe(1) // k
        ->and($headline['causes'])->toBe([
            'not_found' => 1, 'unreadable' => 1, 'skipped' => 0, 'missed' => 1,
            'unexpected' => 2, 'wrong_flagged' => 1, 'wrong_unflagged' => 2,
        ]);

    $verdicts = array_column(array_filter($result['observations'], static fn (array $o): bool => $o['scan'] === 'sheet1.jpg'), 'verdict', 'key');
    expect($verdicts['a'])->toBe('right')
        ->and($verdicts['b'])->toBe('right, flagged for review')
        ->and($verdicts['c'])->toBe('right, marked may be wrong')
        ->and($verdicts['d'])->toBe('a value read where the answer is blank')
        ->and($verdicts['h'])->toBe('right (blank)')
        ->and($verdicts['k'])->toContain('day and month swapped')
        ->and($verdicts['e'])->not->toContain('swapped')
        ->and($verdicts['m'])->toBe('not scored');
});

it('withholds nothing at any review threshold, so it no longer sweeps one (D108)', function (): void {
    $result = (new OcrBakeoffScorer)->score([ocrBakeoffScorerScan()], ['auto' => 90, 'review' => 70]);
    $strict = (new OcrBakeoffScorer)->score([ocrBakeoffScorerScan()], ['auto' => 100, 'review' => 100]);

    // A review threshold now only chooses between "check this" and "may be wrong"; what the reviewer is shown is the same.
    expect(array_key_exists('review_sweep', $result))->toBeFalse()
        ->and($strict['headline']['needs_correction'])->toBe(8)
        ->and($strict['headline']['flagged'])->toBe(8);
});

it('sweeps the auto threshold at the configured review threshold: silent errors against flagged fields', function (): void {
    $sweep = (new OcrBakeoffScorer)->score([ocrBakeoffScorerScan()], ['auto' => 90, 'review' => 70])['auto_sweep'];

    // An auto threshold below the review one flags only what falls below review (c and d, marked may be wrong).
    expect(ocrBakeoffScorerRow($sweep, 'auto', 50))->toBe(['auto' => 50, 'silent' => 4, 'flagged' => 2, 'right_unflagged' => 2])
        ->and(ocrBakeoffScorerRow($sweep, 'auto', 90))->toBe(['auto' => 90, 'silent' => 3, 'flagged' => 4, 'right_unflagged' => 1])
        ->and(ocrBakeoffScorerRow($sweep, 'auto', 100))->toBe(['auto' => 100, 'silent' => 0, 'flagged' => 8, 'right_unflagged' => 0]);
});

it('puts a confidence exactly at a threshold on the side the review screen does', function (): void {
    $scan = new OcrBakeoffScan('edge', ['edge.png'], 'read', null, null, 1, 'stamp', [
        'at_review' => ocrBakeoffScorerField('integer', 'read', '1', 70),
        'below_review' => ocrBakeoffScorerField('integer', 'read', '2', 69),
        'below_review_wrong' => ocrBakeoffScorerField('integer', 'read', '5', 69),
        'at_auto' => ocrBakeoffScorerField('integer', 'read', '9', 90),
        'below_auto' => ocrBakeoffScorerField('integer', 'read', '8', 89),
    ], ['at_review' => '1', 'below_review' => '2', 'below_review_wrong' => '6', 'at_auto' => '3', 'below_auto' => '4']);

    $result = (new OcrBakeoffScorer)->score([$scan], ['auto' => 90, 'review' => 70]);
    $causes = array_column($result['observations'], 'cause', 'key');

    expect($causes)->toBe([
        'at_review' => null, 'below_review' => null, 'below_review_wrong' => 'wrong_flagged', 'at_auto' => 'wrong_unflagged', 'below_auto' => 'wrong_flagged',
    ])->and($result['headline']['silent'])->toBe(1);
});

it('scores only scans that were read and have an answer row, and reports the rest', function (): void {
    $unanswered = new OcrBakeoffScan('photo2.jpg', ['photo2.jpg'], 'read', null, null, 1, 'unconfirmed', [
        'a' => ocrBakeoffScorerField('short_text', 'read', 'ANA', 92),
    ], null);
    $failed = new OcrBakeoffScan('bad.jpg', ['bad.jpg'], 'failed', 'unreadable_file: no', null, null, null, [], ['a' => 'X']);

    $result = (new OcrBakeoffScorer)->score([ocrBakeoffScorerScan(), $unanswered, $failed], ['auto' => 90, 'review' => 70]);
    $scans = array_column($result['scans'], null, 'name');

    expect($result['headline']['fields'])->toBe(12)
        ->and($result['headline']['scans'])->toBe(1)
        ->and($scans['photo2.jpg'])->toMatchArray(['has_answers' => false, 'scored' => 0, 'matched_by' => 'unconfirmed', 'counts' => ['read' => 1]])
        ->and($scans['bad.jpg'])->toMatchArray(['status' => 'failed', 'message' => 'unreadable_file: no', 'scored' => 0])
        ->and($result['histogram'][90])->toBe(['right' => 1, 'wrong' => 3])  // a right; e, j, k wrong (photo2 is not scored)
        ->and($result['histogram'][50])->toBe(['right' => 1, 'wrong' => 1]); // c right, d wrong
});

it('groups the figure by the condition column, and measures nothing when nothing is scored', function (): void {
    $photo = new OcrBakeoffScan('photo1.jpg', ['photo1.jpg'], 'read', null, 'photo', 1, 'stamp', [
        'a' => ocrBakeoffScorerField('short_text', 'read', 'JUAN', 95),
    ], ['a' => 'JUAN']);

    $result = (new OcrBakeoffScorer)->score([ocrBakeoffScorerScan(), $photo], ['auto' => 90, 'review' => 70]);
    $empty = (new OcrBakeoffScorer)->score([], ['auto' => 90, 'review' => 70]);

    expect(array_keys($result['by_condition']))->toBe(['clean', 'photo'])
        ->and($result['by_condition']['photo'])->toMatchArray(['fields' => 1, 'needs_correction' => 0, 'rate' => 0.0, 'g9_pass' => true])
        ->and($result['by_condition']['clean']['needs_correction'])->toBe(8)
        ->and($empty['headline'])->toMatchArray(['fields' => 0, 'rate' => null, 'g9_pass' => null]);
});
