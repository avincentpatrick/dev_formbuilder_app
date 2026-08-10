<?php

declare(strict_types=1);

use App\Enums\DomainEventType;
use App\Enums\FeedbackStatus;
use App\Enums\SubmissionStatus;
use App\Enums\WebhookEndpointStatus;
use App\Models\FeedbackReport;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| `?q` on the webhooks, submissions and feedback lists (Increment J1e).
|
| The forms list, the members roster and the audit ledger each earned a file of their own — they carry the
| recon's four hazards, J1c's connection rule and the redaction channel respectively. These three share one
| file because they share one shape, and what is worth asserting is that the shape is applied WHOLE on each:
|
|   `q` narrows · the CLAMPED query is echoed into `filters.applied.q` · `empty_reason` distinguishes
|   "filtered to zero" from "nothing here yet" · a hostile or oversized query degrades instead of refusing.
|
| ⚠️ THE `empty_reason` HALF IS THE ONE THAT MATTERS MOST AND LOOKS LEAST IMPORTANT. Three of these six
| pages shipped an unconditional first-run empty state, so the first `?q` matching nothing would have told
| an established tenant it had never created anything. A `has('data', 0)` assertion cannot see that; the
| prop the page branches on is what has to be asserted.
|
| ⚠️ AND THE INBOX'S `no_rows` CASE PINS A DELIBERATE NON-ANSWER. `countable()` hides in-progress drafts as
| a display DEFAULT, and it deliberately does NOT count as a filter — a reviewer who narrowed nothing has
| genuinely received no completed responses, and the hint above the table is where "drafts are hidden"
| belongs. Asserting it here stops a later reader "fixing" that into a filtered empty state.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run();

    $this->tenant = inboxTenant();
    $this->owner = User::factory()->create(['name' => 'Rosa Delgado']);
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');
    $this->actingAs($this->owner)->withoutVite();
});

afterEach(fn () => app(PermissionRegistrar::class)->setPermissionsTeamId(null));

function listUrl(string $path, string $query = ''): string
{
    return 'http://acme.meridian.test'.$path.($query === '' ? '' : '?q='.urlencode($query));
}

/** Every hostile shape one box has to survive without a 422 or a 500. */
function hostileKeywords(): array
{
    return ['&', 'foo &', "'; drop--", '%%%', 'a_b', '(x', '   ', str_repeat('q', 300)];
}

// ── webhooks ──────────────────────────────────────────────────────────────────────────────────────────

it('narrows the webhooks list on name and URL, and refuses to search the Scope column', function (): void {
    WebhookEndpoint::factory()->create([
        'name' => 'Zapier relay',
        'url' => 'https://8.8.8.8/hooks/zap',
        'event_types' => [DomainEventType::SubmissionCreated->value],
        'status' => WebhookEndpointStatus::Active,
    ]);
    WebhookEndpoint::factory()->create([
        'name' => 'CRM sync',
        'url' => 'https://8.8.4.4/inbound/crm',
        'event_types' => [DomainEventType::SubmissionCreated->value],
        'status' => WebhookEndpointStatus::Active,
    ]);

    $this->get(listUrl('/webhooks', 'zapier'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('webhooks/Index', false)
            ->has('data', 1)
            ->where('data.0.name', 'Zapier relay')
            ->where('filters.applied.q', 'zapier')
            ->where('empty_reason', null));

    // The URL branch — a fragment of the host, not the name.
    $this->get(listUrl('/webhooks', 'inbound'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('data', 1)->where('data.0.name', 'CRM sync'));
});

it('tells a webhooks list filtered to zero that it was filtered, not that it is empty', function (): void {
    WebhookEndpoint::factory()->create([
        'name' => 'Zapier relay',
        'url' => 'https://8.8.8.8/hooks/zap',
        'event_types' => [DomainEventType::SubmissionCreated->value],
        'status' => WebhookEndpointStatus::Active,
    ]);

    $this->get(listUrl('/webhooks', 'zzzznothing'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('empty_reason', 'no_matches')->has('data', 0));

    $this->get(listUrl('/webhooks'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('empty_reason', null)->has('data', 1));
});

it('reports no_rows on a webhooks list that genuinely has nothing', function (): void {
    $this->get(listUrl('/webhooks'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('empty_reason', 'no_rows')->has('data', 0));
});

// ── submissions ───────────────────────────────────────────────────────────────────────────────────────

it('narrows the inbox on the parent form’s title', function (): void {
    $clinic = publishedInboxForm($this->tenant, $this->owner, 'Clinic Intake');
    $household = publishedInboxForm($this->tenant, $this->owner, 'Household Survey');
    seedInboxSubmission($clinic, $this->owner, SubmissionStatus::Submitted, ['full_name' => 'Ada']);
    seedInboxSubmission($household, $this->owner, SubmissionStatus::Submitted, ['full_name' => 'Grace']);

    $this->get(listUrl('/submissions', 'clinic'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('submissions/Inbox', false)
            ->has('data', 1)
            ->where('data.0.form_title', 'Clinic Intake')
            ->where('meta.total', 1)
            ->where('filters.applied.q', 'clinic')
            ->where('empty_reason', null));
});

it('narrows the inbox on a submission REFERENCE, and records what the 8-character one cannot do', function (): void {
    $clinic = publishedInboxForm($this->tenant, $this->owner, 'Clinic Intake');
    $one = seedInboxSubmission($clinic, $this->owner, SubmissionStatus::Submitted, ['full_name' => 'Ada']);
    $two = seedInboxSubmission($clinic, $this->owner, SubmissionStatus::Submitted, ['full_name' => 'Grace']);

    // A FULL reference is an exact lookup, which is the path that actually works.
    $this->get(listUrl('/submissions', (string) $one->id))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('data', 1)->where('data.0.id', $one->id));

    // ⚠️ AND THE EIGHT-CHARACTER ONE IS NOT A LOOKUP UNDER uuidv7 — MEASURED HERE, NOT ARGUED.
    // `SearchTerms::MIN_UUID_PREFIX`'s docblock justified 8 on the grounds that anything shorter "would
    // match a meaningful fraction of any tenant's submissions and stop being a lookup". That reasoning
    // holds for a RANDOM uuid and fails for a uuidv7: the first 8 hex characters are the top 32 bits of a
    // 48-bit millisecond timestamp, so they are IDENTICAL for every row created in the same ~49-day
    // window. Two submissions seeded milliseconds apart share them, which is what this asserts.
    //
    // It is not a disclosure — the rows are still bounded by `visibleTo` — it is the reference lookup not
    // being one. The constant is deliberately NOT raised here: the inbox and the palette both DISPLAY
    // exactly 8 characters, and `SubmissionSearchArm`'s contract is that what is shown can be pasted back
    // in, so raising it alone would make the displayed reference unusable. The fix is the real short handle
    // that scope already files for J2. This case goes red the day either half changes, which is the day
    // someone should re-read all of it.
    expect(substr((string) $one->id, 0, 8))->toBe(substr((string) $two->id, 0, 8));

    $this->get(listUrl('/submissions', substr((string) $one->id, 0, 8)))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('data', 2));
});

it('ANDs the keyword with the status filter rather than replacing it', function (): void {
    $clinic = publishedInboxForm($this->tenant, $this->owner, 'Clinic Intake');
    seedInboxSubmission($clinic, $this->owner, SubmissionStatus::Submitted, ['full_name' => 'Ada']);

    // A keyword that matches AND a status that does not is legitimately zero. Replace-instead-of-AND in
    // either direction returns the row.
    $this->get('http://acme.meridian.test/submissions?q=clinic&status='.SubmissionStatus::Returned->value)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('data', 0)->where('empty_reason', 'no_matches'));
});

it('calls a draft-only inbox no_rows, because countable() is a display default and not a filter', function (): void {
    $clinic = publishedInboxForm($this->tenant, $this->owner, 'Clinic Intake');
    seedInboxSubmission($clinic, $this->owner, SubmissionStatus::Draft, ['full_name' => 'Ada']);

    // The reviewer narrowed nothing and has no completed responses — "nothing here yet" is the true answer,
    // and the hint above the table is where "drafts are hidden, choose Draft" belongs.
    $this->get(listUrl('/submissions'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('data', 0)->where('empty_reason', 'no_rows'));
});

// ── feedback ──────────────────────────────────────────────────────────────────────────────────────────

it('narrows the feedback list on remarks and on the page route', function (): void {
    FeedbackReport::create([
        'user_id' => $this->owner->id,
        'route' => '/forms/builder',
        'remarks' => 'The publish button is hard to find',
        'status' => FeedbackStatus::New,
        'browser_info' => [],
        'submitted_at' => now(),
    ]);
    FeedbackReport::create([
        'user_id' => $this->owner->id,
        'route' => '/analytics',
        'remarks' => 'Charts render slowly on mobile',
        'status' => FeedbackStatus::New,
        'browser_info' => [],
        'submitted_at' => now(),
    ]);

    $this->get(listUrl('/feedback', 'publish'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('feedback/Index', false)
            ->has('data', 1)
            ->where('data.0.route', '/forms/builder')
            ->where('filters.applied.q', 'publish')
            ->where('empty_reason', null));

    // The route branch.
    $this->get(listUrl('/feedback', 'analytics'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('data', 1)->where('data.0.route', '/analytics'));
});

it('ANDs a feedback keyword with the status filter', function (): void {
    FeedbackReport::create([
        'user_id' => $this->owner->id,
        'route' => '/forms/builder',
        'remarks' => 'The publish button is hard to find',
        'status' => FeedbackStatus::New,
        'browser_info' => [],
        'submitted_at' => now(),
    ]);

    $this->get('http://acme.meridian.test/feedback?q=publish&status='.FeedbackStatus::Resolved->value)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('data', 0)->where('empty_reason', 'no_matches'));
});

// ── the shape, applied whole ──────────────────────────────────────────────────────────────────────────

it('degrades rather than refusing a hostile query on every one of the three', function (): void {
    // A 422 on an Inertia GET redirects "back" — nowhere on a cold visit — so every bound in this feature
    // lives in `SearchTerms::parse()`. The ampersand is the one that raises 42601 if it ever reaches a real
    // `to_tsquery`, which the inbox's form-title branch does.
    foreach (['/webhooks', '/submissions', '/feedback'] as $path) {
        foreach (hostileKeywords() as $hostile) {
            $this->get(listUrl($path, $hostile))->assertOk();
        }

        // `?q[]=x` reaches `mb_substr()` as an ARRAY without the `is_string()` guard.
        $this->get('http://acme.meridian.test'.$path.'?q[]=x')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('filters.applied.q', null));
    }
});

it('echoes the CLAMPED query on every one of the three, never the raw input', function (): void {
    foreach (['/webhooks', '/submissions', '/feedback'] as $path) {
        $this->get(listUrl($path, str_repeat('x', 300)))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('filters.applied.q', str_repeat('x', 200)));
    }
});
