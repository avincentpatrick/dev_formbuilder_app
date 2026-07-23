<?php

declare(strict_types=1);

use App\Enums\PlanTier;
use App\Enums\SubmissionSource;
use App\Enums\UsageMetric;
use App\Models\FormVersion;
use App\Models\Plan;
use App\Models\Submission;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use App\Services\Entitlements\UsageMeter;
use App\Services\Submissions\SubmissionPayload;
use App\Services\Submissions\SubmissionPipeline;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// The never-block guarantee (ADR-0008 §D4): a respondent's completed submission is NEVER rejected over the
// tenant's billing status. It is metered (via the post-commit SubmissionCreated listener) and the tenant is
// upsold, but the data is always saved — even if the meter itself throws.

beforeEach(function (): void {
    TenantContext::flush();
    Notification::fake();
    $this->tenant = inboxTenant('acme');
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    $this->form = publishedInboxForm($this->tenant, $this->owner);
    $this->version = FormVersion::findOrFail($this->form->current_published_version_id);
    $this->pipeline = app(SubmissionPipeline::class);
});

/** Cap submissions_count to `$limit` while leaving every other quota unlimited. */
function h5bCapSubmissions(int $limit): void
{
    $plan = Plan::factory()->tier(PlanTier::Free)
        ->withQuotas([UsageMetric::SubmissionsCount->value => $limit])
        ->create();
    Subscription::factory()->forPlan($plan)->create();
    app(EntitlementService::class)->forget(); // refresh the scoped memo to the newly-assigned plan
}

function h5bSubmit(FormVersion $version, SubmissionSource $source, ?string $respondent, ?string $uuid = null): mixed
{
    return app(SubmissionPipeline::class)->submit(new SubmissionPayload(
        version: $version,
        answers: ['full_name' => 'Jane Doe'],
        source: $source,
        respondentUserId: $respondent,
        clientSubmissionUuid: $uuid,
    ));
}

it('accepts a manual submission far past the submissions_count quota, and meters it', function (): void {
    h5bCapSubmissions(1); // quota = 1

    foreach (range(1, 3) as $i) {
        $result = h5bSubmit($this->version, SubmissionSource::Manual, $this->owner->id);
        expect($result->created)->toBeTrue();
    }

    expect(Submission::query()->count())->toBe(3); // all three saved despite the quota of 1

    // Metered despite the overage (the counter reflects reality, it just does not gate).
    app(EntitlementService::class)->forget($this->tenant->id);
    expect(app(EntitlementService::class)->usage(UsageMetric::SubmissionsCount))->toBe(3);
});

it('accepts a GUEST submission over quota (a respondent has no control over the tenant plan)', function (): void {
    h5bCapSubmissions(1);

    $result = h5bSubmit($this->version, SubmissionSource::Guest, null);

    expect($result->created)->toBeTrue()
        ->and(Submission::query()->where('source', 'guest')->count())->toBe(1);
});

it('does not double-count an idempotent replay', function (): void {
    h5bCapSubmissions(100);
    $uuid = Str::uuid()->toString();

    $first = h5bSubmit($this->version, SubmissionSource::Manual, $this->owner->id, $uuid);
    $replay = h5bSubmit($this->version, SubmissionSource::Manual, $this->owner->id, $uuid);

    expect($first->created)->toBeTrue()
        ->and($replay->created)->toBeFalse() // idempotent no-op — no new event, no new meter
        ->and(Submission::query()->count())->toBe(1);

    app(EntitlementService::class)->forget($this->tenant->id);
    expect(app(EntitlementService::class)->usage(UsageMetric::SubmissionsCount))->toBe(1);
});

it('persists the submission even when the meter throws (data commits before the post-commit event)', function (): void {
    // A broken meter must not lose a respondent's data — the row is committed before SubmissionCreated fires.
    app()->instance(UsageMeter::class, new class extends UsageMeter
    {
        public function increment(UsageMetric $metric, int $by = 1): void
        {
            throw new RuntimeException('meter boom');
        }
    });

    try {
        h5bSubmit($this->version, SubmissionSource::Guest, null);
    } catch (Throwable) {
        // A post-commit throw may surface to the caller; the guarantee under test is data preservation.
    }

    expect(Submission::query()->count())->toBe(1); // the respondent's submission survived
});
