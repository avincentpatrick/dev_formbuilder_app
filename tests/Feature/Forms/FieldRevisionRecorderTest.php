<?php

declare(strict_types=1);

use App\Enums\FieldRevisionKind;
use App\Enums\FieldType;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormFieldRevision;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\BuilderPresenter;
use App\Services\Forms\FieldRevisionRecorder;
use App\Services\Forms\FormBuilderService;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M188 (`R-3894369d`, `D167`, `D168`) — a question's history is written by the builder's own saves
|--------------------------------------------------------------------------
| Through `FormBuilderService`, the way the builder's routes reach it: a save records only the settings it changed, an
| unchanged re-send records nothing, a type change and a move are their own kinds, and the history follows the question
| through a publish by its lineage — never by its key, which a new question can inherit. None of it is audited.
|
| ⚠️ Helpers are prefixed `history*`: Pest loads every test file into one process.
*/

beforeEach(fn () => TenantContext::flush());

/** @return array{tenant: Tenant, user: User, form: Form} */
function historySetup(): array
{
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $user = User::factory()->create(['name' => 'Ana Reyes']);
    enterTenant($tenant->id, $user->id);
    $form = app(FormService::class)->create($tenant, $user, 'Household survey');

    return ['tenant' => $tenant, 'user' => $user, 'form' => $form->refresh()];
}

/** The builder's save, built from the question as the builder holds it, with these settings changed. */
function historySave(Form $form, FormField $field, User $user, array $changes): FormField
{
    $row = app(BuilderPresenter::class)->field($field->refresh());
    $data = [...$row, 'config' => (array) $row['config'], ...$changes];

    return app(FormBuilderService::class)->updateField($form->refresh(), $field, $user, $data, $row['version']);
}

function historyAdd(Form $form, User $user, FieldType $type = FieldType::ShortText, ?string $section = null): FormField
{
    return app(FormBuilderService::class)->addField($form->refresh(), $user, $type, $section)->refresh();
}

/** @return list<FormFieldRevision> oldest first */
function historyOf(FormField $field): array
{
    return array_values(FormFieldRevision::query()->where('lineage_id', $field->refresh()->lineage_id)->orderBy('created_at')->orderBy('id')->get()->all());
}

it('records only the settings a save changed, with who, before and after — and nothing for an unchanged re-send', function (): void {
    ['user' => $user, 'form' => $form] = historySetup();
    $field = historyAdd($form, $user);
    $audits = DB::table('audits')->count();

    historySave($form, $field, $user, ['label' => 'Household size', 'hint' => 'Count everyone who sleeps here']);
    historySave($form, $field, $user, []); // the builder's undo replay, the edit journal: the same row again

    $history = historyOf($field);
    expect($history)->toHaveCount(1)
        ->and($history[0]->kind)->toBe(FieldRevisionKind::Edited)
        ->and($history[0]->user_id)->toBe($user->id)
        ->and($history[0]->field_key)->toBe($field->key)
        ->and($history[0]->changes)->toEqual([
            ['setting' => 'label', 'before' => 'Short text', 'after' => 'Household size'],
            ['setting' => 'hint', 'before' => null, 'after' => 'Count everyone who sleeps here'],
        ])
        // ⛔ Not the audit ledger: draft edits stay out of `audits` (audit-compliance-logging-spec.md).
        ->and(DB::table('audits')->count())->toBe($audits);
});

it('names each config key and the rules as their own settings', function (): void {
    ['user' => $user, 'form' => $form] = historySetup();
    $field = historyAdd($form, $user);

    historySave($form, $field, $user, [
        'config' => [...(array) $field->config, 'max_length' => 40],
        'validations' => [['rule_type' => 'min_length', 'rule_value' => '2', 'error_message' => null, 'sequence' => 0]],
    ]);

    $settings = array_column(historyOf($field)[0]->changes, 'setting');
    expect($settings)->toContain('config.max_length')->toContain('validations')->not->toContain('config');
});

it('records a change of type as its own kind, which is never put back', function (): void {
    ['user' => $user, 'form' => $form] = historySetup();
    $field = historyAdd($form, $user);

    app(FormBuilderService::class)->convertField($form->refresh(), $field, $user, FieldType::LongText, (string) FormBuilderService::rowVersion($field), null);

    $entry = historyOf($field)[0];
    expect($entry->kind)->toBe(FieldRevisionKind::TypeChanged)
        ->and($entry->kind->canBePutBack())->toBeFalse()
        ->and($entry->field_type)->toBe('long_text')
        ->and($entry->changes[0])->toEqual(['setting' => 'field_type', 'before' => 'short_text', 'after' => 'long_text']);
});

it('records the one question a drag moved, not every row the drag renumbered', function (): void {
    ['user' => $user, 'form' => $form] = historySetup();
    [$a, $b, $c, $d] = [historyAdd($form, $user), historyAdd($form, $user), historyAdd($form, $user), historyAdd($form, $user)];

    // D dragged to the top: every row is renumbered, and only D moved.
    $order = [$d, $a, $b, $c];
    app(FormBuilderService::class)->reorder($form->refresh(), $user, [], array_map(
        static fn (FormField $f, int $i): array => ['id' => $f->id, 'form_section_id' => null, 'sequence' => $i, 'section_sequence' => null],
        $order,
        array_keys($order),
    ));

    expect(historyOf($a))->toBe([])->and(historyOf($b))->toBe([])->and(historyOf($c))->toBe([])
        ->and(historyOf($d))->toHaveCount(1)
        ->and(historyOf($d)[0]->kind)->toBe(FieldRevisionKind::Moved)
        ->and(historyOf($d)[0]->changes)->toEqual([['setting' => 'position', 'before' => ['section' => null, 'position' => 4], 'after' => ['section' => null, 'position' => 1]]]);
});

it('records a move into a section, and a deleted section’s questions moving to the top level', function (): void {
    ['user' => $user, 'form' => $form] = historySetup();
    $builder = app(FormBuilderService::class);
    $section = $builder->addSection($form->refresh());
    $section->forceFill(['label' => 'Household'])->save();
    $field = historyAdd($form, $user);

    $builder->reorder($form->refresh(), $user, [['id' => $section->id, 'sequence' => 0]], [
        ['id' => $field->id, 'form_section_id' => $section->id, 'sequence' => 0, 'section_sequence' => null],
    ]);
    $builder->deleteSection($form->refresh(), $section->refresh(), $user);

    $moves = array_map(static fn (FormFieldRevision $r): array => $r->changes[0], historyOf($field));
    expect($moves)->toEqual([
        ['setting' => 'position', 'before' => ['section' => null, 'position' => 1], 'after' => ['section' => 'Household', 'position' => 1]],
        ['setting' => 'position', 'before' => ['section' => 'Household', 'position' => 1], 'after' => ['section' => null, 'position' => 1]],
    ]);
});

it('records the Other, please specify switch on the choice question', function (): void {
    ['user' => $user, 'form' => $form] = historySetup();
    $field = historyAdd($form, $user, FieldType::SingleSelect);

    app(FormBuilderService::class)->addOtherSpecify($form->refresh(), $user, $field);

    $settings = array_column(historyOf($field)[0]->changes, 'setting');
    expect($settings)->toContain('config.other_specify_key')->toContain('config.options');
});

it('keeps a value too large to hold out of the history, and says so', function (): void {
    $changes = FieldRevisionRecorder::changes(['label' => 'A'], ['label' => str_repeat('x', FieldRevisionRecorder::MAX_VALUE_BYTES + 1)]);

    expect($changes)->toBe([['setting' => 'label', 'before' => null, 'after' => null, 'too_large' => true]]);
});

it('follows the question through a publish by its lineage, never by its key', function (): void {
    ['user' => $user, 'form' => $form] = historySetup();
    $builder = app(FormBuilderService::class);
    $field = historyAdd($form, $user);
    $key = $field->key;
    historySave($form, $field, $user, ['label' => 'Age']);

    app(PublishService::class)->publish($form->refresh(), $user);
    $next = FormField::query()->where('form_version_id', $form->refresh()->draft_version_id)->where('key', $key)->firstOrFail();
    expect($next->id)->not->toBe($field->id)->and($next->lineage_id)->toBe($field->lineage_id);

    historySave($form, $next, $user, ['key' => 'age_years']);
    expect(array_map(static fn (FormFieldRevision $r): string => $r->field_key, historyOf($next)))->toBe([$key, 'age_years']);

    // Its old key, now free, is handed to the next NEW question (`uniqueKey`), which brings no history with it; nor
    // does a copy share one.
    $new = historyAdd($form, $user);
    $copy = $builder->duplicateField($form->refresh(), $user, $next->refresh());
    expect($new->key)->toBe($key)
        ->and(historyOf($new))->toBe([])
        ->and($copy->lineage_id)->not->toBe($next->lineage_id)
        ->and(historyOf($copy))->toBe([]);
});

it('marks a save that puts back an entry of this question, and an unknown entry as an ordinary edit', function (): void {
    ['user' => $user, 'form' => $form] = historySetup();
    $field = historyAdd($form, $user);
    $other = historyAdd($form, $user);
    historySave($form, $field, $user, ['label' => 'Age']);
    historySave($form, $other, $user, ['label' => 'Sex']);
    $entry = historyOf($field)[0];

    historySave($form, $field, $user, ['label' => 'Short text', 'put_back_of' => $entry->id]);
    historySave($form, $field, $user, ['label' => 'Age in years', 'put_back_of' => historyOf($other)[0]->id]);

    [, $putBack, $plain] = historyOf($field);
    expect($putBack->kind)->toBe(FieldRevisionKind::PutBack)->and($putBack->put_back_of)->toBe($entry->id)
        ->and($plain->kind)->toBe(FieldRevisionKind::Edited)->and($plain->put_back_of)->toBeNull();
});
