<?php

declare(strict_types=1);

use App\Enums\ComparisonOperator;
use App\Enums\ConversionWarning;
use App\Enums\FieldType;
use App\Enums\FormVersionStatus;
use App\Enums\IndexedDataType;
use App\Enums\LogicOperator;
use App\Enums\RequiredMode;
use App\Enums\ValidationRuleType;
use App\Exceptions\Forms\BuilderConflictException;
use App\Exceptions\Forms\FormException;
use App\Exceptions\Forms\PublishValidationException;
use App\Models\Audit;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormFieldValidation;
use App\Models\FormSection;
use App\Models\FormVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\ExpressionValidationGate;
use App\Services\Forms\FormBuilderService;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Forms\StructuralValidationGate;
use App\Services\Xlsform\XlsformExporter;
use App\Support\Forms\ConversionPlan;
use App\Support\Forms\DefaultFieldRules;
use App\Support\Forms\FieldTypeConversion;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M121 — FormBuilderService::conversionPlans() and ::convertField() (R-495abf48, B5a first half).
|--------------------------------------------------------------------------
| A type conversion is an in-place UPDATE of one row: the id and the key survive, so every reference to
| the field — a `${key}` in an expression, a `related_form_field_id` on another field's rule — survives
| by construction. The delete-and-re-add workaround it replaces cascade-DELETES the other fields' rules
| (migration …_000206:25), which is worse than the row that filed this said.
|
| ⚠️ Helpers are prefixed `conversionService*`: Pest loads Unit and Feature into one process on a full
| run, and tests/Unit/Forms/FieldTypeConversionTest.php already owns `fieldConversion*`.
*/

beforeEach(function (): void {
    TenantContext::flush();
    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $this->user = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);
    $this->builder = app(FormBuilderService::class);
});

function conversionServiceForm(Tenant $tenant, User $user): Form
{
    return app(FormService::class)->create($tenant, $user, 'Survey')->refresh();
}

function conversionServiceDraft(Form $form): FormVersion
{
    return FormVersion::query()->whereKey($form->refresh()->draft_version_id)->firstOrFail();
}

/** The plan for one target, read through the public service method exactly as the future GET will. */
function conversionServicePlanTo(FormBuilderService $builder, Form $form, FormField $field, FieldType $to): ConversionPlan
{
    foreach ($builder->conversionPlans($form, $field) as $plan) {
        if ($plan->to === $to) {
            return $plan;
        }
    }

    throw new LogicException("no plan offered for {$to->value}");
}

/** Plan, then apply with the plan's own fingerprint and the row's current token — the happy path. */
function conversionServiceConvert(FormBuilderService $builder, Form $form, FormField $field, User $user, FieldType $to): FormField
{
    $plan = conversionServicePlanTo($builder, $form, $field, $to);

    return $builder->convertField($form, $field->refresh(), $user, $to, (string) FormBuilderService::rowVersion($field), $plan->fingerprint());
}

/**
 * Every row of the two tables a conversion writes, as plain arrays — for a byte-for-byte "nothing moved".
 *
 * @return array<string, mixed>
 */
function conversionServiceSnapshot(string $versionId): array
{
    return [
        'fields' => DB::table('form_fields')->where('form_version_id', $versionId)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        'validations' => DB::table('form_field_validations')->where('form_version_id', $versionId)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
    ];
}

/** @return list<string> every SQL statement issued while $work ran, in order */
function conversionServiceSqlDuring(Closure $work): array
{
    $log = [];
    DB::listen(function (QueryExecuted $q) use (&$log): void {
        $log[] = strtolower($q->sql);
    });

    $work();

    return $log;
}

/**
 * Index of the first statement containing every one of $needles, or null. Every caller asserts non-null
 * before comparing: PHP evaluates `null < 5` as true, so an ordering assertion over a missing statement
 * would pass over nothing.
 *
 * @param  list<string>  $log
 */
function conversionServiceFirstIndex(array $log, string ...$needles): ?int
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

/**
 * Every violation the structural gate collects for a draft, flattened to `{field, code, message}`.
 *
 * @return list<array{field: ?string, code: string, message: string}>
 */
function conversionServiceViolations(FormVersion $draft): array
{
    return array_merge(...array_map(
        static fn (PublishValidationException $e): array => $e->violations(),
        (new StructuralValidationGate)->collect($draft),
    ));
}

/**
 * A validation row on $owner, carrying the three columns the builder never round-trips — which is exactly
 * why an in-place conversion must keep them and a delete-and-reinsert would lose them.
 *
 * @param  array<string, mixed>  $extra
 */
function conversionServiceRule(FormField $owner, array $extra): FormFieldValidation
{
    return FormFieldValidation::create(array_merge([
        'form_version_id' => $owner->form_version_id,
        'form_field_id' => $owner->id,
    ], $extra));
}

// ── The write ────────────────────────────────────────────────────────────────────────────────────────

it('converts email to phone in place, keeping every kept row exactly as it was', function (): void {
    $form = conversionServiceForm($this->tenant, $this->user);
    $section = $this->builder->addSection($form);
    $sibling = $this->builder->addField($form, $this->user, FieldType::ShortText, $section->id);
    $field = $this->builder->addField($form, $this->user, FieldType::Email, $section->id); // seeds the email pattern at 0

    $length = conversionServiceRule($field, [
        'rule_type' => ValidationRuleType::MinLength, 'rule_value' => '2', 'sequence' => 1,
        'error_message_translations' => ['es' => 'Muy corto'], 'logic_group' => (string) Str::uuid(), 'logic_operator' => LogicOperator::And,
    ]);
    $condition = conversionServiceRule($field, [
        'rule_type' => ValidationRuleType::RequiredIf, 'operator' => ComparisonOperator::Eq, 'rule_value' => 'x',
        'related_form_field_id' => $sibling->id, 'sequence' => 2,
    ]);
    $before = $field->refresh()->only(['id', 'key', 'form_section_id', 'sequence', 'section_sequence', 'label', 'created_by']);

    $converted = conversionServiceConvert($this->builder, $form, $field, $this->user, FieldType::Phone);

    expect($converted->field_type)->toBe(FieldType::Phone)
        ->and($converted->only(array_keys($before)))->toBe($before);

    $rows = FormFieldValidation::query()->where('form_field_id', $field->id)->orderBy('sequence')->get();
    expect($rows)->toHaveCount(3)
        ->and($rows->pluck('rule_value')->all())->not->toContain(DefaultFieldRules::for(FieldType::Email)[0]['rule_value']);

    $keptLength = $rows->firstWhere('id', $length->id);
    $keptCondition = $rows->firstWhere('id', $condition->id);
    expect($keptLength)->not->toBeNull()
        ->and($keptLength->error_message_translations['es'] ?? null)->toBe('Muy corto')
        ->and($keptLength->logic_group)->toBe($length->logic_group)
        ->and($keptLength->logic_operator)->toBe(LogicOperator::And)
        ->and($keptLength->sequence)->toBe(1)
        ->and($keptCondition)->not->toBeNull()
        ->and($keptCondition->related_form_field_id)->toBe($sibling->id)
        ->and($keptCondition->sequence)->toBe(2);

    $added = $rows->firstWhere('sequence', 3);
    expect($added)->not->toBeNull()
        ->and($added->rule_value)->toBe(DefaultFieldRules::for(FieldType::Phone)[0]['rule_value']);
});

it('leaves every reference resolving after a lossless conversion', function (): void {
    $form = conversionServiceForm($this->tenant, $this->user);
    $draft = conversionServiceDraft($form);
    $name = addFormField($draft, $this->user, 'name', FieldType::ShortText, 0);
    addFormField($draft, $this->user, 'nick', FieldType::ShortText, 1, ['relevant_expression' => "\${name} != ''"]);
    $age = addFormField($draft, $this->user, 'age', FieldType::Integer, 2, ['is_required' => RequiredMode::Conditional]);
    $rule = conversionServiceRule($age, ['rule_type' => ValidationRuleType::RequiredIf, 'operator' => ComparisonOperator::Eq, 'rule_value' => 'x', 'related_form_field_id' => $name->id, 'sequence' => 0]);

    $gate = new StructuralValidationGate;
    expect($gate->collect($draft))->toBe([]); // premise: the draft is clean before the conversion

    conversionServiceConvert($this->builder, $form, $name, $this->user, FieldType::LongText);

    expect($rule->refresh()->related_form_field_id)->toBe($name->id)
        ->and($gate->collect($draft))->toBe([]);
    app(ExpressionValidationGate::class)->assertExpressionsResolve($draft); // throws if `${name}` stopped resolving
});

it('drops exactly the rows the table says, on the way to hidden', function (): void {
    $form = conversionServiceForm($this->tenant, $this->user);
    $draft = conversionServiceDraft($form);
    $other = addFormField($draft, $this->user, 'other', FieldType::ShortText, 0);
    $field = addFormField($draft, $this->user, 'code', FieldType::ShortText, 1, ['is_required' => RequiredMode::Conditional]);
    conversionServiceRule($field, ['rule_type' => ValidationRuleType::MinLength, 'rule_value' => '2', 'sequence' => 0]);
    conversionServiceRule($field, ['rule_type' => ValidationRuleType::Pattern, 'rule_value' => '[A-Z]+', 'sequence' => 1]);
    conversionServiceRule($field, ['rule_type' => ValidationRuleType::RequiredIf, 'operator' => ComparisonOperator::Eq, 'rule_value' => 'y', 'related_form_field_id' => $other->id, 'sequence' => 2]);
    conversionServiceRule($field, ['expression' => ". != ''", 'sequence' => 3]);

    $converted = conversionServiceConvert($this->builder, $form, $field, $this->user, FieldType::Hidden);

    $codes = array_column(conversionServiceViolations($draft), 'code');
    expect(FormFieldValidation::query()->where('form_field_id', $field->id)->count())->toBe(0)
        ->and($converted->is_required)->toBe(RequiredMode::Optional)
        ->and($codes)->not->toContain('hidden_field_required')
        ->and($codes)->not->toContain('hidden_field_has_validations');
});

it('keeps the governing rule, with its id, when a likert scale becomes a single choice', function (): void {
    $form = conversionServiceForm($this->tenant, $this->user);
    $draft = conversionServiceDraft($form);
    $other = addFormField($draft, $this->user, 'other', FieldType::ShortText, 0);
    $field = addFormField($draft, $this->user, 'rating', FieldType::LikertScale, 1, ['config' => ['options' => [['value' => '1', 'label' => 'Low'], ['value' => '2', 'label' => 'High']]]]);
    conversionServiceRule($field, ['rule_type' => ValidationRuleType::MinValue, 'rule_value' => '1', 'sequence' => 0]);
    conversionServiceRule($field, ['rule_type' => ValidationRuleType::MaxValue, 'rule_value' => '2', 'sequence' => 1]);
    $governing = conversionServiceRule($field, ['rule_type' => ValidationRuleType::RequiredIf, 'operator' => ComparisonOperator::Eq, 'rule_value' => 'y', 'related_form_field_id' => $other->id, 'sequence' => 2]);

    conversionServiceConvert($this->builder, $form, $field, $this->user, FieldType::SingleSelect);

    expect(FormFieldValidation::query()->where('form_field_id', $field->id)->pluck('id')->all())->toBe([$governing->id]);
});

// ── The refusals ─────────────────────────────────────────────────────────────────────────────────────

it('refuses an incompatible target and leaves every row byte-identical', function (): void {
    $form = conversionServiceForm($this->tenant, $this->user);
    $draft = conversionServiceDraft($form);
    $other = addFormField($draft, $this->user, 'other', FieldType::ShortText, 0);
    $field = addFormField($draft, $this->user, 'where', FieldType::Geopoint, 1);
    conversionServiceRule($field, ['rule_type' => ValidationRuleType::RequiredIf, 'operator' => ComparisonOperator::Eq, 'rule_value' => 'y', 'related_form_field_id' => $other->id, 'sequence' => 0]);
    $before = conversionServiceSnapshot($draft->id);
    $token = (string) FormBuilderService::rowVersion($field->refresh());

    expect(fn () => $this->builder->convertField($form, $field, $this->user, FieldType::ShortText, $token, str_repeat('0', 64)))
        ->toThrow(FormException::class, 'This question cannot be changed from '.FieldType::Geopoint->label().' to '.FieldType::ShortText->label().'.')
        ->and(fn () => $this->builder->convertField($form, $field, $this->user, FieldType::Geopoint, $token, str_repeat('0', 64)))
        ->toThrow(FormException::class)
        ->and(conversionServiceSnapshot($draft->id))->toBe($before);
});

it('refuses a field of a published version, on both the read and the write', function (): void {
    $form = conversionServiceForm($this->tenant, $this->user);
    $this->builder->addField($form, $this->user, FieldType::Email, null);
    app(PublishService::class)->publish($form->refresh(), $this->user);
    $published = FormVersion::query()->where('form_id', $form->id)->where('status', FormVersionStatus::Published)->firstOrFail();
    $field = FormField::query()->where('form_version_id', $published->id)->firstOrFail();
    $form->refresh();

    $message = 'That item belongs to a published version and can no longer be edited.';
    expect(fn () => $this->builder->conversionPlans($form, $field))->toThrow(FormException::class, $message)
        ->and(fn () => $this->builder->convertField($form, $field, $this->user, FieldType::Phone, (string) FormBuilderService::rowVersion($field), str_repeat('0', 64)))
        ->toThrow(FormException::class, $message);
});

it('refuses a stale version token before anything is written', function (): void {
    $form = conversionServiceForm($this->tenant, $this->user);
    $field = $this->builder->addField($form, $this->user, FieldType::Email, null);
    $plan = conversionServicePlanTo($this->builder, $form, $field, FieldType::Phone);
    $before = conversionServiceSnapshot($field->form_version_id);

    try {
        $this->builder->convertField($form, $field, $this->user, FieldType::Phone, '2000-01-01T00:00:00.000000+00:00', $plan->fingerprint());
        $this->fail('a stale token was accepted');
    } catch (BuilderConflictException $e) {
        expect($e->current->id)->toBe($field->id);
    }

    expect(conversionServiceSnapshot($field->form_version_id))->toBe($before);
});

it('refuses when a validations-only edit changed the plan after it was read', function (): void {
    $form = conversionServiceForm($this->tenant, $this->user);
    $field = $this->builder->addField($form, $this->user, FieldType::ShortText, null);
    $token = (string) FormBuilderService::rowVersion($field->refresh());
    $read = conversionServicePlanTo($this->builder, $form, $field, FieldType::LongText)->fingerprint();

    // The edit a second tab makes: a rule row, and nothing on the field row itself.
    conversionServiceRule($field, ['rule_type' => ValidationRuleType::MinLength, 'rule_value' => '2', 'sequence' => 0]);
    expect(FormBuilderService::rowVersion($field->refresh()))->toBe($token); // premise: the token cannot see it

    expect(fn () => $this->builder->convertField($form, $field, $this->user, FieldType::LongText, $token, $read))
        ->toThrow(BuilderConflictException::class)
        ->and($field->refresh()->field_type)->toBe(FieldType::ShortText);

    // Positive control: re-reading the plan is exactly the remedy.
    $reread = conversionServicePlanTo($this->builder, $form, $field, FieldType::LongText)->fingerprint();
    expect($reread)->not->toBe($read)
        ->and($this->builder->convertField($form, $field, $this->user, FieldType::LongText, $token, $reread)->field_type)->toBe(FieldType::LongText);
});

it('accepts no fingerprint for a plan that needs no confirmation, and refuses it for one that does', function (): void {
    $form = conversionServiceForm($this->tenant, $this->user);
    $lossless = $this->builder->addField($form, $this->user, FieldType::ShortText, null);
    $confirming = $this->builder->addField($form, $this->user, FieldType::ShortText, null);

    $converted = $this->builder->convertField($form, $lossless, $this->user, FieldType::LongText, (string) FormBuilderService::rowVersion($lossless->refresh()), null);

    expect($converted->field_type)->toBe(FieldType::LongText)
        // short text -> email adds the email check, so the author has to have seen it.
        ->and(fn () => $this->builder->convertField($form, $confirming, $this->user, FieldType::Email, (string) FormBuilderService::rowVersion($confirming->refresh()), null))
        ->toThrow(BuilderConflictException::class)
        ->and($confirming->refresh()->field_type)->toBe(FieldType::ShortText);
});

// ── How it writes ────────────────────────────────────────────────────────────────────────────────────

it('locks the one field row before touching any validation row, and never the forms row', function (): void {
    $form = conversionServiceForm($this->tenant, $this->user);
    $field = $this->builder->addField($form, $this->user, FieldType::Email, null);
    $plan = conversionServicePlanTo($this->builder, $form, $field, FieldType::Phone);
    $token = (string) FormBuilderService::rowVersion($field->refresh());

    $log = conversionServiceSqlDuring(fn () => $this->builder->convertField($form, $field, $this->user, FieldType::Phone, $token, $plan->fingerprint()));

    $lock = conversionServiceFirstIndex($log, '"form_fields"', 'for update');
    $delete = conversionServiceFirstIndex($log, 'delete from "form_field_validations"');
    $insert = conversionServiceFirstIndex($log, 'insert into "form_field_validations"');
    $update = conversionServiceFirstIndex($log, 'update "form_fields"');

    expect($lock)->not->toBeNull()
        ->and($delete)->not->toBeNull()
        ->and($insert)->not->toBeNull()
        ->and($update)->not->toBeNull();

    // ⚠️ Not a `?` count: the tenant global scope adds a second placeholder to the same statement.
    expect($log[$lock])->toContain('"form_fields"."id" = ?')
        ->and($log[$lock])->not->toContain(' in (')
        ->and($lock)->toBeLessThan($update)
        ->and($update)->toBeLessThan($delete)
        ->and($lock)->toBeLessThan($insert)
        ->and(array_filter($log, static fn (string $sql): bool => str_contains($sql, 'update "form_fields"')))->toHaveCount(1)
        ->and(array_filter($log, static fn (string $sql): bool => str_contains($sql, '"forms"') && str_contains($sql, 'for update')))->toBe([]);
});

it('writes no audit row, because draft field edits are not audited', function (): void {
    // A PIN, not a gate: FormField is not Auditable, so no mutation can redden this. It records the
    // decision (docs/audit-compliance-logging-spec.md excludes draft form_fields edits) at the code.
    $form = conversionServiceForm($this->tenant, $this->user);
    $field = $this->builder->addField($form, $this->user, FieldType::Email, null);
    $before = Audit::query()->count();

    conversionServiceConvert($this->builder, $form, $field, $this->user, FieldType::Phone);

    expect(Audit::query()->count())->toBe($before);
});

// ── What the rest of the product makes of it ─────────────────────────────────────────────────────────

it('records the type change in the next published version', function (): void {
    $form = conversionServiceForm($this->tenant, $this->user);
    addFormField(conversionServiceDraft($form), $this->user, 'age', FieldType::Integer, 0);
    app(PublishService::class)->publish($form->refresh(), $this->user);

    $age = FormField::query()->where('form_version_id', $form->refresh()->draft_version_id)->where('key', 'age')->firstOrFail();
    conversionServiceConvert($this->builder, $form, $age, $this->user, FieldType::Decimal);
    $v2 = app(PublishService::class)->publish($form->refresh(), $this->user);

    expect($v2->change_summary)->toContain('Changed field age: type integer → decimal');
});

it('exports a dropdown converted to single choice as a plain select_one, choices intact', function (): void {
    $form = conversionServiceForm($this->tenant, $this->user);
    $draft = conversionServiceDraft($form);
    // ⚠️ The FIELD-level translation is load-bearing: the exporter collects its languages from labels and
    // hints, never from option translations, so without it no `label::es` column exists at all. That is a
    // separate defect (filed by M121) — this fixture works around it deliberately.
    $field = addFormField($draft, $this->user, 'colour', FieldType::Dropdown, 0, [
        'label_translations' => ['es' => 'Color'],
        'config' => ['options' => [['value' => 'r', 'label' => 'Red', 'label_translations' => ['es' => 'Rojo']]]],
    ]);

    conversionServiceConvert($this->builder, $form, $field, $this->user, FieldType::SingleSelect);
    $sheets = app(XlsformExporter::class)->build($form->refresh(), $draft);

    $survey = collect($sheets['survey']['rows'])->firstWhere('name', 'colour');
    $choice = collect($sheets['choices']['rows'])->first(fn (array $r): bool => ($r['name'] ?? null) === 'r');
    expect($survey)->not->toBeNull()
        ->and($survey['type'])->toStartWith('select_one ')
        ->and($survey['appearance'] ?? '')->not->toBe('minimal')
        ->and($choice)->not->toBeNull()
        ->and($choice['label::es'] ?? null)->toBe('Rojo');
});

it('plans and applies every target of a short text field, reading the plan back through two queries', function (): void {
    $form = conversionServiceForm($this->tenant, $this->user);

    foreach (FieldTypeConversion::targetsFor(FieldType::ShortText) as $to) {
        $field = $this->builder->addField($form, $this->user, FieldType::ShortText, null);
        $plans = $this->builder->conversionPlans($form, $field);

        expect(array_map(static fn (ConversionPlan $p): FieldType => $p->to, $plans))->toBe(FieldTypeConversion::targetsFor(FieldType::ShortText));

        $converted = conversionServiceConvert($this->builder, $form, $field, $this->user, $to);
        expect($converted->field_type)->toBe($to);
    }
});

it('offers a page break nothing, and refuses to convert one', function (): void {
    $form = conversionServiceForm($this->tenant, $this->user);
    $break = addFormField(conversionServiceDraft($form), $this->user, 'break', FieldType::PageBreak, 0);

    expect($this->builder->conversionPlans($form, $break))->toBe([])
        ->and(fn () => $this->builder->convertField($form, $break, $this->user, FieldType::Note, (string) FormBuilderService::rowVersion($break->refresh()), null))
        ->toThrow(FormException::class);
});

it('gives a note the target default config, and warns of setup exactly when publish would refuse', function (FieldType $to, array $expected): void {
    $form = conversionServiceForm($this->tenant, $this->user);
    $draft = conversionServiceDraft($form);
    $note = $this->builder->addField($form, $this->user, FieldType::Note, null);
    $plan = conversionServicePlanTo($this->builder, $form, $note, $to);

    $converted = conversionServiceConvert($this->builder, $form, $note, $this->user, $to);
    $keys = array_column(conversionServiceViolations($draft), 'field');

    // toEqual, not toBe: the config came back out of jsonb, which does not keep key order (M111).
    expect($converted->config)->toEqual($expected)
        ->and(in_array(ConversionWarning::NeedsSetup, $plan->warnings, true))
        ->toBe(in_array($converted->key, $keys, true), "{$to->value}: the warning and the publish gate disagree");
})->with([
    'single choice' => [FieldType::SingleSelect, ['options' => []]],
    'cascading' => [FieldType::CascadingSelect, ['levels' => [], 'options' => []]],
    'matrix' => [FieldType::Matrix, ['rows' => [], 'columns' => [], 'cells' => []]],
    'likert matrix' => [FieldType::LikertMatrix, ['rows' => [], 'columns' => []]],
    'short text' => [FieldType::ShortText, []],
    'gps point' => [FieldType::Geopoint, []],
    'file upload' => [FieldType::FileUpload, []],
]);

it('lets a note become calculated with a warning, because publish refuses it until it has a formula', function (): void {
    $form = conversionServiceForm($this->tenant, $this->user);
    $draft = conversionServiceDraft($form);
    $note = $this->builder->addField($form, $this->user, FieldType::Note, null);
    $plan = conversionServicePlanTo($this->builder, $form, $note, FieldType::Calculated);

    conversionServiceConvert($this->builder, $form, $note, $this->user, FieldType::Calculated);

    // The premise of the warning, pinned: the structural gate is silent, the expression gate refuses (M122).
    expect($plan->warnings)->toBe([ConversionWarning::CalculatedNeedsFormula])
        ->and((new StructuralValidationGate)->collect($draft))->toBe([]);
    expect(fn () => app(ExpressionValidationGate::class)->assertExpressionsResolve($draft))->toThrow(PublishValidationException::class, $note->key);
});

it('keeps the id and key across a round trip through note, clearing what a note cannot hold', function (): void {
    $form = conversionServiceForm($this->tenant, $this->user);
    $field = $this->builder->addField($form, $this->user, FieldType::Email, null);
    $field->forceFill(['is_queryable' => true, 'indexed_data_type' => IndexedDataType::Text, 'default_value' => 'a@b.test'])->save();

    $parked = conversionServiceConvert($this->builder, $form, $field, $this->user, FieldType::Note);
    expect($parked->id)->toBe($field->id)
        ->and($parked->key)->toBe($field->key)
        ->and($parked->is_queryable)->toBeFalse()
        ->and($parked->indexed_data_type)->toBeNull()
        ->and($parked->default_value)->toBeNull()
        ->and(FormFieldValidation::query()->where('form_field_id', $field->id)->count())->toBe(0);

    $back = conversionServiceConvert($this->builder, $form, $parked, $this->user, FieldType::Email);
    expect($back->id)->toBe($field->id)
        ->and($back->key)->toBe($field->key)
        ->and(FormFieldValidation::query()->where('form_field_id', $field->id)->pluck('rule_value')->all())
        ->toBe([DefaultFieldRules::for(FieldType::Email)[0]['rule_value']]);
});

it('leaves another field rule that names the converted field untouched', function (): void {
    // The census of cross-field consequences is M122's; what is pinned here is that the engine itself
    // never cascades, rewrites or deletes a row on a DIFFERENT field.
    $form = conversionServiceForm($this->tenant, $this->user);
    $draft = conversionServiceDraft($form);
    $target = addFormField($draft, $this->user, 'consent', FieldType::SingleSelect, 0, ['config' => ['options' => [['value' => 'y', 'label' => 'Yes']]]]);
    $other = addFormField($draft, $this->user, 'reason', FieldType::ShortText, 1, ['is_required' => RequiredMode::Conditional]);
    $rule = conversionServiceRule($other, ['rule_type' => ValidationRuleType::RequiredIf, 'operator' => ComparisonOperator::Eq, 'rule_value' => 'y', 'related_form_field_id' => $target->id, 'sequence' => 0]);
    $before = DB::table('form_field_validations')->where('id', $rule->id)->first();

    conversionServiceConvert($this->builder, $form, $target, $this->user, FieldType::Note);

    expect((array) DB::table('form_field_validations')->where('id', $rule->id)->first())->toBe((array) $before);
});

it('warns when the field sits in a repeatable section the target cannot be published in', function (): void {
    $form = conversionServiceForm($this->tenant, $this->user);
    $section = $this->builder->addSection($form);
    FormSection::query()->whereKey($section->id)->update(['is_repeatable' => true]);
    $field = $this->builder->addField($form, $this->user, FieldType::ShortText, $section->id);

    expect(conversionServicePlanTo($this->builder, $form, $field, FieldType::Hidden)->warnings)
        ->toContain(ConversionWarning::RepeatableSectionRefusesType);
});
