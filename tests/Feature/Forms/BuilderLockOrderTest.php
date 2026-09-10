<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Exceptions\Forms\BuilderConflictException;
use App\Models\Form;
use App\Models\FormField;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormBuilderService;
use App\Services\Forms\FormService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The ORDER in which writeField() acquires its rows (M91).
|--------------------------------------------------------------------------
| updateField() touches the field row, then the validation rows. A publisher takes `form_fields` and
| then `form_field_validations`, each in one statement (PublishService). The two agree — and the
| agreement is ACCIDENTAL, because it rests on Eloquent issuing an UPDATE at all.
|
| ⛔ THE CLEAN PAYLOAD IS THE WHOLE DEFECT. Model::save() checks isDirty() BEFORE it issues anything, so
| resubmitting a payload identical to the stored row skips the UPDATE entirely. The builder's first lock
| then lands on `form_field_validations` while it still wants `form_fields` for the INSERT's foreign-key
| check (FOR KEY SHARE) — the reverse of the publisher's order, which is the definition of a deadlock.
| Postgres answers 40P01, and updateField()'s typed catch re-throws anything that is not 23503, so the
| loser reaches the builder as the framework's generic JSON 500: the same unrendered answer M89 fixed
| for the constraint case, arriving through a different door.
|
| ⛔ WHY THE CLEAN PATH IS REACHABLE AND NOT A CURIOSITY. The optimistic-concurrency token is the only
| thing standing in front of a resubmit, and it compares the row's version to the one the client holds —
| which is unchanged precisely when nothing changed. A double-click, a retried fetch, or a save with no
| edit all reach it.
|
| ⚠️ WHAT THIS FILE DOES NOT DO IS FORCE A DEADLOCK. Two live connections would be needed, and under
| RefreshDatabase a raised Postgres error aborts the wrapping transaction — the shape SsoAuthRequestService
| records for its own untested trim. What is provable, deterministically and on one connection, is the
| ORDER, which is the property the fix changes. The negative arm below is what keeps that honest.
|
| ⚠️ Helpers are prefixed `builderLockOrder*` deliberately: Pest loads every file in a directory into one
| process, so a same-named file-scope helper is a fatal redeclaration. `publishLocking*` is already taken
| in this directory by PublishLockingTest.
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
function builderLockOrderSqlDuring(Closure $work): array
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
 * ⛔ THE NULL IS WHY EVERY CALLER BELOW ASSERTS non-null BEFORE IT COMPARES. PHP evaluates `null < 5`
 * as true, so an ordering assertion written straight over this return value passes when the statement
 * it is looking for was never issued at all — a gate with no floor, green over an empty set, which is
 * the failure this repository has now measured more than once.
 *
 * @param  list<string>  $log
 */
function builderLockOrderFirstIndex(array $log, string ...$needles): ?int
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

/** A draft form carrying one field and one validation rule, ready to be edited. */
function builderLockOrderDraft(Tenant $tenant, User $user): Form
{
    $form = app(FormService::class)->create($tenant, $user, 'Survey');
    app(FormBuilderService::class)->addField($form->refresh(), $user, FieldType::ShortText, null);

    return $form->refresh();
}

/**
 * The exact stored shape of the form's single field, as a writeField() payload.
 *
 * Built by READING the row rather than by restating it, so the "clean" resubmit below is genuinely
 * clean: a hand-written payload that differed in one cast would make the model dirty and quietly turn
 * the clean-path case into a second copy of the dirty-path one.
 *
 * @return array{0: FormField, 1: array<string, mixed>}
 */
function builderLockOrderPayload(Form $form): array
{
    /** @var FormField $field */
    $field = FormField::query()->where('form_version_id', $form->draft_version_id)->firstOrFail();

    return [$field, [
        'key' => $field->key,
        'label' => $field->label,
        'hint' => $field->hint,
        'placeholder' => $field->placeholder,
        'is_required' => $field->is_required->value,
        'relevant_expression' => $field->relevant_expression,
        'appearance' => $field->appearance,
        'config' => $field->config ?? [],
        'default_value' => $field->default_value,
        'is_pii' => $field->is_pii,
        'is_sensitive' => $field->is_sensitive,
        'is_queryable' => $field->is_queryable,
        'validations' => [
            ['rule_type' => 'min_length', 'operator' => null, 'rule_value' => '2',
                'expression' => null, 'error_message' => 'Too short', 'related_field_key' => null],
        ],
    ]];
}

it('takes the field row before any validation row even when the payload is clean', function (): void {
    $form = builderLockOrderDraft($this->tenant, $this->user);
    [$field, $payload] = builderLockOrderPayload($form);

    // Write once so the validation row exists and the model matches the payload exactly.
    app(FormBuilderService::class)->updateField($form, $field, $this->user, $payload, null);

    /** @var FormField $stored */
    $stored = FormField::query()->whereKey($field->getKey())->firstOrFail();

    $log = builderLockOrderSqlDuring(function () use ($form, $stored, $payload): void {
        app(FormBuilderService::class)->updateField($form, $stored, $this->user, $payload, null);
    });

    // ⛔ THE PREMISE, ASSERTED RATHER THAN ASSUMED. If this resubmit issued an UPDATE, the payload was
    //    not clean and everything below would be measuring the dirty path under a clean path's name.
    expect(builderLockOrderFirstIndex($log, 'update "form_fields"'))->toBeNull(
        'the resubmit was DIRTY, so this case is not exercising the defect it names. '
        .'builderLockOrderPayload() must reproduce the stored row exactly.'
    );

    $lock = builderLockOrderFirstIndex($log, 'form_fields', 'for update');
    $childWrite = builderLockOrderFirstIndex($log, 'delete from "form_field_validations"');

    // Both floors first. Either one missing makes the comparison below vacuous, and the null-coercion
    // note on builderLockOrderFirstIndex() is why that would not have shown up as a failure.
    expect($lock)->not->toBeNull(
        'no locking select on `form_fields` was issued at all — writeField() no longer takes the field '
        .'row before it writes children, and the clean-payload deadlock against a publisher is open again.'
    );
    expect($childWrite)->not->toBeNull('no validation write was issued, so the ordering proves nothing');

    expect($lock)->toBeLessThan(
        $childWrite,
        'writeField() touched `form_field_validations` before it locked `form_fields`. A publisher takes '
        .'those two tables in the opposite order, so this is the 40P01 cycle — and updateField()`s typed '
        .'catch re-throws anything that is not 23503, so the loser lands as an unrendered JSON 500.'
    );
});

it('takes them in the same order when the payload is dirty, which is the case that already worked', function (): void {
    // The partner case. A fix that somehow ordered only the clean path would leave the common path
    // unproved, and a regression that reordered only the dirty path would be invisible above.
    $form = builderLockOrderDraft($this->tenant, $this->user);
    [$field, $payload] = builderLockOrderPayload($form);

    app(FormBuilderService::class)->updateField($form, $field, $this->user, $payload, null);

    /** @var FormField $stored */
    $stored = FormField::query()->whereKey($field->getKey())->firstOrFail();
    $payload['label'] = 'A different label';

    $log = builderLockOrderSqlDuring(function () use ($form, $stored, $payload): void {
        app(FormBuilderService::class)->updateField($form, $stored, $this->user, $payload, null);
    });

    // This one DOES issue the update — the discriminator against the case above.
    expect(builderLockOrderFirstIndex($log, 'update "form_fields"'))->not->toBeNull(
        'the dirty resubmit issued no UPDATE, so this case is a duplicate of the clean one'
    );

    $lock = builderLockOrderFirstIndex($log, 'form_fields', 'for update');
    $childWrite = builderLockOrderFirstIndex($log, 'delete from "form_field_validations"');

    expect($lock)->not->toBeNull('no locking select on `form_fields` was issued on the dirty path either');
    expect($childWrite)->not->toBeNull('no validation write was issued, so the ordering proves nothing');
    expect($lock)->toBeLessThan($childWrite, 'the dirty path reached the validation rows first');
});

it('still writes what it was asked to write, and still refuses a stale token', function (): void {
    // ⛔ THE NON-VACUITY PARTNER FOR BOTH ORDERING ARMS. A writeField() that threw before touching
    //    anything, or that lost the payload, satisfies every assertion above — the log would simply be
    //    shorter. These assert the method still does its job with the lock in front of it.
    $form = builderLockOrderDraft($this->tenant, $this->user);
    [$field, $payload] = builderLockOrderPayload($form);
    $payload['label'] = 'Household size';

    $written = app(FormBuilderService::class)->updateField($form, $field, $this->user, $payload, null);

    expect($written->label)->toBe('Household size');
    expect($written->validations()->count())->toBe(1);

    // And the optimistic-concurrency contract is untouched by the new lock: a stale token still loses
    // before the transaction opens.
    expect(fn () => app(FormBuilderService::class)->updateField(
        $form,
        $written->refresh(),
        $this->user,
        $payload,
        'a-token-from-some-earlier-read',
    ))->toThrow(BuilderConflictException::class);
    // ⚠️ The CONCRETE class, not `Throwable`: Pest resolves a string argument to toThrow() with
    //    class_exists(), which is FALSE for an interface, so `Throwable::class` is compared as a
    //    MESSAGE and the arm fails against a correct refusal. Measured here rather than inherited.
});
