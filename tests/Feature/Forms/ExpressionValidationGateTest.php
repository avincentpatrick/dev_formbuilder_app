<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\FormVersionStatus;
use App\Enums\ValidationRuleType;
use App\Exceptions\Forms\PublishValidationException;
use App\Models\FormFieldValidation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\ExpressionValidationGate;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
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
    $this->gate = app(ExpressionValidationGate::class);
});

it('passes a draft whose expressions and structured rules all resolve', function (): void {
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    $draft = $form->draftVersion;
    addFormField($draft, $this->user, 'age', FieldType::Integer);
    addFormField($draft, $this->user, 'note', FieldType::ShortText, 1, ['relevant_expression' => '${age} > 17']);
    FormFieldValidation::create([
        'form_version_id' => $draft->id,
        'form_field_id' => $draft->fields()->where('key', 'age')->value('id'),
        'rule_type' => ValidationRuleType::MinValue,
        'rule_value' => '0',
    ]);

    $this->gate->assertExpressionsResolve($draft->refresh());
    $published = $this->publisher->publish($form->refresh(), $this->user);

    expect($published->status)->toBe(FormVersionStatus::Published);
});

it('rejects a relevant_expression referencing an unknown field, naming the field', function (): void {
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    addFormField($form->draftVersion, $this->user, 'visible', FieldType::ShortText, 0, [
        'relevant_expression' => '${ghost} = \'1\'',
    ]);

    expect(fn () => $this->publisher->publish($form->refresh(), $this->user))
        ->toThrow(PublishValidationException::class, 'visible');
});

it('rejects an unparseable constraint expression at publish', function (): void {
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    $draft = $form->draftVersion;
    $age = addFormField($draft, $this->user, 'age', FieldType::Integer);
    FormFieldValidation::create([
        'form_version_id' => $draft->id,
        'form_field_id' => $age->id,
        'expression' => '${age} =',
    ]);

    expect(fn () => $this->publisher->publish($form->refresh(), $this->user))
        ->toThrow(PublishValidationException::class, 'age');
});

it('rejects an uncompilable pattern rule_value at publish', function (): void {
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    $draft = $form->draftVersion;
    $code = addFormField($draft, $this->user, 'code', FieldType::ShortText);
    FormFieldValidation::create([
        'form_version_id' => $draft->id,
        'form_field_id' => $code->id,
        'rule_type' => ValidationRuleType::Pattern,
        'rule_value' => '(',
    ]);

    expect(fn () => $this->publisher->publish($form->refresh(), $this->user))
        ->toThrow(PublishValidationException::class, 'invalid_pattern');
});

it('rejects a non-numeric min_value threshold at publish', function (): void {
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    $draft = $form->draftVersion;
    $age = addFormField($draft, $this->user, 'age', FieldType::Integer);
    FormFieldValidation::create([
        'form_version_id' => $draft->id,
        'form_field_id' => $age->id,
        'rule_type' => ValidationRuleType::MinValue,
        'rule_value' => 'eighteen',
    ]);

    expect(fn () => $this->publisher->publish($form->refresh(), $this->user))
        ->toThrow(PublishValidationException::class, 'non_numeric_threshold');
});
