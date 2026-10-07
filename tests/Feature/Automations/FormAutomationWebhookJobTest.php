<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\FormAutomationAction;
use App\Enums\FormAutomationRunStatus;
use App\Enums\PlanTier;
use App\Enums\RequiredMode;
use App\Enums\UsageMetric;
use App\Jobs\Automations\DeliverFormAutomationWebhookJob;
use App\Models\Form;
use App\Models\FormAutomation;
use App\Models\FormAutomationRun;
use App\Models\FormSection;
use App\Models\Plan;
use App\Models\Submission;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Automations\FormAutomationService;
use App\Services\Entitlements\EntitlementService;
use App\Services\Entitlements\UsageMeter;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Support\Submissions\SubmissionReference;
use App\Support\Tenancy\TenantContext;
use App\Support\Webhooks\WebhookSigner;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M132 — a web-address automation sends the answers (R-b7bc5149, D83).
|--------------------------------------------------------------------------
| The job is run directly (`handle()`), under the tenant context its base establishes; a `release()` outside a worker
| is a no-op, so a retried run is driven by calling it again. Addresses are literal IPs: the guard needs no DNS.
|
| ⚠️ Helpers are prefixed `automationHook*`: Pest loads every test file into one process.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Http::preventStrayRequests();

    $this->tenant = guestTenant();
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');
    $this->form = guestForm($this->tenant, $this->owner);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/**
 * A web-address automation, one submitted response, and the run the dispatcher made for it.
 *
 * @return array{0: FormAutomation, 1: FormAutomationRun, 2: string}
 */
function automationHookRun(Form $form, User $owner, string $url = 'https://8.8.8.8/hook'): array
{
    Queue::fake();
    [$automation, $secret] = app(FormAutomationService::class)->create($form, 'To the registry', FormAutomationAction::Webhook, null, $url, $owner);

    test()->postJson('http://acme.meridian.test/api/v1/public/f/'.shareTokenFor($form).'/submissions', [
        'answers' => ['full_name' => 'Ada Lovelace', 'age' => '36'],
    ])->assertCreated();
    enterTenant($form->tenant_id, $owner->id);

    return [$automation, $automation->runs()->sole(), (string) $secret];
}

function automationHookHandle(string $tenantId, FormAutomationRun $run): void
{
    (new DeliverFormAutomationWebhookJob($tenantId, $run->id))->handle();
    TenantContext::flush();
}

it('sends the answers keyed by question, with the reference, signed with the automation’s secret', function (): void {
    [, $run, $secret] = automationHookRun($this->form, $this->owner);
    $reference = SubmissionReference::format(Submission::query()->findOrFail($run->submission_id)->reference);
    Http::fake(['https://8.8.8.8/*' => Http::response('', 200)]);

    automationHookHandle($this->tenant->id, $run);

    Http::assertSentCount(1);
    Http::assertSent(function (ClientRequest $request) use ($secret, $run, $reference): bool {
        $timestamp = $request->header(WebhookSigner::TIMESTAMP_HEADER)[0];

        return $request->header(WebhookSigner::SIGNATURE_HEADER)[0] === 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->body(), $secret)
            && $request->header(WebhookSigner::EVENT_ID_HEADER)[0] === $run->event_id
            && $request['event_type'] === 'submission.created'
            && $request['answers'] === ['full_name' => 'Ada Lovelace', 'age' => '36']
            && $request['submission']['reference'] === $reference
            && $request['form']['title'] === 'Intake';
    });

    enterTenant($this->tenant->id, $this->owner->id);
    $run->refresh();
    expect($run->status)->toBe(FormAutomationRunStatus::Succeeded)
        ->and($run->attempt_count)->toBe(1)
        ->and($run->response_status)->toBe(200);
});

it('refuses a private address before the attempt, sending nothing, and says why', function (): void {
    [, $run] = automationHookRun($this->form, $this->owner, 'https://10.1.2.3/hook');
    Http::fake();

    automationHookHandle($this->tenant->id, $run);

    Http::assertNothingSent();
    enterTenant($this->tenant->id, $this->owner->id);
    expect($run->refresh()->error_code)->toBe('blocked_url')->and($run->status)->toBe(FormAutomationRunStatus::Retrying);
});

it('records each failed attempt, retries, and gives up after the fifth', function (): void {
    [, $run] = automationHookRun($this->form, $this->owner);
    Http::fake(['https://8.8.8.8/*' => Http::response('nope', 500)]);

    foreach (range(1, 4) as $attempt) {
        automationHookHandle($this->tenant->id, $run);
        enterTenant($this->tenant->id, $this->owner->id);
        expect($run->refresh()->status)->toBe(FormAutomationRunStatus::Retrying)->and($run->attempt_count)->toBe($attempt);
    }

    automationHookHandle($this->tenant->id, $run);
    enterTenant($this->tenant->id, $this->owner->id);
    expect($run->refresh()->status)->toBe(FormAutomationRunStatus::Failed)
        ->and($run->attempt_count)->toBe(5)
        ->and($run->error_code)->toBe('http_500');

    // A finished run is never sent again.
    automationHookHandle($this->tenant->id, $run);
    Http::assertSentCount(5);
});

it('never follows a redirect, which counts as a failed attempt', function (): void {
    [, $run] = automationHookRun($this->form, $this->owner);
    Http::fake(['https://8.8.8.8/*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/internal'])]);

    automationHookHandle($this->tenant->id, $run);

    Http::assertSentCount(1);
    enterTenant($this->tenant->id, $this->owner->id);
    expect($run->refresh()->error_code)->toBe('http_302');
});

it('skips a run once the month’s delivery quota is spent, sending nothing', function (): void {
    [, $run] = automationHookRun($this->form, $this->owner);
    // A quota of one, already used: a quota of 0 is the Free plan's no-access sentinel, which never caps.
    $plan = Plan::factory()->tier(PlanTier::Starter)->withQuotas([UsageMetric::WebhookDeliveries->value => 1])->create();
    Subscription::query()->delete();
    Subscription::factory()->forPlan($plan)->create();
    app(EntitlementService::class)->forget();
    app(UsageMeter::class)->increment(UsageMetric::WebhookDeliveries);
    Http::fake();

    automationHookHandle($this->tenant->id, $run);

    Http::assertNothingSent();
    enterTenant($this->tenant->id, $this->owner->id);
    expect($run->refresh()->status)->toBe(FormAutomationRunStatus::Skipped)->and($run->error_code)->toBe('quota_exceeded');
});

it('fails a run whose response no longer exists, sending nothing', function (): void {
    [, $run] = automationHookRun($this->form, $this->owner);
    Submission::query()->whereKey($run->submission_id)->forceDelete();
    Http::fake();

    automationHookHandle($this->tenant->id, $run);

    Http::assertNothingSent();
    enterTenant($this->tenant->id, $this->owner->id);
    expect($run->refresh()->status)->toBe(FormAutomationRunStatus::Failed)->and($run->error_code)->toBe('submission_missing');
});

// ── M146 (`R-6c0f0e56`, `D100` A) — a repeat group reaches the webhook as the export writes it ─────────────────

/** A guest form at `roster`: the required name, then a repeatable "Kids" section with one short-text member. */
function automationHookRepeatForm(Tenant $tenant, User $owner): Form
{
    $form = app(FormService::class)->create($tenant, $owner, 'Roster');
    $draft = $form->draftVersion;
    addFormField($draft, $owner, 'full_name', FieldType::ShortText, 0, ['is_required' => RequiredMode::Required]);
    $kids = FormSection::create([
        'form_version_id' => $draft->id, 'key' => 'kids', 'label' => 'Kids', 'sequence' => 1,
        'is_repeatable' => true, 'min_instances' => 0, 'max_instances' => 5,
    ]);
    addFormField($draft, $owner, 'kid_name', FieldType::ShortText, 2, ['form_section_id' => $kids->id]);
    app(PublishService::class)->publish($form->refresh(), $owner);
    $form->refresh()->update(['public_slug' => 'roster', 'allow_guest_submissions' => true]);

    return $form->refresh();
}

it('sends a repeat group’s instances joined into one value per member, keyed by the member and never by the section', function (): void {
    $form = automationHookRepeatForm($this->tenant, $this->owner);
    Queue::fake();
    [$automation] = app(FormAutomationService::class)->create($form, 'To the registry', FormAutomationAction::Webhook, null, 'https://8.8.8.8/hook', $this->owner);
    $this->postJson('http://acme.meridian.test/api/v1/public/f/'.shareTokenFor($form).'/submissions', [
        'answers' => ['full_name' => 'Ada Lovelace', 'kids' => [['kid_name' => 'Kid A'], ['kid_name' => 'Kid B']]],
    ])->assertCreated();
    enterTenant($this->tenant->id, $this->owner->id);
    $run = $automation->runs()->sole();
    Http::fake(['https://8.8.8.8/*' => Http::response('', 200)]);

    automationHookHandle($this->tenant->id, $run);

    Http::assertSentCount(1);
    Http::assertSent(fn (ClientRequest $request): bool => $request['answers'] === ['full_name' => 'Ada Lovelace', 'kid_name' => 'Kid A | Kid B']);
});
