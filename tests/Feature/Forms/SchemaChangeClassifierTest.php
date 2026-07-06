<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\RequiredMode;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\SchemaChangeClassifier;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantContext::flush();
    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $this->user = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);
    $this->classifier = new SchemaChangeClassifier;
    $this->form = makeForm($this->user);
});

it('classifies a first publish as the initial version', function (): void {
    $draft = makeDraftVersion($this->form, 1);
    addFormField($draft, $this->user, 'name');

    $result = $this->classifier->classify($draft->refresh(), null);

    expect($result->isFirstPublish)->toBeTrue()
        ->and($result->hasBreakingChanges())->toBeFalse()
        ->and($result->changeSummary())->toBe('Initial version.');
});

it('detects added, removed, and breaking field changes by key', function (): void {
    // Prior version: name, age (integer), email.
    $prev = makeDraftVersion($this->form, 1);
    addFormField($prev, $this->user, 'name');
    addFormField($prev, $this->user, 'age', FieldType::Integer);
    addFormField($prev, $this->user, 'email', FieldType::Email);

    // New draft: name (unchanged), age now short_text (BREAKING), email removed, phone added.
    $draft = makeDraftVersion($this->form, 2);
    addFormField($draft, $this->user, 'name');
    addFormField($draft, $this->user, 'age', FieldType::ShortText);
    addFormField($draft, $this->user, 'phone', FieldType::Phone);

    $result = $this->classifier->classify($draft->refresh(), $prev->refresh());

    expect($result->addedFieldKeys)->toBe(['phone'])
        ->and($result->removedFieldKeys)->toBe(['email'])
        ->and($result->hasBreakingChanges())->toBeTrue()
        ->and($result->breakingChanges)->toHaveCount(1)
        ->and($result->breakingChanges[0]['key'])->toBe('age');

    $summary = $result->changeSummary();
    expect($summary)->toContain('Added field: phone')
        ->and($summary)->toContain('Removed field: email')
        ->and($summary)->toContain('Changed field age: type integer → short_text');
});

it('treats making an optional field required as a breaking change', function (): void {
    $prev = makeDraftVersion($this->form, 1);
    addFormField($prev, $this->user, 'age', FieldType::Integer, 0, ['is_required' => RequiredMode::Optional]);

    $draft = makeDraftVersion($this->form, 2);
    addFormField($draft, $this->user, 'age', FieldType::Integer, 0, ['is_required' => RequiredMode::Required]);

    $result = $this->classifier->classify($draft->refresh(), $prev->refresh());

    expect($result->hasBreakingChanges())->toBeTrue()
        ->and($result->breakingChanges[0]['change'])->toBe('became required');
});
