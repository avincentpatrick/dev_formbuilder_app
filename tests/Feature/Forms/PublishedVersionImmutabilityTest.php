<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\FormVersionStatus;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Support\Migrations\PublishedVersionGuard;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Published-version immutability trigger (Increment H25, ADR-0013, Risk R5).
|--------------------------------------------------------------------------
| The sibling pack FormVersionRlsTest proves what RLS covers: a published version's CONTENT rows are
| frozen, and the version row cannot be DELETEd. This pack proves what RLS structurally cannot express —
| that the version ROW's own columns cannot be rewritten — because a policy sees only OLD in USING and
| only NEW in WITH CHECK, and no clause can compare them.
|
| Two house rules, inherited from FormVersionRlsTest and load-bearing here:
|   (1) A raised exception ABORTS the test transaction, so it must be the final DB interaction — hence
|       exactly ONE throwing assertion per test.
|   (2) Every refusal case therefore OPENS with a non-throwing anti-vacuity assertion (a bare updated_at
|       touch affecting 1 row). Without it a mistyped WHERE would pass the test by not throwing at all.
|
| Assertions use raw DB:: queries (no Eloquent scope) so what is proven is the DATABASE's enforcement.
*/

beforeEach(function (): void {
    TenantContext::flush();
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/**
 * A tenant with one PUBLISHED v1 (and the v2 draft publish clones forward), created through the real
 * PublishService — the only path by which a frozen row legitimately comes to exist.
 *
 * The publisher is deliberately a DIFFERENT user from the form owner, so the users hard-delete test can
 * remove them without tripping `forms.owner_user_id`'s own foreign key.
 *
 * @return array{tenant: Tenant, owner: User, publisher: User, form: Form, published: FormVersion}
 */
function seedPublishedVersion(): array
{
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    $publisher = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');
    // The publisher is a real member too, and that matters for the hard-delete test rather than being
    // decoration: `users` carries a join-shape SELECT policy, and PostgreSQL applies SELECT policies to a
    // WHERE-qualified DELETE as well — so a non-member would simply match zero rows and the FK referential
    // action this pack exists to exercise would never fire at all.
    makeActiveMember($publisher, 'admin');

    $form = app(FormService::class)->create($tenant, $owner, 'Household Survey');
    addFormField($form->draftVersion, $owner, 'full_name', FieldType::ShortText);
    app(PublishService::class)->publish($form->refresh(), $publisher);
    $form->refresh();

    $published = FormVersion::query()->findOrFail($form->current_published_version_id);

    return compact('tenant', 'owner', 'publisher', 'form', 'published');
}

/** A raw, unscoped update of one form_versions row — the DATABASE is what must refuse it, not Eloquent. */
function updateVersionRow(string $id, array $values): int
{
    return DB::table('form_versions')->where('id', $id)->update($values);
}

/** The anti-vacuity probe: prove the row is visible and updatable BEFORE asserting a refusal. */
function assertVersionRowIsReachable(string $id): void
{
    expect(updateVersionRow($id, ['updated_at' => now()]))->toBe(1);
}

// ── Rule 1: the frozen columns ───────────────────────────────────────────────────────────────────

it('freezes schema_snapshot on a published version', function (): void {
    $s = seedPublishedVersion();
    assertVersionRowIsReachable($s['published']->id);

    // json_encode by hand: the query builder will not encode a PHP array for a jsonb column.
    expect(fn () => updateVersionRow($s['published']->id, ['schema_snapshot' => json_encode(['sections' => []])]))
        ->toThrow(QueryException::class);
});

it('freezes checksum on a published version', function (): void {
    $s = seedPublishedVersion();
    assertVersionRowIsReachable($s['published']->id);

    expect(fn () => updateVersionRow($s['published']->id, ['checksum' => str_repeat('a', 64)]))
        ->toThrow(QueryException::class);
});

it('freezes version_number on a published version', function (): void {
    $s = seedPublishedVersion();
    assertVersionRowIsReachable($s['published']->id);

    expect(fn () => updateVersionRow($s['published']->id, ['version_number' => 99]))
        ->toThrow(QueryException::class);
});

it('freezes title and description on a published version', function (): void {
    $s = seedPublishedVersion();
    assertVersionRowIsReachable($s['published']->id);

    expect(fn () => updateVersionRow($s['published']->id, ['title' => 'Renamed', 'description' => 'x']))
        ->toThrow(QueryException::class);
});

it('freezes change_summary on a published version', function (): void {
    $s = seedPublishedVersion();
    assertVersionRowIsReachable($s['published']->id);

    expect(fn () => updateVersionRow($s['published']->id, ['change_summary' => 'rewritten history']))
        ->toThrow(QueryException::class);
});

it('freezes published_at on a published version', function (): void {
    $s = seedPublishedVersion();
    assertVersionRowIsReachable($s['published']->id);

    expect(fn () => updateVersionRow($s['published']->id, ['published_at' => now()->subYear()]))
        ->toThrow(QueryException::class);
});

it('freezes created_at on a published version', function (): void {
    $s = seedPublishedVersion();
    assertVersionRowIsReachable($s['published']->id);

    expect(fn () => updateVersionRow($s['published']->id, ['created_at' => now()->subYear()]))
        ->toThrow(QueryException::class);
});

it('freezes form_id, so a published version cannot be reparented', function (): void {
    $s = seedPublishedVersion();
    $other = app(FormService::class)->create($s['tenant'], $s['owner'], 'Another');
    assertVersionRowIsReachable($s['published']->id);

    expect(fn () => updateVersionRow($s['published']->id, ['form_id' => $other->id]))
        ->toThrow(QueryException::class);
});

it('freezes tenant_id, and the TRIGGER is what refuses it rather than the RLS policy', function (): void {
    $s = seedPublishedVersion();
    assertVersionRowIsReachable($s['published']->id);

    // A BEFORE ROW trigger runs before the RLS WITH CHECK, so the SQLSTATE proves which layer fired:
    // 23001 is this guard, 42501 would be the policy.
    try {
        updateVersionRow($s['published']->id, ['tenant_id' => Tenant::create(['name' => 'B', 'slug' => 'b'])->id]);
        $this->fail('The trigger did not refuse a tenant_id rewrite.');
    } catch (QueryException $e) {
        expect($e->getCode())->toBe('23001');
    }
});

it('names every offending column, the row and the status in the refusal', function (): void {
    $s = seedPublishedVersion();
    assertVersionRowIsReachable($s['published']->id);

    try {
        updateVersionRow($s['published']->id, ['checksum' => str_repeat('b', 64), 'title' => 'Renamed']);
        $this->fail('The trigger did not refuse a two-column rewrite.');
    } catch (QueryException $e) {
        expect($e->getMessage())
            ->toContain('checksum, title')          // string_agg, ordered by column name
            ->toContain($s['published']->id)
            ->toContain('status=published');
        expect($e->getCode())->toBe('23001');
    }
});

// ── Rule 2: the status transition ────────────────────────────────────────────────────────────────

it('refuses to resurrect a published version as a draft', function (): void {
    // THE core R5 case. The draft-child RLS shape keys on form_versions.status = 'draft', so this one
    // UPDATE would re-open every section, field and validation row beneath a live published version.
    $s = seedPublishedVersion();
    assertVersionRowIsReachable($s['published']->id);

    expect(fn () => updateVersionRow($s['published']->id, ['status' => FormVersionStatus::Draft->value]))
        ->toThrow(QueryException::class);
});

it('refuses to resurrect a superseded version as a draft', function (): void {
    $s = seedPublishedVersion();
    expect(updateVersionRow($s['published']->id, ['status' => 'superseded', 'superseded_at' => now()]))->toBe(1);

    expect(fn () => updateVersionRow($s['published']->id, ['status' => FormVersionStatus::Draft->value]))
        ->toThrow(QueryException::class);
});

it('refuses to reopen a superseded version as published, because superseded is terminal', function (): void {
    $s = seedPublishedVersion();
    expect(updateVersionRow($s['published']->id, ['status' => 'superseded', 'superseded_at' => now()]))->toBe(1);

    expect(fn () => updateVersionRow($s['published']->id, ['status' => FormVersionStatus::Published->value]))
        ->toThrow(QueryException::class);
});

// ── Rule 3: superseded_at ────────────────────────────────────────────────────────────────────────

it('refuses to stamp superseded_at without the status flip', function (): void {
    $s = seedPublishedVersion();
    assertVersionRowIsReachable($s['published']->id);

    expect(fn () => updateVersionRow($s['published']->id, ['superseded_at' => now()]))
        ->toThrow(QueryException::class);
});

it('refuses to re-date superseded_at once it is set', function (): void {
    $s = seedPublishedVersion();
    expect(updateVersionRow($s['published']->id, ['status' => 'superseded', 'superseded_at' => now()]))->toBe(1);

    expect(fn () => updateVersionRow($s['published']->id, ['superseded_at' => now()->addDay()]))
        ->toThrow(QueryException::class);
});

it('refuses to clear superseded_at back to null', function (): void {
    $s = seedPublishedVersion();
    expect(updateVersionRow($s['published']->id, ['status' => 'superseded', 'superseded_at' => now()]))->toBe(1);

    expect(fn () => updateVersionRow($s['published']->id, ['superseded_at' => null]))
        ->toThrow(QueryException::class);
});

// ── Rule 4: published_by ─────────────────────────────────────────────────────────────────────────

it('refuses to re-point published_by at another user', function (): void {
    $s = seedPublishedVersion();
    assertVersionRowIsReachable($s['published']->id);

    expect(fn () => updateVersionRow($s['published']->id, ['published_by' => $s['owner']->id]))
        ->toThrow(QueryException::class);
});

it('lets a publisher hard-delete null out published_by through the FK referential action', function (): void {
    // The single easiest way to ship this PR broken. `published_by` is ON DELETE SET NULL against `users`,
    // and PostgreSQL runs that action as an ordinary UPDATE through SPI — it bypasses RLS but NOT this
    // trigger. Freezing the column outright would make user hard-deletion, and GDPR erasure with it,
    // permanently impossible, raising a form-version error on a statement about users.
    $s = seedPublishedVersion();
    expect(DB::table('form_versions')->where('id', $s['published']->id)->value('published_by'))
        ->toBe($s['publisher']->id);

    $s['publisher']->forceDelete();

    $row = DB::table('form_versions')->where('id', $s['published']->id)->first();
    expect($row)->not->toBeNull();
    expect($row->published_by)->toBeNull();
    expect($row->status)->toBe('published');
});

// ── What must still be permitted ─────────────────────────────────────────────────────────────────

it('still lets the publish transition published→superseded succeed, raw', function (): void {
    // Mirrors FormVersionRlsTest's anti-trap. This is the ONE production UPDATE of a published row.
    $s = seedPublishedVersion();

    expect(updateVersionRow($s['published']->id, ['status' => 'superseded', 'superseded_at' => now()]))->toBe(1);
    expect(DB::table('form_versions')->where('id', $s['published']->id)->value('status'))->toBe('superseded');
});

it('leaves a draft version fully mutable, including published_at', function (): void {
    // The trigger gates on OLD.status, never on published_at — a draft row can legitimately carry one
    // (ConnectorFanOutTest does exactly that), and a published_at gate would break it.
    $s = seedPublishedVersion();
    $draft = FormVersion::query()->findOrFail($s['form']->refresh()->draft_version_id);

    expect(updateVersionRow($draft->id, [
        'schema_snapshot' => json_encode(['sections' => ['a']]),
        'checksum' => str_repeat('c', 64),
        'version_number' => 7,
        'title' => 'Freely renamed',
        'published_at' => now(),
    ]))->toBe(1);
});

it('lets a bare updated_at touch through on a published and on a superseded row', function (): void {
    // The four rules are INDEPENDENT, not chained: nothing frozen changed, so a maintenance touch passes
    // even on a terminal row. A guard that coupled them would be stricter than the invariant it encodes.
    $s = seedPublishedVersion();

    expect(updateVersionRow($s['published']->id, ['updated_at' => now()]))->toBe(1);
    expect(updateVersionRow($s['published']->id, ['status' => 'superseded', 'superseded_at' => now()]))->toBe(1);
    expect(updateVersionRow($s['published']->id, ['updated_at' => now()->addSecond()]))->toBe(1);
});

it('lets an idempotent rewrite of the same values through', function (): void {
    // PostgreSQL fires a BEFORE UPDATE row trigger even when nothing changes, so this pins that the guard
    // compares VALUES rather than asking which columns appeared in the SET list.
    $s = seedPublishedVersion();
    $row = DB::table('form_versions')->where('id', $s['published']->id)->first();

    expect(updateVersionRow($s['published']->id, [
        'title' => $row->title,
        'checksum' => $row->checksum,
        'schema_snapshot' => $row->schema_snapshot,
    ]))->toBe(1);
});

it('treats a key-reordered snapshot as unchanged but refuses a scale-only rewrite', function (): void {
    // Two halves of one decision. jsonb normalises key order at parse time, so re-encoding by Laravel's
    // `array` cast in a different order must NOT trip the guard — but jsonb equality is also numeric-aware
    // (`1` = `1.0`), and a scale-only rewrite would silently invalidate `checksum`, which is SHA-256 over
    // the canonical TEXT. Comparing ::text is what separates the two.
    $s = seedPublishedVersion();
    updateVersionRow($s['published']->id, ['status' => 'superseded', 'superseded_at' => now()]);

    $draft = FormVersion::query()->findOrFail($s['form']->refresh()->draft_version_id);
    updateVersionRow($draft->id, ['schema_snapshot' => json_encode(['a' => 1, 'b' => 2])]);
    updateVersionRow($draft->id, ['status' => FormVersionStatus::Published->value]);

    expect(updateVersionRow($draft->id, ['schema_snapshot' => json_encode(['b' => 2, 'a' => 1])]))->toBe(1);

    expect(fn () => updateVersionRow($draft->id, ['schema_snapshot' => '{"a": 1.0, "b": 2}']))
        ->toThrow(QueryException::class);
});

// ── Deny by default ──────────────────────────────────────────────────────────────────────────────

it('freezes a column that does not exist yet', function (): void {
    // THE test for deny-by-default. The guard diffs the whole row rather than a hand-written list, so a
    // column added by a future migration is frozen the moment it exists, with nobody having to remember.
    // PostgreSQL DDL is transactional, so RefreshDatabase rolls the probe column away.
    $s = seedPublishedVersion();
    DB::statement('ALTER TABLE form_versions ADD COLUMN h25_probe text');
    assertVersionRowIsReachable($s['published']->id);

    expect(fn () => updateVersionRow($s['published']->id, ['h25_probe' => 'written']))
        ->toThrow(QueryException::class);
});

// ── The service layer must be completely unaffected ──────────────────────────────────────────────

it('publishes a draft end to end through the real PublishService', function (): void {
    $s = seedPublishedVersion();
    $row = DB::table('form_versions')->where('id', $s['published']->id)->first();

    expect($row->status)->toBe('published');
    expect($row->checksum)->not->toBeNull();
    expect($row->schema_snapshot)->not->toBe('[]');
});

it('supersedes the prior version on a second publish through the real PublishService', function (): void {
    // The highest-value regression in this file: publish, edit the cloned draft, publish again. Step 6 of
    // PublishService writes the frozen columns while the row is STILL a draft (so the trigger is silent),
    // and step 7 performs the one transition the trigger permits.
    $s = seedPublishedVersion();
    $v1 = $s['published']->id;

    $form = $s['form']->refresh();
    addFormField(FormVersion::query()->findOrFail($form->draft_version_id), $s['owner'], 'age', FieldType::Integer, 1);
    app(PublishService::class)->publish($form, $s['publisher']);

    $form->refresh();
    $v1Row = DB::table('form_versions')->where('id', $v1)->first();
    $v2Row = DB::table('form_versions')->where('id', $form->current_published_version_id)->first();

    expect($v1Row->status)->toBe('superseded');
    expect($v1Row->superseded_at)->not->toBeNull();
    expect($v2Row->status)->toBe('published');
    expect($v2Row->version_number)->toBe(2);
});

it('archives a form, hard-deleting its draft, with the guard in place', function (): void {
    $s = seedPublishedVersion();
    $draftId = $s['form']->refresh()->draft_version_id;

    app(FormService::class)->archive($s['form']);

    expect(DB::table('form_versions')->where('id', $draftId)->exists())->toBeFalse();
    expect(DB::table('form_versions')->where('id', $s['published']->id)->exists())->toBeTrue();
});

it('lets a form hard-delete cascade straight through its published versions', function (): void {
    // The scope decision as an executable test (ADR-0013 / Risk R12): H25 adds NO delete trigger, because
    // one would fire on this FK cascade and turn tenant and form hard-deletion into an error. The
    // guarantee shipped is "a published version cannot be EDITED", never "cannot be destroyed".
    $s = seedPublishedVersion();

    $s['form']->forceDelete();

    expect(DB::table('form_versions')->where('form_id', $s['form']->id)->exists())->toBeFalse();
});

it('refuses a status outside the enum, where the trigger is deliberately silent', function (): void {
    // On a DRAFT row the trigger never fires, so the companion CHECK is the only thing standing between a
    // typo and a row frozen forever in a status no code can interpret.
    $s = seedPublishedVersion();
    $draftId = $s['form']->refresh()->draft_version_id;

    expect(fn () => updateVersionRow($draftId, ['status' => 'publishd']))
        ->toThrow(QueryException::class);
});

// ── Structural: the catalog must agree with the generator ────────────────────────────────────────

it('carries exactly one non-internal trigger on form_versions, BEFORE UPDATE FOR EACH ROW', function (): void {
    // `NOT tgisinternal` is essential — form_versions also carries the RI constraint triggers.
    // tgtype is a bitmask: ROW(1) | BEFORE(2) | UPDATE(16) = 19. Asserting 19 EXACTLY is what pins that
    // the INSERT(4) and DELETE(8) bits are clear, i.e. the scope decision, encoded in the catalog.
    $rows = DB::select(
        'SELECT tgname, tgtype::int AS tgtype, tgenabled FROM pg_trigger '
        ."WHERE tgrelid = 'form_versions'::regclass AND NOT tgisinternal"
    );

    expect($rows)->toHaveCount(1);
    expect($rows[0]->tgname)->toBe(PublishedVersionGuard::TRIGGER);
    expect((int) $rows[0]->tgtype)->toBe(19);
    // Origin mode, not ALWAYS: ENABLE ALWAYS would also fire during logical-replication apply and break a
    // replica-based restore legitimately replaying a supersede.
    expect($rows[0]->tgenabled)->toBe('O');
});

it('keeps the WHEN gate on OLD.status in the catalog definition', function (): void {
    $def = DB::selectOne(
        'SELECT pg_get_triggerdef(oid) AS def FROM pg_trigger WHERE tgname = ?',
        [PublishedVersionGuard::TRIGGER]
    )->def;

    // Fragments, never equality: ruleutils lowercases OLD and casts the varchar literal, so the deparse
    // reads `((old.status)::text IS DISTINCT FROM 'draft'::text)`.
    expect($def)->toContain('BEFORE UPDATE ON')
        ->toContain('form_versions')
        ->toContain('FOR EACH ROW')
        ->toContain("IS DISTINCT FROM 'draft'")
        ->toContain('EXECUTE FUNCTION '.PublishedVersionGuard::FUNCTION.'()')
        ->not->toContain('published_at');
});

it('runs the guard as SECURITY INVOKER plpgsql, needing no elevated rights', function (): void {
    $row = DB::selectOne(
        'SELECT l.lanname, p.prosecdef FROM pg_proc p JOIN pg_language l ON l.oid = p.prolang WHERE p.proname = ?',
        [PublishedVersionGuard::FUNCTION]
    );

    expect($row->lanname)->toBe('plpgsql');
    expect((bool) $row->prosecdef)->toBeFalse();
});
