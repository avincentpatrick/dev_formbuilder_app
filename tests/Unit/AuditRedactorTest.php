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
