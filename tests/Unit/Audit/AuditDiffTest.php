<?php

declare(strict_types=1);

use App\Support\Audit\AuditDiff;
use App\Support\Audit\AuditRedactor;

// Pure diff-rendering tests (no database). AuditDiff has two consumers — the viewer presenter and the
// CSV/XLSX exporter — so every rule pinned here is a rule those two cannot drift apart on.

it('emits new-side keys in the author\'s order, then appends old-only keys', function (): void {
    // NOT alphabetical, deliberately: these payloads are literal arrays a service author wrote in a
    // meaningful order. Sorting would put `allow_guest_submissions` above `public_slug` and `archived_at`
    // above `status` — the one key that is the whole story of the row.
    $rows = AuditDiff::rows(
        old: ['public_slug' => 'old-slug', 'draft_version_id' => 'v1'],
        new: ['public_slug' => 'new-slug', 'allow_guest_submissions' => true],
        redacted: null,
    );

    expect(array_column($rows, 'key'))->toBe(['public_slug', 'allow_guest_submissions', 'draft_version_id']);
});

it('distinguishes an ABSENT key from a key holding null', function (): void {
    // The distinction the whole shape exists for. `null` = the key was not in that payload at all;
    // the em-dash = a real column set to nothing. "(absent) → intake" (a created event with no before)
    // and "— → intake" (a column that was empty and now has a value) are different facts.
    $rows = AuditDiff::rows(
        old: ['title' => null],
        new: ['title' => 'Clinic Intake', 'description' => 'New'],
        redacted: null,
    );

    $byKey = array_column($rows, null, 'key');

    expect($byKey['title']['old'])->toBe('—');
    expect($byKey['title']['new'])->toBe('Clinic Intake');
    expect($byKey['description']['old'])->toBeNull();
    expect($byKey['description']['new'])->toBe('New');
});

it('renders booleans as Yes/No, not 1 and the empty string', function (): void {
    $rows = AuditDiff::rows(
        old: ['allow_guest_submissions' => false],
        new: ['allow_guest_submissions' => true],
        redacted: null,
    );

    expect($rows[0]['old'])->toBe('No');
    expect($rows[0]['new'])->toBe('Yes');
});

it('renders numbers as strings and structures as compact JSON', function (): void {
    $rows = AuditDiff::rows(
        old: ['max_responses' => 100, 'locales' => ['en']],
        new: ['max_responses' => 250, 'locales' => ['en', 'fr']],
        redacted: null,
    );

    $byKey = array_column($rows, null, 'key');

    expect($byKey['max_responses']['old'])->toBe('100');
    expect($byKey['max_responses']['new'])->toBe('250');
    expect($byKey['locales']['new'])->toBe('["en","fr"]');
});

it('does not escape slashes in a URL value', function (): void {
    // json_encode's default would render https:\/\/example.test — unreadable in a ledger cell, and the
    // first place anyone notices is a webhook endpoint diff.
    $rows = AuditDiff::rows(
        old: null,
        new: ['targets' => ['https://hooks.example.test/inbound']],
        redacted: null,
    );

    expect($rows[0]['new'])->toBe('["https://hooks.example.test/inbound"]');
});

it('caps a long value for the screen and leaves it whole for the export', function (): void {
    $long = str_repeat('a', AuditDiff::PREVIEW_CHARS + 50);

    $capped = AuditDiff::rows(old: null, new: ['remarks' => $long], redacted: null);
    $uncapped = AuditDiff::rows(old: null, new: ['remarks' => $long], redacted: null, cap: null);

    expect(mb_strlen((string) $capped[0]['new']))->toBe(AuditDiff::PREVIEW_CHARS + 1); // + the ellipsis
    expect($capped[0]['new'])->toEndWith('…');
    expect($uncapped[0]['new'])->toBe($long);
});

it('renders a redacted key as the stored placeholder on BOTH sides and flags it (§2.1)', function (): void {
    // THE LOAD-BEARING TEST OF THIS CLASS. By the time a row is written, AuditRedactor has already
    // replaced the value on both sides — so there is no real prior value to recover, and any future
    // "fix" that nulls, hides or reconstructs the before side is defeating an erasure the row records.
    $redacted = (new AuditRedactor)->redact(
        'submission',
        old: ['status' => 'submitted', 'remarks' => 'called the mother'],
        new: ['status' => 'approved', 'remarks' => 'verified'],
    );

    $rows = AuditDiff::rows($redacted['old'], $redacted['new'], $redacted['redacted_fields']);
    $byKey = array_column($rows, null, 'key');

    expect($byKey['remarks']['old'])->toBe(AuditRedactor::PLACEHOLDER);
    expect($byKey['remarks']['new'])->toBe(AuditRedactor::PLACEHOLDER);
    expect($byKey['remarks']['redacted'])->toBeTrue();

    // …and an unredacted key in the SAME diff is untouched, which is what makes the flag meaningful.
    expect($byKey['status']['old'])->toBe('submitted');
    expect($byKey['status']['new'])->toBe('approved');
    expect($byKey['status']['redacted'])->toBeFalse();
});

it('flags a key redacted on one side only', function (): void {
    // AuditRedactor only replaces keys that are present, so `old` can carry a secret key `new` does not.
    $rows = AuditDiff::rows(
        old: ['secret' => AuditRedactor::PLACEHOLDER],
        new: ['url' => 'https://example.test'],
        redacted: ['secret'],
    );

    $byKey = array_column($rows, null, 'key');

    expect($byKey['secret']['old'])->toBe(AuditRedactor::PLACEHOLDER);
    expect($byKey['secret']['new'])->toBeNull();
    expect($byKey['secret']['redacted'])->toBeTrue();
});

it('returns no rows for an event with no payload at all', function (): void {
    // `exported` records an act with nothing to diff. The viewer disables its "View changes" action on
    // exactly this shape rather than opening an empty dialog.
    expect(AuditDiff::rows(null, null, null))->toBe([]);
});
