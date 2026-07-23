<?php

declare(strict_types=1);

use App\Enums\PlanTier;
use App\Enums\UsageMetric;
use App\Exceptions\Entitlements\QuotaExceededException;
use App\Models\Form;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Hard-block forms_count (ADR-0008 §D4). The guard sits in FormService::create — the sole Form::create in
// the codebase — inside its transaction, so a refusal rolls back and the template-instantiate caller (whose
// outer transaction wraps this) is covered by the same choke point. Enforcement reads the LIVE non-archived
// count, so archiving frees a slot.

beforeEach(function (): void {
    TenantContext::flush();
    $this->tenant = inboxTenant('acme');
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    $this->forms = app(FormService::class);
});

it('blocks creating a form past the Free forms_count limit of 3', function (): void {
    assignPlanTier(PlanTier::Free); // forms_count = 3

    $this->forms->create($this->tenant, $this->owner, 'Form 1');
    $this->forms->create($this->tenant, $this->owner, 'Form 2');
    $this->forms->create($this->tenant, $this->owner, 'Form 3');

    expect(fn () => $this->forms->create($this->tenant, $this->owner, 'Form 4'))
        ->toThrow(QuotaExceededException::class);

    // The 4th create rolled back cleanly — no partial form/version/grant left behind.
    expect(Form::query()->count())->toBe(3);
});

it('does not count archived forms toward the limit', function (): void {
    assignPlanTier(PlanTier::Free); // 3

    $first = $this->forms->create($this->tenant, $this->owner, 'F1');
    $this->forms->create($this->tenant, $this->owner, 'F2');
    $this->forms->create($this->tenant, $this->owner, 'F3');

    $this->forms->archive($first); // frees a slot — the live gauge counts non-archived only

    $this->forms->create($this->tenant, $this->owner, 'F4'); // now allowed again

    expect(Form::query()->where('status', '!=', 'archived')->count())->toBe(3);
});

it('never blocks on an unlimited plan', function (): void {
    assignUnlimitedPlan(); // Enterprise — forms_count null

    foreach (range(1, 6) as $i) {
        $this->forms->create($this->tenant, $this->owner, "F{$i}");
    }

    expect(Form::query()->count())->toBe(6);
});

it('never blocks when no plan catalog is seeded (quota resolves to null)', function (): void {
    // No subscription AND no plans at all ⇒ currentPlan() is null ⇒ quota() is null ⇒ unlimited. This is
    // why the pre-H5b suite, which seeds no plans, is unaffected by enforcement.
    foreach (range(1, 5) as $i) {
        $this->forms->create($this->tenant, $this->owner, "F{$i}");
    }

    expect(Form::query()->count())->toBe(5);
});

it('carries a 402 status, a metric-specific code and limit/used details on the exception', function (): void {
    $plan = Plan::factory()->tier(PlanTier::Free)
        ->withQuotas([UsageMetric::FormsCount->value => 1])
        ->create();
    Subscription::factory()->forPlan($plan)->create();

    $this->forms->create($this->tenant, $this->owner, 'Only one');

    try {
        $this->forms->create($this->tenant, $this->owner, 'Second');
        expect()->fail('expected a QuotaExceededException');
    } catch (QuotaExceededException $e) {
        expect($e->status())->toBe(402)
            ->and($e->code())->toBe('quota_exceeded_forms_count')
            ->and($e->metric())->toBe(UsageMetric::FormsCount)
            ->and($e->limit())->toBe(1)
            ->and($e->used())->toBe(1)
            ->and($e->details())->toBe(['metric' => 'forms_count', 'limit' => 1, 'used' => 1]);
    }
});
