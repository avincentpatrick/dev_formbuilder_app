<?php

declare(strict_types=1);

use App\Enums\ComparisonOperator;
use App\Enums\FieldType;
use App\Enums\FormStatus;
use App\Enums\FormVersionStatus;
use App\Enums\ResourceCapacity;
use App\Enums\ResourceScopeable;
use App\Enums\ValidationRuleType;
use App\Models\FormFieldValidation;
use App\Models\ResourceGrant;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Forms\RestoreService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantContext::flush();
    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $this->user = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);
    $this->forms = app(FormService::class);
    $this->publisher = app(PublishService::class);
    $this->restorer = app(RestoreService::class);
});

it('creates a form with an initial v1 draft and an editor grant for the creator', function (): void {
    $form = $this->forms->create($this->tenant, $this->user, 'Household Survey');

    expect($form->status)->toBe(FormStatus::Draft)
        ->and($form->current_published_version_id)->toBeNull()
        ->and($form->draft_version_id)->not->toBeNull()
        ->and($form->default_locale)->toBe('en');

    $draft = $form->draftVersion;
    expect($draft->version_number)->toBe(1)
        ->and($draft->status)->toBe(FormVersionStatus::Draft)
        ->and($draft->schema_snapshot)->toBe([]);

    // The morph type must be the SHORT ALIAS from the morph map, never a FQCN — a stored class name
    // would break the moment the model moves namespace, and would not satisfy the RLS guard's CHECK.
    $grant = ResourceGrant::query()
        ->where('scopeable_type', ResourceScopeable::Form->value)
        ->where('scopeable_id', $form->id)
        ->where('user_id', $this->user->id)
        ->first();

    expect($grant)->not->toBeNull()
        ->and($grant->capacity)->toBe(ResourceCapacity::Editor)
        ->and($grant->granted_by)->toBe($this->user->id)
        ->and($grant->includes_descendants)->toBeFalse();
});

it('publishes the draft, points the form at it, and clones a fresh draft forward', function (): void {
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    addFormField($form->draftVersion, $this->user, 'name');
    addFormField($form->draftVersion, $this->user, 'age', FieldType::Integer, 1);

    $published = $this->publisher->publish($form->refresh(), $this->user);

    expect($published->status)->toBe(FormVersionStatus::Published)
        ->and($published->version_number)->toBe(1)
        ->and($published->published_by)->toBe($this->user->id)
        ->and($published->checksum)->toMatch('/^[0-9a-f]{64}$/')
        ->and($published->change_summary)->toBe('Initial version.')
        ->and($published->schema_snapshot)->not->toBe([]);

    $form->refresh();
    expect($form->current_published_version_id)->toBe($published->id)
        ->and($form->status)->toBe(FormStatus::Published)
        ->and($form->draft_version_id)->not->toBe($published->id);

    // A brand-new v2 draft, cloned forward: same field keys, but the rows belong to the new draft.
    $newDraft = $form->draftVersion;
    expect($newDraft->version_number)->toBe(2)
        ->and($newDraft->status)->toBe(FormVersionStatus::Draft)
        ->and($newDraft->fields()->pluck('key')->sort()->values()->all())->toBe(['age', 'name'])
        ->and($newDraft->fields()->pluck('form_version_id')->unique()->values()->all())->toBe([$newDraft->id]);
});

it('recomputes capability flags and supersedes the prior version on republish', function (): void {
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    addFormField($form->draftVersion, $this->user, 'name');
    $v1 = $this->publisher->publish($form->refresh(), $this->user);

    $form->refresh();
    addFormField($form->draftVersion, $this->user, 'location', FieldType::Geopoint, 2);
    $v2 = $this->publisher->publish($form->refresh(), $this->user);

    expect($v2->version_number)->toBe(2)
        ->and($v1->refresh()->status)->toBe(FormVersionStatus::Superseded)
        ->and($v1->refresh()->superseded_at)->not->toBeNull()
        ->and($v2->change_summary)->toContain('Added field: location');

    $form->refresh();
    expect($form->current_published_version_id)->toBe($v2->id)
        ->and($form->capability_flags['has_geofields'])->toBeTrue()
        ->and($form->capability_flags['ocr_compatible'])->toBeFalse()
        ->and($form->draftVersion->version_number)->toBe(3);
});

it('remaps validation foreign keys onto the cloned draft, never dangling on the published version', function (): void {
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    $draft = $form->draftVersion;
    $age = addFormField($draft, $this->user, 'age', FieldType::Integer);
    $limit = addFormField($draft, $this->user, 'age_limit', FieldType::Integer, 1);
    FormFieldValidation::create([
        'form_version_id' => $draft->id,
        'form_field_id' => $age->id,
        'related_form_field_id' => $limit->id,
        'rule_type' => ValidationRuleType::LessThanField,
        'operator' => ComparisonOperator::Lt,
    ]);

    $this->publisher->publish($form->refresh(), $this->user);

    $newDraft = $form->refresh()->draftVersion;
    $clonedValidation = $newDraft->validations()->firstOrFail();
    $newAge = $newDraft->fields()->where('key', 'age')->firstOrFail();
    $newLimit = $newDraft->fields()->where('key', 'age_limit')->firstOrFail();

    expect($clonedValidation->form_field_id)->toBe($newAge->id)
        ->and($clonedValidation->related_form_field_id)->toBe($newLimit->id)
        ->and($newDraft->fields()->whereKey($clonedValidation->form_field_id)->exists())->toBeTrue();
});

it('restores an old version onto the draft without publishing (forward-only, history untouched)', function (): void {
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    addFormField($form->draftVersion, $this->user, 'original');
    $v1 = $this->publisher->publish($form->refresh(), $this->user);

    // Diverge the new draft: drop `original`, add `replacement`.
    $form->refresh();
    $draft = $form->draftVersion;
    $draft->fields()->delete();
    addFormField($draft, $this->user, 'replacement');

    $restored = $this->restorer->restore($form->refresh(), $v1, $this->user);

    expect($restored->status)->toBe(FormVersionStatus::Draft)     // NOT published — forward-only
        ->and($restored->id)->toBe($draft->id)                    // same draft row, repopulated
        ->and($restored->fields()->pluck('key')->all())->toBe(['original'])
        ->and($v1->refresh()->status)->toBe(FormVersionStatus::Published); // history intact
});
