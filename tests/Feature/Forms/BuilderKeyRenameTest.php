<?php

declare(strict_types=1);

use App\Enums\FieldRevisionKind;
use App\Enums\FieldType;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormFieldRevision;
use App\Models\FormFieldValidation;
use App\Models\FormSection;
use App\Models\FormVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M242 (`R-ade0b598`, `D226`, `D227`) — a renamed key carries every reference that names it.
|--------------------------------------------------------------------------
| Through the real routes: renaming a question's key (or a section's) rewrites every condition, formula, formula
| default, custom rule, piped label/hint/placeholder and their translations, and the Other pair's link, in every row of
| the draft that named the old key — so the draft publishes with no hand edit, which is the row's defect. The reply
| carries each rewritten row with its new token; a row nothing named keeps its token; a text that cannot be read is
| reported and left as written; the thank-you message follows only while no version is live (`D227` A).
|
| EVERY `${` literal is SINGLE-quoted: PHP 8.3 dropped `${var}` interpolation, so a double-quoted one silently loses it.
| ⚠️ Tokens have one-second precision, so a case that asserts a token MOVED travels a few seconds first.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    Carbon::setTestNow();
});

/** @return array{0: Tenant, 1: User, 2: Form} */
function renameCarryWorkspace(): array
{
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $tenant->domains()->create(['domain' => 'acme']);
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');

    return [$tenant, $admin, app(FormService::class)->create($tenant, $admin, 'Rename test')];
}

/**
 * A draft where `age` is named in every column a key can be named in, beside rows that name nothing.
 *
 * @return array<string, FormField|FormSection>
 */
function renameCarryDraft(FormVersion $v, User $u): array
{
    $r = [];
    // `age` is asked on a page of its own, before every text that pipes it.
    $r['about'] = FormSection::create(['form_version_id' => $v->id, 'key' => 'about', 'label' => 'About you', 'sequence' => 0]);
    $r['intro'] = FormSection::create([
        'form_version_id' => $v->id, 'key' => 'intro', 'sequence' => 1,
        'label' => 'About ${age}', 'label_translations' => ['fil' => 'Tungkol sa ${age}'],
        'description' => 'Ages: ${age}', 'relevant_expression' => '${age} >= 0',
    ]);
    $r['roster'] = FormSection::create(['form_version_id' => $v->id, 'key' => 'roster', 'label' => 'Roster', 'sequence' => 2, 'is_repeatable' => true]);
    // Last saved by the same member who renames, as in the builder: a question whose only change is a rule then has no
    // other column to change, and its token must still move (`old_enough`).
    $in = ['form_section_id' => $r['intro']->id, 'updated_by' => $u->id];

    $r['age'] = addFormField($v, $u, 'age', FieldType::Integer, 0, ['form_section_id' => $r['about']->id]);
    $r['weight'] = addFormField($v, $u, 'weight', FieldType::Integer, 1, $in + [
        'relevant_expression' => '${age} > 1', 'label' => 'Weight at ${age}', 'hint' => '${age} years', 'placeholder' => '${age}',
        'label_translations' => ['fil' => 'Timbang sa ${age}'], 'hint_translations' => ['fil' => '${age} taon'],
    ]);
    $r['bmi'] = addFormField($v, $u, 'bmi', FieldType::Calculated, 2, $in + [
        'config' => ['calculated_formula' => '${weight} * ${age}', 'calculated_type' => 'number'],
    ]);
    $r['next_age'] = addFormField($v, $u, 'next_age', FieldType::Integer, 3, $in + [
        'default_value' => '${age} + 1', 'default_value_is_expression' => true,
    ]);
    $r['old_enough'] = addFormField($v, $u, 'old_enough', FieldType::Integer, 4, $in);
    FormFieldValidation::create([
        'form_version_id' => $v->id, 'form_field_id' => $r['old_enough']->id, 'sequence' => 0,
        'expression' => '. >= ${age}', 'error_message' => 'Too young.',
    ]);
    // "Required when…" names `age` by ID: a rename needs nothing here, so the row is not rewritten.
    $r['reason'] = addFormField($v, $u, 'reason', FieldType::ShortText, 5, $in);
    FormFieldValidation::create([
        'form_version_id' => $v->id, 'form_field_id' => $r['reason']->id, 'sequence' => 0,
        'rule_type' => 'required_if', 'operator' => 'eq', 'rule_value' => '60', 'related_form_field_id' => $r['age']->id,
        'error_message' => 'Say why.',
    ]);
    $r['untouched'] = addFormField($v, $u, 'untouched', FieldType::ShortText, 6, $in + ['label' => 'Names ${weight} only']);
    $r['job'] = addFormField($v, $u, 'job', FieldType::Dropdown, 7, $in + [
        'config' => ['options' => [['value' => 'farmer', 'label' => 'Farmer'], ['value' => 'other', 'label' => 'Other']], 'other_specify_key' => 'job_other'],
    ]);
    $r['job_other'] = addFormField($v, $u, 'job_other', FieldType::ShortText, 8, $in + [
        'label' => 'Please specify', 'relevant_expression' => 'selected(${job}, \'other\')',
    ]);
    $r['members'] = addFormField($v, $u, 'members', FieldType::Calculated, 9, $in + [
        'config' => ['calculated_formula' => 'count(${roster})', 'calculated_type' => 'integer'],
    ]);
    $r['member_name'] = addFormField($v, $u, 'member_name', FieldType::ShortText, 10, ['form_section_id' => $r['roster']->id]);

    return $r;
}

/** The PATCH a builder sends for the question as stored, with its key changed. */
function renameCarryField(Form $form, User $admin, FormField $field, string $key): TestResponse
{
    enterTenant($form->tenant_id, $admin->id);
    $stored = FormField::query()->whereKey($field->id)->firstOrFail();
    $rules = FormFieldValidation::query()->where('form_field_id', $stored->id)->orderBy('sequence')->get();
    $keyById = FormField::query()->where('form_version_id', $stored->form_version_id)->pluck('key', 'id');

    return test()->withHeaders(builderHeaders($form))->actingAs($admin)
        ->patchJson("http://acme.meridian.test/forms/{$form->id}/fields/{$stored->id}", [
            'key' => $key, 'label' => $stored->label, 'hint' => $stored->hint, 'placeholder' => $stored->placeholder,
            'is_required' => $stored->is_required->value, 'relevant_expression' => $stored->relevant_expression,
            'appearance' => $stored->appearance, 'config' => $stored->config ?? [],
            'default_value' => $stored->default_value, 'default_value_is_expression' => $stored->default_value_is_expression,
            'validations' => $rules->map(static fn (FormFieldValidation $v): array => [
                'rule_type' => $v->rule_type?->value, 'operator' => $v->operator?->value, 'rule_value' => $v->rule_value,
                'expression' => $v->expression, 'error_message' => $v->error_message,
                'related_field_key' => $v->related_form_field_id === null ? null : $keyById->get($v->related_form_field_id),
            ])->all(),
            'version' => builderToken($stored),
        ]);
}

/**
 * Every text a key can be named in, for every row of the draft, keyed `kind:key-at-start.column` — compared whole.
 *
 * @return array<string, mixed>
 */
function renameCarryTexts(Form $form): array
{
    enterTenant($form->tenant_id, $form->owner_user_id);
    $out = [];
    foreach (FormField::query()->where('form_version_id', $form->draft_version_id)->orderBy('sequence')->get() as $f) {
        foreach (['label', 'hint', 'placeholder', 'relevant_expression', 'default_value', 'label_translations', 'hint_translations', 'config'] as $c) {
            $out["field:{$f->sequence}.{$c}"] = $f->getAttribute($c);
        }
        foreach (FormFieldValidation::query()->where('form_field_id', $f->id)->orderBy('sequence')->get() as $rule) {
            $out["rule:{$f->sequence}.{$rule->sequence}"] = $rule->expression;
        }
    }
    foreach (FormSection::query()->where('form_version_id', $form->draft_version_id)->orderBy('sequence')->get() as $s) {
        foreach (['label', 'description', 'relevant_expression', 'label_translations', 'description_translations'] as $c) {
            $out["section:{$s->sequence}.{$c}"] = $s->getAttribute($c);
        }
    }

    return $out;
}

it('carries a renamed key into every row that names it, replies with each rewritten row, and the draft publishes untouched', function (): void {
    [, $admin, $form] = renameCarryWorkspace();
    $r = renameCarryDraft($form->draftVersion, $admin);
    $tokens = [];
    foreach ($r as $name => $row) {
        $tokens[$name] = builderToken($row);
    }
    Carbon::setTestNow(now()->addSeconds(5)); // tokens have one-second precision

    $reply = renameCarryField($form, $admin, $r['age'], 'age_years')
        ->assertOk()
        ->assertJsonPath('key', 'age_years')
        ->assertJsonPath('carried.from', 'age')
        ->assertJsonPath('carried.to', 'age_years')
        ->assertJsonPath('carried.unread', [])
        ->assertJsonPath('carried.confirmation', null);

    // Exactly the rows that named `age`, in the draft's order — `old_enough` for its custom rule alone.
    expect(array_column($reply->json('carried.fields'), 'key'))->toBe(['weight', 'bmi', 'next_age', 'old_enough'])
        ->and(array_column($reply->json('carried.sections'), 'key'))->toBe(['intro']);

    enterTenant($form->tenant_id, $admin->id);
    $weight = FormField::query()->whereKey($r['weight']->id)->firstOrFail();
    expect($weight->relevant_expression)->toBe('${age_years} > 1')
        ->and($weight->label)->toBe('Weight at ${age_years}')
        ->and($weight->hint)->toBe('${age_years} years')
        ->and($weight->placeholder)->toBe('${age_years}')
        ->and($weight->label_translations)->toBe(['fil' => 'Timbang sa ${age_years}'])
        ->and($weight->hint_translations)->toBe(['fil' => '${age_years} taon'])
        ->and(FormField::query()->whereKey($r['bmi']->id)->value('config'))->toContain('${weight} * ${age_years}')
        ->and(FormField::query()->whereKey($r['next_age']->id)->value('default_value'))->toBe('${age_years} + 1')
        ->and(FormFieldValidation::query()->where('form_field_id', $r['old_enough']->id)->value('expression'))->toBe('. >= ${age_years}');
    $intro = FormSection::query()->whereKey($r['intro']->id)->firstOrFail();
    expect($intro->label)->toBe('About ${age_years}')
        ->and($intro->label_translations)->toBe(['fil' => 'Tungkol sa ${age_years}'])
        ->and($intro->description)->toBe('Ages: ${age_years}')
        ->and($intro->relevant_expression)->toBe('${age_years} >= 0');

    // Every rewritten row's token moved, and the reply carries the new one, so the builder's next save of it is not a
    // conflict. A row nothing named — the rule naming `age` by id included — keeps its token.
    foreach ($reply->json('carried.fields') as $row) {
        expect($row['version'])->toBe(builderToken(FormField::query()->whereKey($row['id'])->firstOrFail()))
            ->not->toBe($tokens[$row['key']]);
    }
    expect($reply->json('carried.sections.0.version'))->not->toBe($tokens['intro']);
    foreach (['reason', 'untouched', 'job', 'job_other', 'members', 'member_name'] as $name) {
        expect(builderToken(FormField::query()->whereKey($r[$name]->id)->firstOrFail()))->toBe($tokens[$name], "{$name}'s token moved");
    }
    foreach (['about', 'roster'] as $name) {
        expect(builderToken(FormSection::query()->whereKey($r[$name]->id)->firstOrFail()))->toBe($tokens[$name], "{$name}'s token moved");
    }

    // Each rewritten question's history records the change as the renamer's edit.
    $edit = FormFieldRevision::query()->where('lineage_id', $weight->lineage_id)->sole();
    expect($edit->user_id)->toBe($admin->id)
        ->and($edit->kind)->toBe(FieldRevisionKind::Edited)
        ->and(array_column($edit->changes, 'setting'))->toContain('relevant_expression', 'label');

    // The row's defect: until now publish refused every one of those references.
    app(PublishService::class)->publish($form->refresh(), $admin);
    expect($form->refresh()->current_published_version_id)->not->toBeNull();
});

it('puts every text back byte for byte when the key is renamed back, as the builder’s undo does', function (): void {
    [, $admin, $form] = renameCarryWorkspace();
    $r = renameCarryDraft($form->draftVersion, $admin);
    $before = renameCarryTexts($form);

    renameCarryField($form, $admin, $r['age'], 'age_years')->assertOk();
    expect(renameCarryTexts($form))->not->toBe($before);

    renameCarryField($form, $admin, $r['age'], 'age')->assertOk()->assertJsonPath('carried.to', 'age');
    expect(renameCarryTexts($form))->toBe($before);
});

it('carries a question’s own rule that names its own key, in the reply’s row and the question’s one history entry', function (): void {
    [, $admin, $form] = renameCarryWorkspace();
    $v = $form->draftVersion;
    $age = addFormField($v, $admin, 'age', FieldType::Integer, 0);
    FormFieldValidation::create(['form_version_id' => $v->id, 'form_field_id' => $age->id, 'sequence' => 0, 'expression' => '${age} < 130', 'error_message' => 'Too old.']);

    $reply = renameCarryField($form, $admin, $age, 'age_years')->assertOk();

    expect($reply->json('validations.0.expression'))->toBe('${age_years} < 130')
        ->and(array_column($reply->json('carried.fields'), 'id'))->toBe([$age->id]);
    enterTenant($form->tenant_id, $admin->id);
    expect(FormFieldRevision::query()->where('lineage_id', $age->refresh()->lineage_id)->count())->toBe(1);
});

it('reports a text that names the old key but cannot be read, and leaves it as written', function (): void {
    [, $admin, $form] = renameCarryWorkspace();
    $v = $form->draftVersion;
    $age = addFormField($v, $admin, 'age', FieldType::Integer, 0);
    $broken = addFormField($v, $admin, 'note', FieldType::ShortText, 1, ['label' => 'Note', 'relevant_expression' => '${age} = \'unfinished']);

    renameCarryField($form, $admin, $age, 'age_years')
        ->assertOk()
        ->assertJsonPath('carried.fields', [])
        ->assertJsonPath('carried.unread', [['kind' => 'field', 'id' => $broken->id, 'label' => 'Note', 'column' => 'relevant_expression']]);

    enterTenant($form->tenant_id, $admin->id);
    expect($broken->refresh()->relevant_expression)->toBe('${age} = \'unfinished');
});

it('carries a renamed section key into count(), and records the rewritten question’s history as the renamer’s', function (): void {
    [, $admin, $form] = renameCarryWorkspace();
    $r = renameCarryDraft($form->draftVersion, $admin);
    enterTenant($form->tenant_id, $admin->id);
    $roster = FormSection::query()->whereKey($r['roster']->id)->firstOrFail();

    $reply = test()->withHeaders(builderHeaders($form))->actingAs($admin)
        ->patchJson("http://acme.meridian.test/forms/{$form->id}/sections/{$roster->id}", [
            'key' => 'household', 'label' => 'Roster', 'is_repeatable' => true, 'version' => builderToken($roster),
        ])
        ->assertOk()
        ->assertJsonPath('key', 'household')
        ->assertJsonPath('carried.from', 'roster');

    expect(array_column($reply->json('carried.fields'), 'key'))->toBe(['members']);
    enterTenant($form->tenant_id, $admin->id);
    $members = FormField::query()->whereKey($r['members']->id)->firstOrFail();
    expect($members->config['calculated_formula'])->toBe('count(${household})')
        ->and(FormFieldRevision::query()->where('lineage_id', $members->lineage_id)->sole()->user_id)->toBe($admin->id);
});

it('carries the Other pair both ways: the Please specify condition, and the choice question’s link', function (): void {
    [, $admin, $form] = renameCarryWorkspace();
    $r = renameCarryDraft($form->draftVersion, $admin);

    renameCarryField($form, $admin, $r['job'], 'occupation')->assertOk();
    enterTenant($form->tenant_id, $admin->id);
    expect(FormField::query()->whereKey($r['job_other']->id)->value('relevant_expression'))->toBe('selected(${occupation}, \'other\')');

    renameCarryField($form, $admin, $r['job_other'], 'occupation_other')->assertOk();
    enterTenant($form->tenant_id, $admin->id);
    expect(FormField::query()->whereKey($r['job']->id)->firstOrFail()->config['other_specify_key'])->toBe('occupation_other');
});

it('keeps a renamed key out of the section keys, but never renames a key that already shares one on an unrelated edit', function (): void {
    [, $admin, $form] = renameCarryWorkspace();
    $r = renameCarryDraft($form->draftVersion, $admin);

    // A section holds `intro`, so the question is kept as `intro_2` and the references follow THAT key.
    renameCarryField($form, $admin, $r['age'], 'intro')
        ->assertOk()
        ->assertJsonPath('key', 'intro_2')
        ->assertJsonPath('carried.to', 'intro_2');

    // A question already sharing a section's key (another writer made it) keeps it when it is saved unchanged.
    enterTenant($form->tenant_id, $admin->id);
    $shared = addFormField($form->draftVersion, $admin, 'roster', FieldType::ShortText, 20);
    renameCarryField($form, $admin, $shared, 'roster')
        ->assertOk()
        ->assertJsonPath('key', 'roster')
        ->assertJsonMissingPath('carried');
});

it('rewrites the thank-you message while no version is live, and leaves it on a live form (D227 A)', function (): void {
    [$tenant, $admin, $form] = renameCarryWorkspace();
    $v = $form->draftVersion;
    $age = addFormField($v, $admin, 'age', FieldType::Integer, 0);
    $form->forceFill(['confirmation_message' => 'Thanks — ${age}', 'confirmation_message_translations' => ['fil' => 'Salamat — ${age}']])->save();

    renameCarryField($form, $admin, $age, 'age_years')->assertOk()->assertJsonPath('carried.confirmation', 'carried');
    enterTenant($tenant->id, $admin->id);
    $form->refresh();
    expect($form->confirmation_message)->toBe('Thanks — ${age_years}')
        ->and($form->confirmation_message_translations)->toBe(['fil' => 'Salamat — ${age_years}']);

    // Live now: the published version reads the message, so a draft rename leaves it as it is and says so.
    app(PublishService::class)->publish($form, $admin);
    $form->refresh();
    $draftAge = FormField::query()->where('form_version_id', $form->draft_version_id)->where('key', 'age_years')->firstOrFail();

    renameCarryField($form, $admin, $draftAge, 'years')->assertOk()->assertJsonPath('carried.confirmation', 'left');
    enterTenant($tenant->id, $admin->id);
    expect($form->refresh()->confirmation_message)->toBe('Thanks — ${age_years}');
});

it('replies with no carried block when the key is kept', function (): void {
    [, $admin, $form] = renameCarryWorkspace();
    $r = renameCarryDraft($form->draftVersion, $admin);

    renameCarryField($form, $admin, $r['age'], 'age')->assertOk()->assertJsonMissingPath('carried');
});
