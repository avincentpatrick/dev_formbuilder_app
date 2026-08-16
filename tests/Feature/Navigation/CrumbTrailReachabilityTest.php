<?php

declare(strict_types=1);

use App\Enums\PlanTier;
use App\Enums\SubmissionStatus;
use App\Models\Connection;
use App\Models\ConnectionSubscription;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment J2d — EVERY CRUMB THE TRAIL OFFERS MUST LEAD SOMEWHERE.
|
| The twin of `FormTabSetReachabilityTest`, which J2b built for the tab strip and whose absence on the TRAIL
| is exactly how J2c shipped a `Forms` crumb that 403s for a Reviewer and a Viewer. `rbac §9` files this test
| by name.
|
| ⚠️ WHAT MAKES THIS A PROOF RATHER THAN A TRANSCRIPTION: the trail is read back OFF THE REAL INERTIA
| RESPONSE and then navigated. Nothing here restates a URL the page is supposed to emit — a test that spelled
| the expected href would pass against a page emitting that same wrong href, which is precisely the failure
| mode of the client `computed` this increment deleted. The only strings below are LABELS and page names.
|
| ⚠️ TWO REQUESTS PER CASE AND NO WRITES BETWEEN THEM. `FormHubGateTest` records that a request tears the
| tenant GUC down on the way out, so a WRITE issued afterwards runs with no tenant context and RLS refuses
| it. Reads are unaffected; every fixture is built in `beforeEach`, before either request.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = inboxTenant();
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');

    $this->form = publishedInboxForm($this->tenant, $this->owner, 'Clinic Intake');
    $this->submission = seedInboxSubmission($this->form, $this->owner, SubmissionStatus::Submitted, ['full_name' => 'Ada']);
    $this->draft = seedInboxSubmission($this->form, $this->owner, SubmissionStatus::Draft, ['full_name' => 'Grace']);

    // ⚠️ THE PLAN IS PART OF THE FIXTURE, NOT SCAFFOLDING. The two roots added in J4b2 ask a plan
    // FEATURE as well as a policy, and a tenant with no subscription answers false to every feature —
    // so without this the webhook and rule trails would be label-only and the navigation cases below
    // would fail for a reason that has nothing to do with reachability. Starter carries both.
    assignPlanTier(PlanTier::Starter);

    $this->endpoint = WebhookEndpoint::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Ops relay',
    ]);

    $connection = Connection::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->rule = ConnectionSubscription::factory()->create([
        'tenant_id' => $this->tenant->id,
        'connection_id' => $connection->id,
        'name' => 'Intake to Slack',
    ]);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** Every page that renders a trail, by the key the datasets below use. */
function trailPageUrl(string $page, object $ctx): string
{
    $base = 'http://acme.meridian.test';

    return $base.match ($page) {
        'hub' => '/forms/'.$ctx->form->id,
        'builder' => '/forms/'.$ctx->form->id.'/builder',
        'analytics' => '/forms/'.$ctx->form->id.'/analytics',
        'responses' => '/forms/'.$ctx->form->id.'/submissions',
        'detail' => '/submissions/'.$ctx->submission->id,
        'encode' => '/forms/'.$ctx->form->id.'/submissions/create',
        'resume' => '/submissions/'.$ctx->draft->id.'/resume',
        'edit' => '/submissions/'.$ctx->submission->id.'/edit',
        'webhook' => '/webhooks/'.$ctx->endpoint->id,
        'rule' => '/integrations/rules/'.$ctx->rule->id,
    };
}

/**
 * The props of a real Inertia response.
 *
 * ⚠️ A CLOSURE ON `$this`, NOT A GLOBAL `function` LIKE `trailPageUrl()` ABOVE. `withoutVite()` and
 * `actingAs()` are PROTECTED on `Illuminate\Foundation\Testing\TestCase`, so a top-level helper receiving
 * the test case as a parameter fatals with "Call to protected method … from global scope" — pure URL
 * arithmetic can be extracted, a request cannot.
 */
$props = function (string $page): array {
    /** @var TestCase $this */
    return $this->withoutVite()
        ->actingAs($this->owner)
        ->get(trailPageUrl($page, $this))
        ->assertSuccessful()
        ->viewData('page')['props'];
};

it('renders the trail every page is supposed to have, in the expected shape', function (string $page, array $labels) use ($props): void {
    /*
     * The analogue of `FormTabSetReachabilityTest`'s key-set case, and it does three jobs the navigation
     * dataset below cannot. It keeps that dataset honest — add a crumb and this reddens, which is the prompt
     * to add its reachability row rather than discovering the omission in a browser. It proves every page
     * actually emits the key, since a controller that forgot it fails here rather than silently rendering an
     * empty trail. And it is the ONLY place the never-link-the-tail rule is observable at all:
     * `MdsBreadcrumb` renders the last item as text whatever it carries, so an href leaking onto the tail
     * changes nothing in the browser, in Vitest or in axe.
     */
    $crumbs = $props->call($this, $page)['crumbs'];

    expect(array_column($crumbs, 'label'))->toBe($labels)
        ->and(array_key_exists('href', $crumbs[count($crumbs) - 1]))->toBeFalse();
})->with([
    'hub' => ['hub', ['Forms', 'Clinic Intake']],
    'builder' => ['builder', ['Forms', 'Clinic Intake', 'Builder']],
    'analytics' => ['analytics', ['Forms', 'Clinic Intake', 'Response statistics']],
    'responses' => ['responses', ['Forms', 'Clinic Intake', 'Responses']],
    'detail' => ['detail', ['Forms', 'Clinic Intake', 'Responses', 'Response']],
    'encode' => ['encode', ['Forms', 'Clinic Intake', 'New response']],
    'resume' => ['resume', ['Forms', 'Clinic Intake', 'Continue response']],
    'edit' => ['edit', ['Forms', 'Clinic Intake', 'Responses', 'Response', 'Edit answers']],
    // ⚠️ TWO CRUMBS, NOT THREE, AND THE MISSING ONE IS DELIBERATE. Both pages receive a server-resolved
    // `form_url`, so a `Webhooks / {form} / {endpoint}` trail is expressible — and it would be a lie: a
    // breadcrumb is a PATH, and there is no `/webhooks/{form}` route to stand on. That is the same
    // error as treating `scopes/Index.vue`'s selected-node ancestry as a page trail. A rule's
    // connection has no detail route at all, so it has no middle crumb available even in principle.
    'webhook' => ['webhook', ['Webhooks', 'Ops relay']],
    'rule' => ['rule', ['Integrations', 'Intake to Slack']],
]);

it('offers a crumb whose href resolves to a real page', function (string $page, int $index) use ($props): void {
    /*
     * ⚠️ THE ANTI-VACUITY GUARD IS THE FIRST ASSERTION, NOT AN AFTERTHOUGHT. Without it, a `CrumbTrail` that
     * emitted no hrefs at all would skip every navigation below and the entire dataset would pass green —
     * the same species of vacuous test J2c found in its own increment (`clearFilters?.()` no-opping because
     * `<script setup>` exposes nothing without `defineExpose`).
     *
     * `assertSuccessful()` rather than a 200-or-redirect tolerance, for `FormTabSetReachabilityTest`'s
     * reason: the two failures this catches — 404 (the destination does not exist) and 403 (offered to
     * someone the route refuses) — are the two ways a trail lies, and both were live on `submissions/Show`.
     */
    $crumbs = $props->call($this, $page)['crumbs'];

    expect($crumbs[$index] ?? null)->not->toBeNull()
        ->and($crumbs[$index])->toHaveKey('href');

    $this->withoutVite()
        ->actingAs($this->owner)
        ->get('http://acme.meridian.test'.$crumbs[$index]['href'])
        ->assertSuccessful();
})->with([
    'hub/Forms' => ['hub', 0],
    'builder/Forms' => ['builder', 0],
    'builder/form' => ['builder', 1],
    'analytics/Forms' => ['analytics', 0],
    'analytics/form' => ['analytics', 1],
    'responses/Forms' => ['responses', 0],
    'responses/form' => ['responses', 1],
    'detail/Forms' => ['detail', 0],
    'detail/form' => ['detail', 1],
    'detail/responses' => ['detail', 2],
    'encode/Forms' => ['encode', 0],
    'encode/form' => ['encode', 1],
    'resume/Forms' => ['resume', 0],
    'resume/form' => ['resume', 1],
    'edit/Forms' => ['edit', 0],
    'edit/form' => ['edit', 1],
    'edit/responses' => ['edit', 2],
    'edit/submission' => ['edit', 3],
    'webhook/Webhooks' => ['webhook', 0],
    'rule/Integrations' => ['rule', 0],
]);

it('points Cancel at the crumb before the tail, on every encode mode', function (string $page) use ($props): void {
    /*
     * ⚠️ THIS TURNS A DOCBLOCK INTO A MEASUREMENT. `Encode.vue` spelled Cancel's destination TWICE (header
     * and sticky footer) beside a trail that spelled it a third time, with a comment asking future authors
     * to move all three together — and that comment had itself already been wrong once, claiming the TAIL
     * was the destination when it is the crumb before it.
     *
     * Reading both off the same response is what makes them unable to drift: `cancel_url` is not compared to
     * a literal, it is compared to the trail the same request emitted.
     */
    $page = $props->call($this, $page);
    $crumbs = $page['crumbs'];

    expect($page['cancel_url'])->toBe($crumbs[count($crumbs) - 2]['href'] ?? null)
        ->and($page['cancel_url'])->not->toBeNull();
})->with(['encode', 'resume', 'edit']);

it('gives the global submissions inbox no trail at all', function (): void {
    /*
     * A root page is not a page whose trail is empty — it is a page with no trail, and the difference is
     * carried in the payload rather than in a component's `v-if`. `SubmissionInboxController::index()`
     * emits no `crumbs` key, which is what keeps `inbox.test.ts`'s "renders no Breadcrumb and no TabNav"
     * case meaningful and stops a one-crumb trail appearing there the day someone makes the prop required.
     */
    $props = $this->withoutVite()
        ->actingAs($this->owner)
        ->get('http://acme.meridian.test/submissions')
        ->assertSuccessful()
        ->viewData('page')['props'];

    expect($props)->not->toHaveKey('crumbs');
});
