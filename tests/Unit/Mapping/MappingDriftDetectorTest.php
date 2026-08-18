<?php

declare(strict_types=1);

use App\Support\Mapping\ColumnMapping;
use App\Support\Mapping\MappingDriftDetector;

// H16a's drift detector — the half of the shared engine H19a's linelist reuses verbatim
// (`docs/ocr-pipeline-design.md` §4: "if a later upload's detected column headers don't match the cached
// mapping's expected headers … the system flags the mismatch and asks for re-confirmation rather than
// silently applying a stale mapping to structurally different data").

function driftMapping(): ColumnMapping
{
    return ColumnMapping::author(
        ['Name', 'Age', 'Village'],
        ['Name' => 'full_name', 'Age' => 'age', 'Village' => 'village'],
    );
}

it('passes an unchanged header row', function (): void {
    $drift = (new MappingDriftDetector)->compare(driftMapping(), ['Name', 'Age', 'Village']);

    expect($drift->hasDrifted)->toBeFalse()
        ->and($drift->added)->toBeEmpty()
        ->and($drift->removed)->toBeEmpty()
        ->and($drift->moved)->toBeEmpty()
        ->and($drift->summary())->toBe('The column layout is unchanged.');
});

it('passes a header row that differs only cosmetically', function (): void {
    // The normalizer's whole purpose, asserted at the level the delivery path actually calls.
    $drift = (new MappingDriftDetector)->compare(driftMapping(), ['  name', 'AGE ', 'Village', '', null]);

    expect($drift->hasDrifted)->toBeFalse();
});

it('names an added column', function (): void {
    $drift = (new MappingDriftDetector)->compare(driftMapping(), ['Name', 'Age', 'Village', 'Phone']);

    expect($drift->hasDrifted)->toBeTrue()
        ->and($drift->added)->toBe(['phone'])
        ->and($drift->removed)->toBeEmpty()
        ->and($drift->summary())->toContain('added')
        ->and($drift->summary())->toContain('phone');
});

it('names a removed column', function (): void {
    $drift = (new MappingDriftDetector)->compare(driftMapping(), ['Name', 'Age']);

    expect($drift->hasDrifted)->toBeTrue()
        ->and($drift->removed)->toBe(['village'])
        ->and($drift->added)->toBeEmpty();
});

it('names a REORDER, which is the change a set comparison would miss entirely', function (): void {
    // The dangerous one: nothing was added, nothing was removed, and a "which columns exist?" check reports
    // no difference — while every subsequent append would write ages into the village column.
    $drift = (new MappingDriftDetector)->compare(driftMapping(), ['Name', 'Village', 'Age']);

    expect($drift->hasDrifted)->toBeTrue()
        ->and($drift->added)->toBeEmpty()
        ->and($drift->removed)->toBeEmpty()
        ->and($drift->moved)->toEqualCanonicalizing(['age', 'village'])
        ->and($drift->summary())->toContain('moved');
});

it('reports a rename as one removal and one addition, and suggests nothing', function (): void {
    // Deliberately NOT inferred as "Village was renamed to Barangay". Guessing that two labels are the same
    // column is precisely the inference that makes silent misalignment possible; a human re-confirms instead.
    $drift = (new MappingDriftDetector)->compare(driftMapping(), ['Name', 'Age', 'Barangay']);

    expect($drift->added)->toBe(['barangay'])
        ->and($drift->removed)->toBe(['village']);
});

it('derives the verdict from the DIGEST, never from the three axes', function (): void {
    // The load-bearing structural property. If `hasDrifted` were computed as "added + removed + moved are all
    // empty", any change the diff fails to characterise would read as "unchanged" — the silent-misalignment
    // failure reintroduced one level up, in the class written to prevent it.
    //
    // This mapping's stored digest belongs to a DIFFERENT header row, so the diff against the row we pass
    // finds nothing to report while the digest disagrees. Verdict must still be "drifted".
    $payload = driftMapping()->toArray();
    $payload['fingerprint'] = str_repeat('f', 64);

    $drift = (new MappingDriftDetector)->compare(
        ColumnMapping::fromArray($payload),
        ['Name', 'Age', 'Village'],
    );

    expect($drift->hasDrifted)->toBeTrue()
        ->and($drift->added)->toBeEmpty()
        ->and($drift->removed)->toBeEmpty()
        ->and($drift->moved)->toBeEmpty()
        // ...and the summary must not render "The columns changed: ." with nothing after the colon.
        ->and($drift->summary())->toBe('The column layout no longer matches the one this rule was set up against.');
});

it('reports an emptied header row as every column removed', function (): void {
    // A tenant deleting the header row, or pointing the rule at a fresh empty tab. Must never read as
    // "unchanged" just because there is nothing to compare against.
    $drift = (new MappingDriftDetector)->compare(driftMapping(), []);

    expect($drift->hasDrifted)->toBeTrue()
        ->and($drift->removed)->toEqualCanonicalizing(['name', 'age', 'village']);
});

it('bounds the summary rather than echoing an unbounded header row back', function (): void {
    $mapping = ColumnMapping::author(['A'], ['A' => 'a']);
    $drift = (new MappingDriftDetector)->compare($mapping, ['A', 'B', 'C', 'D', 'E', str_repeat('x', 500)]);

    expect($drift->added)->toHaveCount(5)
        ->and($drift->summary())->toContain('and 2 more')
        ->and(mb_strlen($drift->summary()))->toBeLessThan(300);
});
