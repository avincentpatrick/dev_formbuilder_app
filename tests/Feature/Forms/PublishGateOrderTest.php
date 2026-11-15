<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\RequiredMode;
use App\Enums\ValidationRuleType;
use App\Exceptions\Forms\PublishValidationException;
use App\Models\FormFieldValidation;
use App\Models\FormVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\StructuralValidationGate;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M241 — a publish refusal lists its problems in the form's order (publish-gate-field-order).
|--------------------------------------------------------------------------
| The gate used to read a version's questions, sections and rules with no ORDER BY, so a refusal followed
| storage order. NoteContentShapeTest's order assertion then failed on some runs and not others. Each case
| below WRITES its rows in an order that differs from the form's, so an unordered read returns them in
| insertion order every time. Without the fix these fail deterministically, not by chance.
*/

beforeEach(function (): void {
    TenantContext::flush();
    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $this->user = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);
});

/** @return list<array{field: ?string, code: string}> */
function publishRefusals(FormVersion $version): array
{
    try {
        (new StructuralValidationGate)->assertPublishable($version->refresh());
    } catch (PublishValidationException $e) {
        return array_map(static fn (array $v): array => ['field' => $v['field'] ?? null, 'code' => $v['code']], $e->violations());
    }

    return [];
}

it('lists question problems in the form’s order, not the order the questions were saved in', function (): void {
    $version = makeDraftVersion(makeForm($this->user));

    // Saved third, first, second; the form's order is first, second, third.
    foreach (['third' => 2, 'first' => 0, 'second' => 1] as $key => $sequence) {
        addFormField($version, $this->user, $key, FieldType::Note, $sequence, ['is_required' => RequiredMode::Required]);
    }

    expect(publishRefusals($version))->toBe([
        ['field' => 'first', 'code' => 'display_only_field_required'],
        ['field' => 'second', 'code' => 'display_only_field_required'],
        ['field' => 'third', 'code' => 'display_only_field_required'],
    ]);
});

it('lists rule problems in the order of the questions that own them, not the order the rules were saved in', function (): void {
    $version = makeDraftVersion(makeForm($this->user));
    // The second question is saved before the first, so neither the rows' storage order nor an index on the
    // owning question's id (ids are time-ordered) can hand the rules back in the form's order by accident.
    // Measured: with the questions saved in form order, an unordered read passed through that index.
    $second = addFormField($version, $this->user, 'second_num', FieldType::Integer, 1);
    $first = addFormField($version, $this->user, 'first_num', FieldType::Integer, 0);

    // The second question's rule is saved before the first's. Each names no question to compare with.
    foreach ([$second, $first] as $owner) {
        FormFieldValidation::create([
            'form_version_id' => $version->id,
            'form_field_id' => $owner->id,
            'rule_type' => ValidationRuleType::GreaterThanField,
        ]);
    }

    expect(publishRefusals($version))->toBe([
        ['field' => 'first_num', 'code' => 'rule_missing_related_field'],
        ['field' => 'second_num', 'code' => 'rule_missing_related_field'],
    ]);
});
