<?php

declare(strict_types=1);

use App\Enums\FormVersionStatus;
use App\Support\Migrations\PublishedVersionGuard;

/*
|--------------------------------------------------------------------------
| Published-version immutability guard — SQL text pinning (Increment H25, ADR-0013, Risk R5).
|--------------------------------------------------------------------------
| The database behaviour is proven in tests/Feature/Forms/PublishedVersionImmutability*Test.php.
| THIS file exists for the properties a behavioural test cannot see, because they are properties of
| what the SQL DOES NOT say:
|   - the mutable allowlist IS the policy (the guard is "everything except this list"), so widening it
|     must surface as a diff in a security-reviewed test rather than as one quiet word in a const;
|   - both sides of the row diff must be present — a one-sided to_jsonb() is a silent no-op guard that
|     every behavioural test would still pass;
|   - no content column may be NAMED, because naming one means the fail-open hand-list came back;
|   - no bare `<>`, which would compare NULL to a value, yield NULL, and let the write through.
| Runs with no database (the generators are pure), so it fails in seconds in the fast suite.
*/

it('exempts exactly four lifecycle columns and nothing else', function (): void {
    // This list IS the policy. Everything not named here is frozen on a non-draft row, including every
    // column a future migration adds. Adding a name is a security decision, so it gets a red test first.
    expect(PublishedVersionGuard::mutableColumns())
        ->toBe(['status', 'superseded_at', 'updated_at', 'published_by']);
});

it('pins the status vocabulary to the FormVersionStatus enum', function (): void {
    expect(PublishedVersionGuard::statuses())
        ->toBe(array_column(FormVersionStatus::cases(), 'value'));
});

it('diffs the whole row, so a column that does not exist yet is already frozen', function (): void {
    $sql = PublishedVersionGuard::functionSql();

    // Both sides. A one-sided projection compares a row against itself and refuses nothing.
    expect($sql)->toContain('to_jsonb(OLD)')
        ->toContain('to_jsonb(NEW)')
        ->toContain("o.key NOT IN ('status', 'superseded_at', 'updated_at', 'published_by')")
        ->toContain('n.value::text IS DISTINCT FROM o.value::text');
});

it('names no content column anywhere, because a hand-list would be fail-open', function (): void {
    $sql = PublishedVersionGuard::functionSql();

    foreach (['schema_snapshot', 'checksum', 'version_number', 'change_summary', 'form_id', 'title'] as $column) {
        expect($sql)->not->toContain('OLD.'.$column)
            ->not->toContain('NEW.'.$column);
    }
});

it('compares with IS DISTINCT FROM and never a bare inequality', function (): void {
    // `<>` yields NULL when either side is NULL, and NULL is not TRUE, so the write would pass. Seven of
    // the frozen columns are nullable — including created_at, since timestampsTz() emits both nullable.
    $sql = PublishedVersionGuard::functionSql().PublishedVersionGuard::triggerSql();

    expect($sql)->not->toContain(' <> ')
        ->not->toContain(' != ');
});

it('allows exactly one status transition off a non-draft row, and never a return to draft', function (): void {
    $sql = PublishedVersionGuard::functionSql();

    expect($sql)->toContain("NOT (OLD.status = 'published' AND NEW.status = 'superseded')")
        // No branch may readmit 'draft': resurrection re-opens every child row, because the draft-child
        // RLS shape keys on form_versions.status = 'draft'.
        ->not->toContain("NEW.status = 'draft'");
});

it('lets superseded_at move only on that transition, and only out of NULL', function (): void {
    expect(PublishedVersionGuard::functionSql())
        ->toContain('AND OLD.superseded_at IS NULL)');
});

it('lets published_by be cleared but never re-pointed, so the users FK action still fires', function (): void {
    // published_by is ON DELETE SET NULL against users, and PostgreSQL runs that referential action as an
    // ordinary UPDATE through SPI — it bypasses RLS but NOT this trigger. Freezing the column outright
    // would make user hard-deletion (and GDPR erasure) permanently impossible.
    expect(PublishedVersionGuard::functionSql())
        ->toContain('NEW.published_by IS DISTINCT FROM OLD.published_by AND NEW.published_by IS NOT NULL');
});

it('raises an integrity-class error naming the row, the status and the columns', function (): void {
    $sql = PublishedVersionGuard::functionSql();

    expect($sql)->toContain("ERRCODE = 'restrict_violation'")
        ->toContain('column(s) % are immutable')
        ->toContain('id=%')
        ->toContain('status=%');

    // One per rule. A missing RAISE is a rule that computes a verdict and then returns NEW anyway.
    expect(substr_count($sql, 'RAISE EXCEPTION'))->toBe(4);
});

it('gates the trigger on OLD.status, never on published_at', function (): void {
    // A draft row can legitimately carry a published_at (ConnectorFanOutTest does exactly that), so a
    // `published_at IS NOT NULL` gate would both break it and miss a frozen row that was never published.
    expect(PublishedVersionGuard::triggerSql())
        ->toContain('BEFORE UPDATE ON form_versions')
        ->toContain('FOR EACH ROW')
        ->toContain("WHEN (OLD.status IS DISTINCT FROM 'draft')")
        ->toContain('EXECUTE FUNCTION form_versions_published_immutable_fn()')
        ->not->toContain('published_at');
});

it('is an UPDATE trigger only — no INSERT, no DELETE, no AFTER', function (): void {
    // Scope decision (ADR-0013): a BEFORE DELETE row trigger fires on the forms/tenants FK cascade too,
    // so it would turn tenant and form hard-deletion into an error. Recorded as Risk R12 instead.
    expect(PublishedVersionGuard::triggerSql())
        ->not->toContain('DELETE')
        ->not->toContain('INSERT')
        ->not->toContain('AFTER');
});

it('pins the status vocabulary in DDL as the guard\'s own fail-closed precondition', function (): void {
    // The trigger treats anything that is not 'draft' as frozen, so one typo'd status would freeze a row
    // permanently. The CHECK is what keeps that door shut.
    expect(PublishedVersionGuard::statusCheckSql())
        ->toContain('ADD CONSTRAINT form_versions_status_chk')
        ->toContain("CHECK (status IN ('draft', 'published', 'superseded'))");
});

it('replaces the function rather than creating it, because migrate:fresh leaves it behind', function (): void {
    // db:wipe drops tables, views and types — never routines. A bare CREATE FUNCTION therefore dies on the
    // SECOND migrate:fresh on any database, and CI cannot see it: every CI job provisions a fresh one.
    expect(PublishedVersionGuard::functionSql())->toStartWith('CREATE OR REPLACE FUNCTION');
    expect(PublishedVersionGuard::triggerSql())->toStartWith('CREATE TRIGGER');
});

it('quotes the function body with a named tag that appears nowhere inside it', function (): void {
    // The generator MUST use a nowdoc: an interpolating heredoc would eat $fn as a PHP variable.
    expect(substr_count(PublishedVersionGuard::functionSql(), '$fn$'))->toBe(2);
});

it('applies the check before the function and the function before the trigger', function (): void {
    $create = PublishedVersionGuard::createSql();

    expect($create)->toHaveCount(3);
    expect($create[0])->toStartWith('ALTER TABLE form_versions');
    expect($create[1])->toStartWith('CREATE OR REPLACE FUNCTION');
    expect($create[2])->toStartWith('CREATE TRIGGER');
});

it('drops the trigger before the function and never cascades', function (): void {
    // The trigger DEPENDS on the function, so the reverse order fails — and DROP FUNCTION ... CASCADE
    // would silently take the trigger with it, a bad habit to establish in the schema's first function.
    $drop = PublishedVersionGuard::dropSql();

    expect($drop)->toHaveCount(3);
    expect($drop[0])->toStartWith('DROP TRIGGER IF EXISTS form_versions_published_immutable_trg');
    expect($drop[1])->toStartWith('DROP FUNCTION IF EXISTS form_versions_published_immutable_fn');
    expect($drop[2])->toContain('DROP CONSTRAINT IF EXISTS form_versions_status_chk');
    expect(implode(' ', $drop))->not->toContain('CASCADE');
});

it('keeps every identifier inside PostgreSQL 63-byte NAMEDATALEN', function (): void {
    // Over 63 bytes PostgreSQL truncates SILENTLY, which is how two objects collide without an error.
    foreach ([PublishedVersionGuard::FUNCTION, PublishedVersionGuard::TRIGGER, PublishedVersionGuard::STATUS_CHECK] as $identifier) {
        expect(strlen($identifier))->toBeLessThanOrEqual(63);
    }
});
