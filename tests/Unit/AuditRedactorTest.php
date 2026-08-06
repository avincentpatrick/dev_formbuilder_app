<?php

declare(strict_types=1);

use App\Support\Audit\AuditRedactor;

// Pure redaction tests (no database). Pin audit-compliance-logging-spec §2: unconditional secrets, PII
// flags, the transparency record in redacted_fields, and the §2.1 erasure special case (both sides
// placeholdered — never "old = real, new = redacted").

beforeEach(function (): void {
    $this->redactor = new AuditRedactor;
});

it('unconditionally redacts platform secrets on both sides', function (): void {
    $result = $this->redactor->redact(
        'users',
        old: ['name' => 'Ada', 'password' => 'old-hash', 'remember_token' => 'r1'],
        new: ['name' => 'Ada Lovelace', 'password' => 'new-hash', 'remember_token' => 'r2'],
    );

    // Non-secret fields keep their real values …
    expect($result['old']['name'])->toBe('Ada');
    expect($result['new']['name'])->toBe('Ada Lovelace');
    // … secrets are placeholdered on BOTH sides, never stored.
    expect($result['old']['password'])->toBe(AuditRedactor::PLACEHOLDER);
    expect($result['new']['password'])->toBe(AuditRedactor::PLACEHOLDER);
    expect($result['old']['remember_token'])->toBe(AuditRedactor::PLACEHOLDER);
    expect($result['redacted_fields'])->toContain('password')->toContain('remember_token');
});

it('redacts PII columns declared for the auditable type', function (): void {
    $result = $this->redactor->redact(
        'submission',
        old: null,
        new: ['form_id' => 'f1', 'guest_contact_email' => 'a@b.test', 'guest_ip' => '1.2.3.4'],
    );

    expect($result['new']['form_id'])->toBe('f1');
    expect($result['new']['guest_contact_email'])->toBe(AuditRedactor::PLACEHOLDER);
    expect($result['new']['guest_ip'])->toBe(AuditRedactor::PLACEHOLDER);
    expect($result['redacted_fields'])->toEqualCanonicalizing(['guest_contact_email', 'guest_ip']);
});

it('redacts a reviewer\'s free-text remarks but keeps the returned reason (I2)', function (): void {
    // The asymmetry is the decision, not an oversight. `remarks` is an internal note with no audience
    // discipline — a reviewer can paste respondent PII into it — and `audits` is never deleted.
    // `returned_reason` was authored FOR the respondent and already emailed to them, so it is exactly the
    // accountability the ledger exists to preserve.
    $result = $this->redactor->redact(
        'submission',
        old: ['status' => 'under_review', 'remarks' => 'called the mother, number is 555-0101', 'returned_reason' => null],
        new: ['status' => 'returned', 'remarks' => 'still incomplete', 'returned_reason' => 'Missing page 2'],
    );

    expect($result['old']['remarks'])->toBe(AuditRedactor::PLACEHOLDER);
    expect($result['new']['remarks'])->toBe(AuditRedactor::PLACEHOLDER);
    expect($result['new']['returned_reason'])->toBe('Missing page 2');
    expect($result['new']['status'])->toBe('returned');
    expect($result['redacted_fields'])->toBe(['remarks']);
});

it('leaves a submission payload that carries no remarks key untouched', function (): void {
    // SubmissionFinalizer's `created` payload has no `remarks` key, and apply() guards on
    // array_key_exists — so adding `remarks` to the PII map is a no-op there. Pinned because that is the
    // one place the new registration could have silently changed an existing audited site.
    $result = $this->redactor->redact('submission', old: null, new: ['form_id' => 'f1', 'status' => 'submitted']);

    expect($result['new'])->toBe(['form_id' => 'f1', 'status' => 'submitted']);
    expect($result['redacted_fields'])->toBeNull();
});

it('records exactly the stripped field names, and null when nothing was stripped', function (): void {
    $clean = $this->redactor->redact('tenant', old: ['status' => 'active'], new: ['status' => 'suspended']);

    // A tenant status change carries no secret/PII → nothing redacted, redacted_fields is null (§13 nullable).
    expect($clean['old'])->toBe(['status' => 'active']);
    expect($clean['new'])->toBe(['status' => 'suspended']);
    expect($clean['redacted_fields'])->toBeNull();
});

it('placeholders BOTH sides for an erased key — never old=real, new=redacted (§2.1)', function (): void {
    // The erasure special case: logging the pre-erasure raw value in old_values would defeat the erasure
    // the event records. So a caller-flagged erased key is placeholdered on the "before" side too.
    $result = $this->redactor->redact(
        'submission',
        old: ['answer_email' => 'real@person.test'],
        new: ['answer_email' => null],
        erasedKeys: ['answer_email'],
    );

    expect($result['old']['answer_email'])->toBe(AuditRedactor::PLACEHOLDER);
    expect($result['new']['answer_email'])->toBe(AuditRedactor::PLACEHOLDER);
    expect($result['redacted_fields'])->toBe(['answer_email']);
});

it('passes null arrays through untouched', function (): void {
    $result = $this->redactor->redact('users', old: null, new: null);

    expect($result['old'])->toBeNull();
    expect($result['new'])->toBeNull();
    expect($result['redacted_fields'])->toBeNull();
});
