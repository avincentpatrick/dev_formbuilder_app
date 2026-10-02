<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\FieldVariantGroup;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormBuilderService;
use App\Services\Forms\FormService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M125 — the palette's variant groups (R-a367bf9e).
|--------------------------------------------------------------------------
| The palette shows one "Text" and one "Number", and the Basics tab switches a field between the members of
| its group with no confirmation dialog. That is honest only while every member converts to every sibling
| LOSSLESSLY and WITHOUT CONFIRMATION — so this file asserts it for every member the enum groups, through the
| real path the switch takes: a field added from the palette (the service's own default config and shipped
| rules), planned by `FormBuilderService::conversionPlans()`.
|
| ⛔ The membership is ALSO written out, because the second test is derived from the enum and would pass
| happily over a group that silently lost a member.
*/

beforeEach(function (): void {
    TenantContext::flush();
    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $this->user = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);

    $this->form = app(FormService::class)->create($this->tenant, $this->user, 'Survey')->refresh();
    $this->builder = app(FormBuilderService::class);
});

it('groups exactly short and long text, and whole and decimal numbers, each behind a member primary', function (): void {
    expect(array_map(fn (FieldType $t): string => $t->value, FieldVariantGroup::Text->members()))->toBe(['short_text', 'long_text'])
        ->and(array_map(fn (FieldType $t): string => $t->value, FieldVariantGroup::Number->members()))->toBe(['integer', 'decimal'])
        ->and(FieldVariantGroup::Text->primary())->toBe(FieldType::ShortText)
        ->and(FieldVariantGroup::Number->primary())->toBe(FieldType::Integer)
        ->and(FieldVariantGroup::Text->label())->toBe('Text')
        ->and(FieldVariantGroup::Number->label())->toBe('Number');

    $ungrouped = array_filter(FieldType::cases(), fn (FieldType $t): bool => $t->variantGroup() === null);
    expect($ungrouped)->toHaveCount(count(FieldType::cases()) - 4);
});

it('converts a palette-added field to every sibling losslessly and with no confirmation', function (FieldVariantGroup $group): void {
    foreach ($group->members() as $from) {
        $field = $this->builder->addField($this->form, $this->user, $from, null);
        $plans = $this->builder->conversionPlans($this->form, $field);

        foreach ($group->members() as $to) {
            if ($to === $from) {
                continue;
            }

            $matching = array_values(array_filter($plans, fn ($plan): bool => $plan->to === $to));
            expect($matching)->toHaveCount(1, "{$from->value} offers no plan to {$to->value}");

            $plan = $matching[0];
            expect($plan->lossless())->toBeTrue("{$from->value} → {$to->value} loses something")
                ->and($plan->requiresConfirmation())->toBeFalse("{$from->value} → {$to->value} needs confirmation")
                ->and($plan->added)->toBe([], "{$from->value} → {$to->value} adds a shipped rule");
        }
    }
})->with([
    'Text' => [FieldVariantGroup::Text],
    'Number' => [FieldVariantGroup::Number],
]);
