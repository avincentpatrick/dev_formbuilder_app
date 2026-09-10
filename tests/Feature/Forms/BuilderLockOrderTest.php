<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Exceptions\Forms\BuilderConflictException;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormFieldValidation;
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

/*
|--------------------------------------------------------------------------
| The SET writeField() acquires, and its order within `form_fields` (M92).
|--------------------------------------------------------------------------
| M91's arms above prove the TABLE order: `form_fields` before `form_field_validations`, on both the
| clean and the dirty path. That closed the cycle where the two sides disagreed about which table to
| touch first, and left the one where they agree on the table and disagree on the ROW.
|
| ⛔ A CROSS-FIELD VALIDATION RULE IS THE DOOR. `related_field_key` resolves to a SIBLING field's id and
| is INSERTed as `related_form_field_id`, whose foreign-key check takes FOR KEY SHARE on that sibling's
| row — a row a `whereKey($field)` lock never touched. A publisher locks EVERY field of the draft in one
| statement, so it can hold the sibling and block on this field while this transaction holds this field
| and blocks on the sibling. 40P01 again, inside one table, and the same typed catch re-throws it as an
| unrendered 500.
|
| ⚠️ THE NARROWING IS ASSERTED, NOT JUST THE ORDERING, and it is the half a later reader will undo.
| The obvious fix — lock every field in the version, exactly as the publisher does — closes the cycle
| and silently serializes every concurrent field edit in a draft, which is the §3.4 reversal M91's own
| comment block spends a paragraph declining. The last arm pins that a payload naming no sibling still
| locks exactly one row.
*/

/** A draft carrying TWO fields, so one validation rule can name the other. */
function builderLockOrderPair(Tenant $tenant, User $user): Form
{
    $form = app(FormService::class)->create($tenant, $user, 'Survey');
    app(FormBuilderService::class)->addField($form->refresh(), $user, FieldType::ShortText, null);
    app(FormBuilderService::class)->addField($form->refresh(), $user, FieldType::ShortText, null);

    return $form->refresh();
}

/**
 * The two fields of a paired draft, ordered by id — which is the order both sides must lock them in.
 *
 * @return array{0: FormField, 1: FormField}
 */
function builderLockOrderFields(Form $form): array
{
    /** @var list<FormField> $fields */
    $fields = FormField::query()
        ->where('form_version_id', $form->draft_version_id)
        ->orderBy('id')
        ->get()
        ->all();

    expect($fields)->toHaveCount(2, 'the paired draft must hold exactly two fields for this to prove anything');

    return [$fields[0], $fields[1]];
}

/**
 * A writeField() payload for $field carrying one rule that names $related.
 *
 * @return array<string, mixed>
 */
function builderLockOrderCrossFieldPayload(FormField $field, FormField $related): array
{
    return [
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
            ['rule_type' => 'greater_than_field', 'operator' => null, 'rule_value' => null, 'expression' => null,
                'error_message' => 'Must exceed the other answer', 'related_field_key' => $related->key],
        ],
    ];
}

it('locks the sibling a cross-field rule names, ascending, before it writes any validation row', function (): void {
    $form = builderLockOrderPair($this->tenant, $this->user);
    [$lower, $higher] = builderLockOrderFields($form);

    // The HIGHER id is edited and names the LOWER one. That is the arrangement that fails without the
    // fix: an unordered publisher can reach `lower` first while this transaction holds `higher`.
    $payload = builderLockOrderCrossFieldPayload($higher, $lower);

    $log = builderLockOrderSqlDuring(function () use ($form, $higher, $payload): void {
        app(FormBuilderService::class)->updateField($form, $higher, $this->user, $payload, null);
    });

    $lock = builderLockOrderFirstIndex($log, 'form_fields', 'for update');
    $childWrite = builderLockOrderFirstIndex($log, 'delete from "form_field_validations"');

    // Floors first — see the null-coercion note on builderLockOrderFirstIndex().
    expect($lock)->not->toBeNull('no locking select on `form_fields` was issued at all');
    expect($childWrite)->not->toBeNull('no validation write was issued, so the ordering proves nothing');
    expect($lock)->toBeLessThan($childWrite, 'the validation rows were reached before the field rows were locked');

    $statement = $log[$lock];

    // ⛔ THE SIBLING IS IN THE LOCK SET, AND THE PLACEHOLDER LIST IS THE ONLY HONEST WITNESS TO IT.
    //    `whereIn` renders `in (?)` for one id and `in (?, ?)` for two, so `toContain('in (')` would
    //    pass against a lock set that had silently dropped the sibling — which is exactly the mutant
    //    control `m92-lock-set-narrowed` applies. Two placeholders, asserted.
    expect($statement)->toContain('in (?, ?)');

    // ⛔ AND IT IS ORDERED. Postgres locks rows as they are pulled from the plan, so without this clause
    //    the acquisition order is the plan's scan order and the publisher — which orders — can still
    //    interleave with it. `EXPLAIN` puts LockRows ABOVE Sort, which is what makes the clause bite.
    //    ⚠️ ONE needle per toContain() call: a second argument is read as a second NEEDLE, not a message.
    expect($statement)->toContain('order by "id" asc');

    // Non-vacuity: the rule really did resolve to the sibling rather than to a null.
    /** @var FormFieldValidation $stored */
    $stored = FormFieldValidation::query()->where('form_field_id', $higher->getKey())->firstOrFail();
    expect($stored->related_form_field_id)->toBe($lower->getKey());
});

it('locks the sibling on the CLEAN path too, which is the path that carries the defect', function (): void {
    // The partner case, and the one that matters: M91's cycle needed a clean payload, and so does this
    // one — Eloquent skips the UPDATE, so a lock that rode on save() would not be taken at all.
    $form = builderLockOrderPair($this->tenant, $this->user);
    [$lower, $higher] = builderLockOrderFields($form);
    $payload = builderLockOrderCrossFieldPayload($higher, $lower);

    app(FormBuilderService::class)->updateField($form, $higher, $this->user, $payload, null);

    /** @var FormField $stored */
    $stored = FormField::query()->whereKey($higher->getKey())->firstOrFail();

    $log = builderLockOrderSqlDuring(function () use ($form, $stored, $payload): void {
        app(FormBuilderService::class)->updateField($form, $stored, $this->user, $payload, null);
    });

    expect(builderLockOrderFirstIndex($log, 'update "form_fields"'))->toBeNull(
        'the resubmit was DIRTY, so this case is not exercising the clean path it names'
    );

    $lock = builderLockOrderFirstIndex($log, 'form_fields', 'for update');
    expect($lock)->not->toBeNull('the clean path took no field lock at all');
    expect($log[$lock])->toContain('in (?, ?)');
    expect($log[$lock])->toContain('order by "id" asc');
});

it('locks exactly ONE row when the payload names no sibling, which is §3.4 left standing', function (): void {
    // ⛔ THE NARROWING ARM. Locking every field in the version would satisfy both arms above and would
    //    serialize every concurrent field edit in a draft — declining to do that is what §3.4 says, and
    //    it is the property a later "simplification" to `where('form_version_id', …)` would destroy
    //    while keeping this file green everywhere else.
    $form = builderLockOrderPair($this->tenant, $this->user);
    [$lower, $higher] = builderLockOrderFields($form);

    $payload = builderLockOrderCrossFieldPayload($higher, $lower);
    $payload['validations'] = [
        ['rule_type' => 'min_length', 'operator' => null, 'rule_value' => '2',
            'expression' => null, 'error_message' => 'Too short', 'related_field_key' => null],
    ];

    $log = builderLockOrderSqlDuring(function () use ($form, $higher, $payload): void {
        app(FormBuilderService::class)->updateField($form, $higher, $this->user, $payload, null);
    });

    $lock = builderLockOrderFirstIndex($log, 'form_fields', 'for update');
    expect($lock)->not->toBeNull('no locking select on `form_fields` was issued at all');

    // ⛔ THE SET SIZE IS THE PROPERTY, AND IT IS READ FROM THE PLACEHOLDER LIST. `whereIn` renders
    //    `in (?)` for one id and `in (?, ?)` for two, so the PRESENCE of `in (` says nothing — it is
    //    there either way. The first draft of this arm asserted `not->toContain('in (')` and went red
    //    against a correct implementation: the cheap version of exactly the mistake this file exists to
    //    make expensive, and it is recorded rather than quietly corrected.
    expect($log[$lock])->toContain('in (?)');
    expect($log[$lock])->not->toContain('in (?, ?)');

    // And no whole-version sweep. That is the shape §3.4 declines, and it would satisfy both arms above.
    expect($log[$lock])->not->toContain('form_version_id');

    // The sibling resolution never ran either — no lookup by `key` was issued.
    expect(builderLockOrderFirstIndex($log, 'from "form_fields"', '"key" in ('))->toBeNull(
        'a sibling resolution ran for a payload that names no sibling'
    );
});
