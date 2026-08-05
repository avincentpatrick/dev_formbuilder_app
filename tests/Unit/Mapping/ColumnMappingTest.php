<?php

declare(strict_types=1);

use App\Support\Mapping\ColumnFingerprint;
use App\Support\Mapping\ColumnMapping;
use App\Support\Mapping\MappingDriftDetector;

// The shared column-mapping/drift engine (H16a), which H19a's OCR linelist reuses. Pure — no container, no
// database, no HTTP — so these run as plain Unit tests.

// ── ColumnFingerprint ───────────────────────────────────────────────────────────────────────────────────

it('absorbs the header edits a human cannot see', function (string $variant): void {
    // Case, leading/trailing space and a doubled interior space are all invisible on screen. Treating any of
    // them as drift would pause a tenant's rule over nothing and train them to click through the warning,
    // which is how a real drift eventually gets waved past.
    expect(ColumnFingerprint::forHeaders([$variant, 'Age'])->digest)
        ->toBe(ColumnFingerprint::forHeaders(['Village Name', 'Age'])->digest);
})->with(['Village Name', 'village name', '  Village Name  ', 'Village  Name', "Village\tName"]);

it('treats a reorder as a different table', function (): void {
    // THE property the whole engine turns on. Both consumers address columns positionally, so same-labels-
    // different-order is not the same table: applying the old mapping would write every answer one column off.
    // A set-based digest would call these equal.
    expect(ColumnFingerprint::forHeaders(['Name', 'Age'])->digest)
        ->not->toBe(ColumnFingerprint::forHeaders(['Age', 'Name'])->digest);
});

it('ignores trailing padding but not an interior blank', function (): void {
    // Sheets pads the header row out to the sheet's column count, so the padding is an artifact of reading,
    // not of authoring. An INTERIOR blank is a deliberate spacer column and dropping it would shift every
    // column after it — the same one-off misalignment, arrived at from the other direction.
    expect(ColumnFingerprint::forHeaders(['Name', 'Age', '', '', null])->digest)
        ->toBe(ColumnFingerprint::forHeaders(['Name', 'Age'])->digest);

    expect(ColumnFingerprint::forHeaders(['Name', '', 'Age'])->digest)
        ->not->toBe(ColumnFingerprint::forHeaders(['Name', 'Age'])->digest);
});

it('cannot be collided by concatenation or by a header containing the delimiter', function (): void {
    // Two distinct collisions, both pinned, because the separator is the ONLY thing preventing either.
    //
    // (a) With no separator at all, ['ab'] and ['a','b'] both canonicalize to "ab". This assertion is what
    //     made removing the terminator a reddening mutation — the redundant column index that used to sit
    //     beside it was pinned by nothing, which is how mutation testing found it was doing no work.
    expect(ColumnFingerprint::forHeaders(['ab'])->digest)
        ->not->toBe(ColumnFingerprint::forHeaders(['a', 'b'])->digest);

    // (b) With a PRINTABLE separator such as "|", a header literally named `a|b` impersonates the two columns
    //     `a` and `b` — the classic delimiter-injection bug wearing a hashing costume. "\x1E" cannot occur in
    //     a spreadsheet cell, so no header can forge one.
    expect(ColumnFingerprint::forHeaders(['a|b'])->digest)
        ->not->toBe(ColumnFingerprint::forHeaders(['a', 'b'])->digest);

    // A LEADING blank shifts every column right by one, so it must change the digest — unlike a trailing
    // blank, which is padding and is dropped. (`['a', '']` is deliberately EQUAL to `['a']`; asserting
    // otherwise here would contradict the trailing-padding test above.)
    expect(ColumnFingerprint::forHeaders(['a'])->digest)
        ->not->toBe(ColumnFingerprint::forHeaders(['', 'a'])->digest)
        ->and(ColumnFingerprint::forHeaders([])->isEmpty())->toBeTrue()
        ->and(ColumnFingerprint::forHeaders(['a', ''])->digest)->toBe(ColumnFingerprint::forHeaders(['a'])->digest);
});

// ── Authoring ───────────────────────────────────────────────────────────────────────────────────────────

it('binds headers to field keys through the same normalizer the fingerprint uses', function (): void {
    // If authoring matched raw labels while the fingerprint matched normalized ones, the two halves of the
    // class would disagree about what a header IS: a mapping could be fingerprint-valid and yet bind nothing.
    $mapping = ColumnMapping::author(
        ['  Village Name ', 'AGE'],
        ['village name' => 'village', 'Age' => 'age'],
    );

    expect($mapping->boundFieldKeys())->toBe(['village', 'age'])
        ->and($mapping->columnCount())->toBe(2);
});

it('keeps an unmapped column as an unbound cell rather than dropping it', function (): void {
    // A tenant's own notes column sits between two of ours. Dropping it from the mapping would make project()
    // emit two cells for a three-column sheet and shift their notes under an answer.
    $mapping = ColumnMapping::author(
        ['Name', 'Reviewer notes', 'Age'],
        ['Name' => 'full_name', 'Age' => 'age'],
    );

    expect($mapping->columnCount())->toBe(3)
        ->and($mapping->columns[1]->isBound())->toBeFalse()
        ->and($mapping->project(['full_name' => 'Ana', 'age' => '31']))->toBe(['Ana', '', '31']);
});

it('refuses to bind one field key to two columns', function (): void {
    // Ambiguity on the READ side: interpret() would have to pick a winner and any choice would be arbitrary.
    // Refusing at authoring time is the only point where a human is present to fix it.
    expect(fn () => ColumnMapping::author(['Age', 'Age (years)'], ['Age' => 'age', 'Age (years)' => 'age']))
        ->toThrow(InvalidArgumentException::class, 'more than one column');
});

// ── project() — the WRITE direction (H16a) ──────────────────────────────────────────────────────────────

it('emits exactly one cell per column, including for a field with no answer', function (): void {
    // A short row silently re-aligns the tenant's spreadsheet, so "no answer" must still occupy its column.
    $mapping = ColumnMapping::author(['Name', 'Age', 'Village'], [
        'Name' => 'full_name', 'Age' => 'age', 'Village' => 'village',
    ]);

    expect($mapping->project(['full_name' => 'Ana']))->toBe(['Ana', '', ''])
        ->and($mapping->project([]))->toHaveCount(3);
});

// ── interpret() — the READ direction (H19a) ─────────────────────────────────────────────────────────────

it('reads a positional row back into field keys', function (): void {
    // Shipped in H16a rather than deferred to H19a so the reuse claim is demonstrable now. If the engine only
    // ever ran one direction, H19a would discover the shape does not fit and write a second one — which is
    // exactly the outcome un-deferring H16a was chosen to prevent.
    $mapping = ColumnMapping::author(['Name', 'Notes', 'Age'], ['Name' => 'full_name', 'Age' => 'age']);

    expect($mapping->interpret(['Ana', 'seen twice', '31']))
        ->toBe(['full_name' => 'Ana', 'age' => '31']);
});

it('treats a missing trailing cell as absent, not as a blank answer', function (): void {
    // A spreadsheet omits trailing empty cells and a scan can lose its last column to the page edge. "The OCR
    // could not see this" and "the respondent left it empty" are different facts, and the H18b/H19b review UI
    // has to be able to tell them apart — so the key is ABSENT rather than present-and-empty.
    $mapping = ColumnMapping::author(['Name', 'Age'], ['Name' => 'full_name', 'Age' => 'age']);

    expect($mapping->interpret(['Ana']))->toBe(['full_name' => 'Ana'])
        ->and($mapping->interpret(['Ana', '']))->toBe(['full_name' => 'Ana', 'age' => ''])
        ->and($mapping->interpret(['Ana', '31', 'extra ignored']))->toBe(['full_name' => 'Ana', 'age' => '31']);
});

// ── Storage round-trip ──────────────────────────────────────────────────────────────────────────────────

it('round-trips through the stored config payload', function (): void {
    $mapping = ColumnMapping::author(['Name', 'Notes', 'Age'], ['Name' => 'full_name', 'Age' => 'age']);
    $restored = ColumnMapping::fromArray($mapping->toArray());

    expect($restored->fingerprint->digest)->toBe($mapping->fingerprint->digest)
        ->and($restored->project(['full_name' => 'Ana', 'age' => '31']))->toBe(['Ana', '', '31']);
});

it('trusts the STORED digest rather than recomputing one from the stored headers', function (): void {
    // The vacuity guard, and the reason fromArray() does not call forHeaders(). Recomputing would make every
    // stored mapping fingerprint-valid by construction and the drift check could never fire. The stored digest
    // is a claim about the ARTIFACT and has to be able to disagree with the headers we happen to hold.
    $payload = ColumnMapping::author(['Name', 'Age'], ['Name' => 'full_name'])->toArray();
    $payload['fingerprint'] = str_repeat('0', 64);

    $restored = ColumnMapping::fromArray($payload);
    $drift = (new MappingDriftDetector)->compare($restored, ['Name', 'Age']);

    expect($drift->hasDrifted)->toBeTrue();
});

it('refuses a malformed stored mapping instead of repairing it', function (): void {
    // Silently repairing one writes answers into columns nobody authored.
    expect(fn () => ColumnMapping::fromArray(['columns' => []]))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => ColumnMapping::fromArray(['fingerprint' => 'abc']))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => ColumnMapping::fromArray(['fingerprint' => 'abc', 'columns' => [['field_key' => 'age']]]))
        ->toThrow(InvalidArgumentException::class, 'no `header`');
});
