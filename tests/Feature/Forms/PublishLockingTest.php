<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Models\Form;
use App\Models\FormField;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormBuilderService;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The SHAPE of publish()'s locking (M89, docs/feature-backlog.md:8713).
|--------------------------------------------------------------------------
| The `forms` FOR UPDATE at the top of the publish transaction does NOT reach the child rows the
| transaction freezes, and §3.4 declines to serialize field-level edits, so a collaborator's
| updateField() runs concurrently and lock-free. Under READ COMMITTED its RLS `EXISTS (... status =
| 'draft')` is evaluated per statement against the COMMITTED snapshot, which says `draft` at every
| instant before this transaction commits — so RLS refuses nothing, and the published version can end
| up with a frozen schema_snapshot and checksum that predate a row belonging to it.
|
| RefreshDatabase wraps each test in one uncommitted transaction on one connection, so genuine
| contention is unobservable — no test in this repository opens a second connection, and the one file
| that claims a sibling which does (ScopeNodeMoveLockingTest, citing ScopeNodeConcurrentMoveTest)
| names a file that does not exist. What IS observable, and what the concurrency argument actually
| rests on, is the sequence of statements publish() issues. These assertions are deterministic and
| cannot flake.
|
| ⛔ THE NEGATIVE ARM IS THE LOAD-BEARING ONE. Postgres applies the UPDATE policy's USING expression to
| a locking SELECT as a FILTER, not an error. A lockForUpdate() moved into SchemaTreeCloner would run
| after the status flip, see its own uncommitted `published`, match zero rows and clone an EMPTY tree
| with no error — and every other publish test in this repository would still pass. Only an assertion
| that no lock is taken after the flip can see it.
|
| Helpers are prefixed `publishLocking*`: Pest loads the whole suite into one process, and a file-scope
| helper sharing a name with ScopeNodeMoveLockingTest's `sqlDuring()` is a fatal redeclaration.
*/

beforeEach(function (): void {
    TenantContext::flush();
    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $this->user = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);
});

/**
 * @return list<string> every SQL statement issued while $work ran, in order
 */
function publishLockingSqlDuring(Closure $work): array
{
    $log = [];
    DB::listen(function (QueryExecuted $q) use (&$log): void {
        $log[] = strtolower($q->sql);
    });

    $work();

    return $log;
}

/**
 * Index of the first statement containing every one of $needles, or null.
 *
 * @param  list<string>  $log
 */
function publishLockingFirstIndex(array $log, string ...$needles): ?int
{
    foreach ($log as $i => $sql) {
        $hit = true;
        foreach ($needles as $needle) {
            $hit = $hit && str_contains($sql, $needle);
        }

        if ($hit) {
            return $i;
        }
    }

    return null;
}

/** A draft form carrying one section and one field, ready to publish. */
function publishLockingDraft(Tenant $tenant, User $user): Form
{
    $form = app(FormService::class)->create($tenant, $user, 'Survey');
    app(FormBuilderService::class)->addField($form->refresh(), $user, FieldType::ShortText, null);

    return $form->refresh();
}

it('locks all three child tables inside the publish transaction', function (): void {
    $form = publishLockingDraft($this->tenant, $this->user);

    $log = publishLockingSqlDuring(fn () => app(PublishService::class)->publish($form, $this->user));

    // Each of the three tables the snapshot freezes and the cloner walks. Before M89 all three were
    // read with a plain SELECT, so a concurrent editor could move any of them mid-transaction.
    foreach (['form_sections', 'form_fields', 'form_field_validations'] as $table) {
        expect(publishLockingFirstIndex($log, $table, 'for update'))
            ->not->toBeNull("publish() must lock {$table} for the transaction's duration");
    }
});

it('locks the form first, then the children, then reads them', function (): void {
    $form = publishLockingDraft($this->tenant, $this->user);

    $log = publishLockingSqlDuring(fn () => app(PublishService::class)->publish($form, $this->user));

    $formLock = publishLockingFirstIndex($log, 'from "forms"', 'for update');
    $childLock = publishLockingFirstIndex($log, 'form_fields', 'for update');
    $firstChildRead = publishLockingFirstIndex($log, 'from "form_fields"');

    // The order is the whole property. A lock taken after the validation gates would leave them reading
    // rows that can still move — publishing a snapshot that never passed the gate it was meant to pass.
    expect($formLock)->not->toBeNull()
        ->and($childLock)->not->toBeNull()
        ->and($formLock)->toBeLessThan($childLock)
        ->and($childLock)->toBe($firstChildRead);
});

it('takes NO lock after the status flip, because a lock there would clone an empty tree', function (): void {
    $form = publishLockingDraft($this->tenant, $this->user);

    $log = publishLockingSqlDuring(fn () => app(PublishService::class)->publish($form, $this->user));

    $flip = publishLockingFirstIndex($log, 'update "form_versions"');
    expect($flip)->not->toBeNull('the publish must flip the version status');

    // ⛔ Past this point the transaction sees its own uncommitted `published`, so the draft_update RLS
    // policy's EXISTS matches nothing and a locking read returns ZERO ROWS — silently. This arm is the
    // only thing in the repository that would notice.
    $after = array_slice($log, $flip + 1);
    expect(array_values(array_filter($after, fn (string $s): bool => str_contains($s, 'for update'))))
        ->toBe([], 'a locking read after the status flip matches zero rows and clones an empty tree');
});

it('locks each child table exactly once, not once per walk', function (): void {
    $form = publishLockingDraft($this->tenant, $this->user);

    $log = publishLockingSqlDuring(fn () => app(PublishService::class)->publish($form, $this->user));

    // Row locks are held to the end of the transaction, so one acquisition covers the gates, the
    // classifier, the serializer and the cloner. A refactor that re-locks per walk is a defect rather
    // than belt-and-braces: it is how the lock migrates into the cloner one step at a time.
    expect(count(array_filter($log, fn (string $s): bool => str_contains($s, 'for update'))))->toBe(4);
});

it('freezes a snapshot that describes the rows it locked (behavioural control)', function (): void {
    $form = publishLockingDraft($this->tenant, $this->user);
    $liveKeys = FormField::query()->where('form_version_id', $form->draft_version_id)
        ->orderBy('key')->pluck('key')->all();

    $published = app(PublishService::class)->publish($form, $this->user);

    // Not a race — the property the locking exists to preserve, asserted the only way one connection
    // can. If the lock were moved into SchemaTreeCloner the snapshot would still be right and the new
    // DRAFT would be empty, which is why the negative arm above exists as well as this one.
    expect(array_column($published->schema_snapshot['fields'], 'key'))->toBe($liveKeys)
        ->and($liveKeys)->not->toBeEmpty();
});

it('clones the published tree forward into the new draft (the cloner is not silently empty)', function (): void {
    $form = publishLockingDraft($this->tenant, $this->user);

    $before = FormField::query()->where('form_version_id', $form->draft_version_id)->count();

    app(PublishService::class)->publish($form, $this->user);
    $form->refresh();

    // ⛔ THE ARM THAT FAILS IF THE LOCK EVER MIGRATES INTO SchemaTreeCloner. A locking read there matches
    // zero rows against the transaction's own uncommitted `published`, so the new draft is cloned EMPTY
    // — no error, no failing publish, and the editor simply finds their form blank afterwards. Compared
    // against the pre-publish count rather than a literal, so the fixture can grow without a false red.
    expect($before)->toBeGreaterThan(0)
        ->and(FormField::query()->where('form_version_id', $form->draft_version_id)->count())->toBe($before);
});
