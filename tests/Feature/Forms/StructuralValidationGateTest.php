<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\IndexedDataType;
use App\Exceptions\Forms\PublishValidationException;
use App\Models\FormSection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\StructuralValidationGate;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantContext::flush();
    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $this->user = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);
    $this->gate = new StructuralValidationGate;
});

it('passes a structurally valid draft', function (): void {
    $version = makeDraftVersion(makeForm($this->user));
    addFormField($version, $this->user, 'name');
    addFormField($version, $this->user, 'age', FieldType::Integer, 1, [
        'is_queryable' => true,
        'indexed_data_type' => IndexedDataType::Number,
    ]);

    $this->gate->assertPublishable($version->refresh());

    expect(true)->toBeTrue(); // reached here without throwing
});

it('rejects a queryable field with no indexed data type, naming the field', function (): void {
    $version = makeDraftVersion(makeForm($this->user));
    addFormField($version, $this->user, 'age', FieldType::Integer, 0, ['is_queryable' => true]);

    expect(fn () => $this->gate->assertPublishable($version->refresh()))
        ->toThrow(PublishValidationException::class, 'age');
});

it('rejects a field whose section belongs to a different version', function (): void {
    // A section that lives on ANOTHER version …
    $otherVersion = makeDraftVersion(makeForm($this->user));
    $foreignSection = FormSection::create([
        'form_version_id' => $otherVersion->id,
        'key' => 'demographics',
        'label' => 'Demographics',
    ]);

    // … referenced by a field on THIS version.
    $version = makeDraftVersion(makeForm($this->user));
    addFormField($version, $this->user, 'name', FieldType::ShortText, 0, [
        'form_section_id' => $foreignSection->id,
    ]);

    expect(fn () => $this->gate->assertPublishable($version->refresh()))
        ->toThrow(PublishValidationException::class, 'name');
});
