<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\FormVersionStatus;
use App\Exceptions\Forms\FormException;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormFieldValidation;
use App\Models\FormSection;
use App\Models\FormVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormBuilderService;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment D4a — the builder cannot mutate a PUBLISHED version through the HTTP surface.
|--------------------------------------------------------------------------
| Published versions are immutable (form-versioning-schema-migration.md §2). The draft_child RLS guard is
| the DB backstop; FormBuilderService::assertDraftChild is the clean service guard that turns a write
| against a published version into a 422 instead of a silent zero-row write. This proves both: the HTTP
| endpoint rejects it, AND a raw RLS-level UPDATE on a published child is a no-op. (builderTenant() +
| enterTenant()/makeActiveMember() are shared — defined in BuilderRoutesTest.php / tests/Pest.php.)
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** Create a form, add one field to its draft, publish it — returns [form, publishedField, draftField]. */
function publishedFormWithField(Tenant $tenant, User $admin): array
{
    enterTenant($tenant->id, $admin->id);
    $form = app(FormService::class)->create($tenant, $admin, 'Survey');
    app(FormBuilderService::class)->addField($form, $admin, FieldType::ShortText, null);
    app(PublishService::class)->publish($form->refresh(), $admin);

    $form->refresh();
    $publishedVersion = FormVersion::query()->where('form_id', $form->id)
        ->where('status', FormVersionStatus::Published)->firstOrFail();
    $publishedField = FormField::query()->where('form_version_id', $publishedVersion->id)->firstOrFail();
    $draftField = FormField::query()->where('form_version_id', $form->draft_version_id)->firstOrFail();

    return [$form, $publishedField, $draftField];
}

it('rejects editing a field that belongs to a published version (422)', function (): void {
    $tenant = builderTenant();
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');

    [$form, $publishedField] = publishedFormWithField($tenant, $admin);

    $this->actingAs($admin)
        ->patchJson("http://acme.meridian.test/forms/{$form->id}/fields/{$publishedField->id}", [
            'key' => 'hacked', 'label' => 'Hacked', 'is_required' => 'optional',
            'config' => [], 'validations' => [], 'version' => null,
        ])
        ->assertStatus(422);

    // The published field is untouched.
    enterTenant($tenant->id, $admin->id);
    expect(FormField::query()->whereKey($publishedField->id)->value('key'))->not->toBe('hacked');
});

it('rejects deleting a field that belongs to a published version (422)', function (): void {
    $tenant = builderTenant();
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');

    [$form, $publishedField] = publishedFormWithField($tenant, $admin);

    $this->actingAs($admin)
        ->deleteJson("http://acme.meridian.test/forms/{$form->id}/fields/{$publishedField->id}")
        ->assertStatus(422);

    enterTenant($tenant->id, $admin->id);
    expect(FormField::query()->whereKey($publishedField->id)->exists())->toBeTrue();
});

it('still allows editing the current draft after publishing (positive control)', function (): void {
    $tenant = builderTenant();
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');

    [$form, , $draftField] = publishedFormWithField($tenant, $admin);

    $this->actingAs($admin)
        ->patchJson("http://acme.meridian.test/forms/{$form->id}/fields/{$draftField->id}", [
            'key' => 'edited', 'label' => 'Edited', 'is_required' => 'optional',
            'config' => [], 'validations' => [], 'version' => null,
        ])
        ->assertOk();

    enterTenant($tenant->id, $admin->id);
    expect(FormField::query()->whereKey($draftField->id)->value('key'))->toBe('edited');
});

it('is backstopped by RLS — a raw UPDATE on a published child touches zero rows', function (): void {
    $tenant = builderTenant();
    $admin = User::factory()->create();

    [, $publishedField] = publishedFormWithField($tenant, $admin);

    enterTenant($tenant->id, $admin->id);
    $affected = FormField::query()->whereKey($publishedField->id)->update(['label' => 'via-rls']);

    expect($affected)->toBe(0);
    expect(FormField::query()->whereKey($publishedField->id)->value('label'))->not->toBe('via-rls');
});

/*
|--------------------------------------------------------------------------
| Increment M88 — the same guard, re-decided from the database inside the write.
|--------------------------------------------------------------------------
| Every case above binds a form that was ALREADY published, which is the only state
| `assertDraftChild` can see: it compares `$form->draft_version_id` and `$child->form_version_id`,
| both read at route-model-binding time. The cases below bind a DRAFT and then let the version
| change underneath it — which is what a publish, a restore, an XLSForm import, an archive or
| another editor's delete does — and the pre-M88 service let all of them through.
|
| ⛔ AND "LET THROUGH" DID NOT MEAN A CRASH. The `draft_child` RLS policy made the UPDATE match zero
| rows, `save()` reported success anyway, and `updateField()` returned `$field->refresh()`: the
| builder got 200 OK carrying the PRE-EDIT values and the user watched the edit revert with no
| error. The assertions below are written in that shape — the throw AND the unchanged row — because
| the throw alone would also pass against a service that silently did nothing.
|
| These drive the SERVICE and not the HTTP surface, deliberately. Through a request, route-model
| binding re-loads both models on every call, so the stale snapshot this closes cannot be staged
| without a second connection; the service takes the snapshot as arguments, which is exactly the
| shape the defect has.
*/

/** A form with one draft field, plus the models a request would be holding. Returns [form, field]. */
function draftFormWithBoundField(Tenant $tenant, User $admin): array
{
    enterTenant($tenant->id, $admin->id);
    $form = app(FormService::class)->create($tenant, $admin, 'Survey');
    app(FormBuilderService::class)->addField($form->refresh(), $admin, FieldType::ShortText, null);

    $bound = Form::query()->whereKey($form->id)->firstOrFail();
    $field = FormField::query()->where('form_version_id', $bound->draft_version_id)->firstOrFail();

    return [$bound, $field];
}

/** @return array<string, mixed> */
function builderFieldPayload(string $key): array
{
    return [
        'key' => $key,
        'label' => 'Edited',
        'is_required' => 'optional',
        'config' => [],
        'validations' => [],
    ];
}

it('refuses a field edit whose form was published after the models were bound', function (): void {
    $tenant = builderTenant();
    $admin = User::factory()->create();

    [$boundForm, $boundField] = draftFormWithBoundField($tenant, $admin);
    $original = $boundField->key;

    // The concurrent commit, staged: publish through a SEPARATELY loaded model, so the bound one
    // keeps the draft_version_id it was loaded with — exactly the state the race produces.
    app(PublishService::class)->publish(Form::query()->whereKey($boundForm->id)->firstOrFail(), $admin);

    expect($boundForm->draft_version_id)->toBe($boundField->form_version_id); // the stale guard agrees

    expect(fn () => app(FormBuilderService::class)
        ->updateField($boundForm, $boundField, $admin, builderFieldPayload('edited'), null))
        ->toThrow(FormException::class);

    enterTenant($tenant->id, $admin->id);
    expect(FormField::query()->whereKey($boundField->id)->value('key'))->toBe($original);
});

it('refuses a section edit whose form was published after the models were bound', function (): void {
    $tenant = builderTenant();
    $admin = User::factory()->create();

    enterTenant($tenant->id, $admin->id);
    $form = app(FormService::class)->create($tenant, $admin, 'Survey');
    $section = app(FormBuilderService::class)->addSection($form->refresh());

    $boundForm = Form::query()->whereKey($form->id)->firstOrFail();
    $boundSection = FormSection::query()->whereKey($section->id)->firstOrFail();
    $original = $boundSection->label;

    app(PublishService::class)->publish(Form::query()->whereKey($form->id)->firstOrFail(), $admin);

    expect(fn () => app(FormBuilderService::class)->updateSection($boundForm, $boundSection, [
        'key' => 'edited', 'label' => 'Edited', 'description' => null,
    ], null))->toThrow(FormException::class);

    enterTenant($tenant->id, $admin->id);
    expect(FormSection::query()->whereKey($boundSection->id)->value('label'))->toBe($original);
});

it('refuses a field edit whose row another editor deleted after the models were bound', function (): void {
    // The publish case needs a version flip to be visible. This one does not move
    // `draft_version_id` at all, so re-reading the FORM alone cannot see it — which is why the
    // guard re-reads the CHILD as well. It is also the more reachable race of the two: two
    // collaborators in one builder, no publish involved.
    $tenant = builderTenant();
    $admin = User::factory()->create();

    [$boundForm, $boundField] = draftFormWithBoundField($tenant, $admin);

    app(FormBuilderService::class)->deleteField(
        Form::query()->whereKey($boundForm->id)->firstOrFail(),
        FormField::query()->whereKey($boundField->id)->firstOrFail()
    );

    expect($boundForm->draft_version_id)->toBe($boundField->form_version_id); // still agrees

    expect(fn () => app(FormBuilderService::class)
        ->updateField($boundForm, $boundField, $admin, builderFieldPayload('edited'), null))
        ->toThrow(FormException::class);

    enterTenant($tenant->id, $admin->id);
    expect(FormField::query()->whereKey($boundField->id)->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| M89 — the window the re-read guard cannot close surfaces as a 422, not a 500.
|--------------------------------------------------------------------------
| assertStillDraftChild() re-reads BEFORE the write. A delete committing after that re-read and before
| replaceValidations()' INSERT reaches the foreign key itself, which is IMMEDIATE — there is no
| DEFERRABLE in database/migrations/ — so PostgreSQL raises SQLSTATE 23503. Nothing rendered it:
| bootstrap/app.php registers no QueryException renderable, its Throwable catch-all returns early for
| anything outside `api/v1/*`, and the builder's fetch sets `Accept: application/json`, so the framework
| answered the generic JSON 500 and the builder showed "Server Error".
|
| ⛔ THE RACE IS STAGED INSIDE THE TRANSACTION, WHICH IS THE ONLY WAY IT CAN BE STAGED DETERMINISTICALLY.
| A second connection cannot see the RefreshDatabase transaction, which is why no concurrency test in this
| repository opens one. A `creating` hook on the validation row commits the delete at exactly the instant
| the real race does — after the guard, before the INSERT — on the one connection the test has.
| Model event listeners registered here do not leak: each test boots a fresh dispatcher.
*/

it('answers 422, not 500, when the edited field is deleted between the guard and the write', function (): void {
    $tenant = builderTenant();
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');

    [$form, $field] = draftFormWithBoundField($tenant, $admin);

    // The concurrent commit, staged at the instant the guard can no longer see it.
    FormFieldValidation::creating(static function () use ($field): void {
        DB::table('form_fields')->where('id', $field->id)->delete();
    });

    $response = $this->actingAs($admin)
        ->patchJson("http://acme.meridian.test/forms/{$form->id}/fields/{$field->id}", [
            'key' => 'edited', 'label' => 'Edited', 'is_required' => 'optional',
            'config' => [], 'version' => null,
            'validations' => [['rule_type' => 'min_length', 'rule_value' => '3']],
        ]);

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('removed by another editor');
});

it('names the RELATED field when that is the row a concurrent editor removed', function (): void {
    $tenant = builderTenant();
    $admin = User::factory()->create();

    [$form, $field] = draftFormWithBoundField($tenant, $admin);
    $sibling = app(FormBuilderService::class)->addField($form->refresh(), $admin, FieldType::ShortText, null);

    // Same statement, the OTHER foreign key: `related_form_field_id`. assertStillDraftChild() re-reads
    // only the edited child, so this half of replaceValidations() is not narrowed by the guard at all.
    FormFieldValidation::creating(static function () use ($sibling): void {
        DB::table('form_fields')->where('id', $sibling->id)->delete();
    });

    expect(fn () => app(FormBuilderService::class)->updateField(
        Form::query()->whereKey($form->id)->firstOrFail(),
        FormField::query()->whereKey($field->id)->firstOrFail(),
        $admin,
        [
            'key' => 'edited', 'label' => 'Edited', 'is_required' => 'optional', 'config' => [],
            'validations' => [[
                'rule_type' => 'required_if',
                'related_field_key' => $sibling->key,
                'rule_value' => 'yes',
            ]],
        ],
        null
    ))->toThrow(FormException::class, 'A field this rule refers to was removed by another editor. Refresh the builder and try again.');
});

it('lets an unrelated constraint violation keep its own behaviour (negative control)', function (): void {
    $tenant = builderTenant();
    $admin = User::factory()->create();

    [$form, $field] = draftFormWithBoundField($tenant, $admin);

    // 23514, not 23503: the rule XOR check refuses a row carrying BOTH expression and rule_type. The
    // catch must rethrow it untouched rather than relabel every QueryException as a removed row.
    expect(fn () => app(FormBuilderService::class)->updateField(
        Form::query()->whereKey($form->id)->firstOrFail(),
        FormField::query()->whereKey($field->id)->firstOrFail(),
        $admin,
        [
            'key' => 'edited', 'label' => 'Edited', 'is_required' => 'optional', 'config' => [],
            'validations' => [['rule_type' => 'min_length', 'expression' => '${a} > 1', 'rule_value' => '3']],
        ],
        null
    ))->toThrow(QueryException::class);
});

/*
|--------------------------------------------------------------------------
| M90 — the OTHER FOUR call sites, and they do not share a symptom.
|--------------------------------------------------------------------------
| M88 routed `updateField()` and `updateSection()` through the re-reading guard. Four more call sites
| kept the route-bound one: `deleteSection()`, `deleteField()`, `duplicateField()` and
| `saveFieldToLibrary()`. The backlog row named two of them and excluded the locking pair because
| "the locking siblings' exposure is bounded by their lock".
|
| ⛔ THAT REASON IS FALSE, AND IT IS WHY THE CENSUS WAS SHORT. `lockDraft()` re-reads under FOR UPDATE
| and returns a FRESH draft; the guard compares the STALE `$form` the caller passed in. They are
| different objects, so the lock bounds nothing about a staleness that began at route-model binding.
|
| ⚠️ THE FOUR NEED DIFFERENT ASSERTIONS BECAUSE THEY FAIL DIFFERENTLY, and writing one shared case for
| them would have hidden that:
|   - the two DELETES were silent — `draft_delete`'s USING clause matched zero rows, Eloquent's
|     delete() returned true regardless, and the controller answered `['deleted' => true]` with a 200.
|     So the row must still be THERE afterwards; asserting only the throw would pass against the old
|     code's silent no-op too.
|   - duplicateField() was NOT silent: a straddled INSERT hit `draft_insert`'s WITH CHECK and raised
|     42501, which respond() does not catch. So the old behaviour is a QueryException, and the new one
|     is a FormException — a test that accepted any Throwable would not tell those apart.
*/

it('refuses a field DELETE whose form was published after the models were bound, and leaves the row', function (): void {
    $tenant = builderTenant();
    $admin = User::factory()->create();

    [$boundForm, $boundField] = draftFormWithBoundField($tenant, $admin);

    app(PublishService::class)->publish(Form::query()->whereKey($boundForm->id)->firstOrFail(), $admin);

    expect($boundForm->draft_version_id)->toBe($boundField->form_version_id); // the stale guard agrees

    expect(fn () => app(FormBuilderService::class)->deleteField($boundForm, $boundField))
        ->toThrow(FormException::class);

    // ⛔ THE LOAD-BEARING HALF. Before M90 this returned normally and deleted NOTHING, and the
    // controller answered `['deleted' => true]` with a 200 — so the field vanished from the builder
    // and was still there on reload. The throw alone cannot tell that apart from a correct refusal.
    enterTenant($tenant->id, $admin->id);
    expect(FormField::query()->whereKey($boundField->id)->exists())->toBeTrue();
});

it('refuses a section DELETE whose form was published after the models were bound, and leaves the row', function (): void {
    $tenant = builderTenant();
    $admin = User::factory()->create();

    enterTenant($tenant->id, $admin->id);
    $form = app(FormService::class)->create($tenant, $admin, 'Survey');
    $section = app(FormBuilderService::class)->addSection($form->refresh());

    $boundForm = Form::query()->whereKey($form->id)->firstOrFail();
    $boundSection = FormSection::query()->whereKey($section->id)->firstOrFail();

    app(PublishService::class)->publish(Form::query()->whereKey($form->id)->firstOrFail(), $admin);

    expect(fn () => app(FormBuilderService::class)->deleteSection($boundForm, $boundSection))
        ->toThrow(FormException::class);

    enterTenant($tenant->id, $admin->id);
    expect(FormSection::query()->whereKey($boundSection->id)->exists())->toBeTrue();
});

it('refuses a DUPLICATE across the version boundary with a 422, where it used to raise 42501', function (): void {
    $tenant = builderTenant();
    $admin = User::factory()->create();

    [$boundForm, $boundField] = draftFormWithBoundField($tenant, $admin);

    app(PublishService::class)->publish(Form::query()->whereKey($boundForm->id)->firstOrFail(), $admin);

    // ⚠️ FormException, NOT QueryException, and the distinction is the whole case. The old path built
    // an INSERT carrying the stale field's `form_version_id` with the fresh draft's key and sequence;
    // `draft_insert`'s WITH CHECK refused it as 42501, which FormBuilderController::respond() does not
    // catch, so it left as a bare 500. M89 deliberately declined to RELABEL 42501 in updateField's
    // typed catch — this does not relabel it either, it makes it unreachable through this path.
    expect(fn () => app(FormBuilderService::class)->duplicateField($boundForm, $admin, $boundField))
        ->toThrow(FormException::class);

    enterTenant($tenant->id, $admin->id);
    expect(FormField::query()->where('form_version_id', $boundField->form_version_id)->count())->toBe(1);
});

it('refuses a library CAPTURE across the version boundary, which is consistency rather than safety', function (): void {
    $tenant = builderTenant();
    $admin = User::factory()->create();

    [$boundForm, $boundField] = draftFormWithBoundField($tenant, $admin);

    app(PublishService::class)->publish(Form::query()->whereKey($boundForm->id)->firstOrFail(), $admin);

    // ⚠️ STATED SO NOBODY READS MORE INTO IT THAN IS THERE. This one was never corruption: publish
    // clones the tree forward with identical content and new ids, so the item captured in the race
    // would have been byte-identical to a correct one. It refuses because every sibling does, and
    // because a capture whose source the user can no longer see is a confusing thing to succeed.
    expect(fn () => app(FormBuilderService::class)->saveFieldToLibrary($boundForm, $admin, $boundField, []))
        ->toThrow(FormException::class);

    enterTenant($tenant->id, $admin->id);
    expect(DB::table('field_library')->count())->toBe(0);
});
