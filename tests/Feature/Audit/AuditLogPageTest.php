<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Models\Audit;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Support\Audit\AuditLogger;
use App\Support\Audit\AuditRedactor;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The audit-log viewer (Increment I2, PRD Feature #12) — the first surface that lets a human READ the
| ledger. Props are asserted KEY BY KEY, never `has('data')`: a partial assertion passes against a prop
| that has silently lost half its shape, which on this page means a compliance record that quietly stopped
| showing who did something.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = inboxTenant();
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');
    $this->actingAs($this->owner);
});

afterEach(fn () => app(PermissionRegistrar::class)->setPermissionsTeamId(null));

function auditPageUrl(string $query = ''): string
{
    return 'http://acme.meridian.test/audit-log'.($query === '' ? '' : '?'.$query);
}

it('renders every prop the page binds to, key by key', function (): void {
    $this->withoutVite();

    $form = app(FormService::class)->create($this->tenant, $this->owner, 'Clinic Intake');

    $this->get(auditPageUrl())->assertOk()->assertInertia(fn ($page) => $page
        ->component('audit/Index', false)
        ->has('data', 1)
        ->where('data.0.event', 'created')
        ->where('data.0.event_label', 'Created')
        ->where('data.0.actor', $this->owner->name)
        ->where('data.0.is_system', false)
        ->where('data.0.target.type', 'form')
        ->where('data.0.target.type_label', 'Form')
        ->where('data.0.target.id', $form->id)
        // Resolved server-side: only the server knows whether the target still exists, and half the
        // aliases have no addressable page at all.
        ->where('data.0.target.label', 'Clinic Intake')
        // J2d — the FORM'S OWN HUB, not the forms index. The old value was `/forms`, justified by a comment
        // saying `/forms/{form}` was the builder; J2b made `/forms/{form}` the hub, gated on `viewOverview`,
        // which every reader of this ledger (Owner/Admin only) holds. A soft-deleted target keeps its label
        // and loses its url — `AuditLogPresenter` resolves the two with separate queries for that reason.
        ->where('data.0.target.url', '/forms/'.$form->id)
        ->where('data.0.changes.0.key', 'title')
        ->where('data.0.changes.0.new', 'Clinic Intake')
        ->where('data.0.changes.0.redacted', false)
        ->where('data.0.redacted_fields', [])
        ->has('data.0.created_at')
        ->has('data.0.ip_address')
        ->where('meta.current_page', 1)
        ->where('meta.total', 1)
        ->where('meta.per_page', 25)
        ->has('meta.last_page')
        ->has('filters.events', count(AuditEvent::cases()))
        ->where('filters.events.0.value', 'created')
        ->where('filters.events.0.label', 'Created')
        ->has('filters.auditable_types')
        ->has('filters.actors', 1)
        ->where('filters.actors.0.value', $this->owner->id)
        ->where('filters.applied.event', null)
        ->where('filters.applied.auditable_type', null)
        ->where('filters.applied.user_id', null)
        ->where('filters.applied.from', null)
        ->where('filters.applied.to', null)
        ->where('empty_reason', null)
        ->where('can.export', true)
    );
});

it('narrows on each filter and echoes back what it applied', function (): void {
    $this->withoutVite();

    $form = app(FormService::class)->create($this->tenant, $this->owner, 'Survey');
    app(FormService::class)->archive($form, $this->owner);

    // Two rows exist (created + archived); the filter selects one.
    $this->get(auditPageUrl('event=archived'))->assertOk()->assertInertia(fn ($page) => $page
        ->has('data', 1)
        ->where('data.0.event', 'archived')
        ->where('filters.applied.event', 'archived')
        ->where('meta.total', 1)
    );

    $this->get(auditPageUrl('auditable_type=submission'))->assertOk()->assertInertia(fn ($page) => $page
        ->has('data', 0)
        ->where('filters.applied.auditable_type', 'submission')
    );
});

it('includes the WHOLE of the `to` day, unlike the API twin', function (): void {
    $this->withoutVite();

    app(FormService::class)->create($this->tenant, $this->owner, 'Survey');
    $today = now()->toDateString();

    // AuditApiController does a naive `<= $request->date('to')`, which resolves to midnight and therefore
    // excludes every event ON that day — a same-day filter returns nothing. The web request takes `to` to
    // endOfDay, so filtering to today finds today's rows.
    $this->get(auditPageUrl('from='.$today.'&to='.$today))->assertOk()->assertInertia(fn ($page) => $page
        ->has('data', 1)
        ->where('filters.applied.from', $today)
        ->where('filters.applied.to', $today)
    );
});

it('renders a redacted change as the placeholder on BOTH sides, flagged', function (): void {
    $this->withoutVite();

    // Written through the real AuditLogger so the redaction is PRODUCED rather than asserted into place.
    app(AuditLogger::class)->record(
        AuditEvent::Updated,
        'submission',
        (string) Str::uuid7(),
        old: ['status' => 'submitted', 'remarks' => 'called the mother, 555-0101'],
        new: ['status' => 'approved', 'remarks' => 'verified'],
        actorId: (string) $this->owner->getKey(),
    );

    $this->get(auditPageUrl('auditable_type=submission'))->assertOk()->assertInertia(function ($page): void {
        $changes = collect($page->toArray()['props']['data'][0]['changes'])->keyBy('key');

        // Spec §2.1: the "before" side was never the real value, so the diff shows the placeholder on both
        // sides and flags the key. A renderer that treated `old` as literal would be advertising a value
        // the ledger deliberately never stored.
        expect($changes['remarks']['old'])->toBe(AuditRedactor::PLACEHOLDER);
        expect($changes['remarks']['new'])->toBe(AuditRedactor::PLACEHOLDER);
        expect($changes['remarks']['redacted'])->toBeTrue();
        expect($changes['status']['redacted'])->toBeFalse();
    })->assertInertia(fn ($page) => $page->where('data.0.redacted_fields', ['remarks']));
});

it('labels a null actor `System` and an invisible one `Unknown user`', function (): void {
    $this->withoutVite();

    $ghost = User::factory()->create();
    makeActiveMember($ghost, 'form_editor');

    app(AuditLogger::class)->record(
        AuditEvent::Updated, 'settings', $this->tenant->id, new: ['draft_ttl_days' => 10], actorId: (string) $ghost->getKey(),
    );

    // The system row is minted through the FACTORY, not AuditLogger, and the reason is worth stating: a
    // null `actorId` does NOT produce a null `user_id` — AuditLogger falls back to `Auth::id()`, so from
    // an authenticated request a genuinely system-attributed row is unreachable. In production these come
    // from console and queue paths where there is no guard. The factory is the documented stand-in for
    // read-side tests.
    Audit::factory()->create([
        'auditable_type' => 'settings',
        'auditable_id' => $this->tenant->id,
        'user_id' => null,
        'new_values' => ['draft_ttl_days' => 20],
    ]);

    // Remove the membership: `users` RLS is the join-shape policy (self + ACTIVE co-tenant members), so the
    // ghost's row becomes invisible even though `user_id` is still a live uuid. That is NOT the same fact
    // as a null actor, and rendering both as "System" would blame the platform for a person's act.
    TenantUser::query()->where('user_id', $ghost->id)->delete();

    $this->get(auditPageUrl('auditable_type=settings'))->assertOk()->assertInertia(fn ($page) => $page
        // Newest first.
        ->where('data.0.actor', 'System')
        ->where('data.0.is_system', true)
        ->where('data.1.actor', 'Unknown user')
        ->where('data.1.is_system', false)
    );
});

it('offers every visible member as an actor filter, including one who has never acted', function (): void {
    $this->withoutVite();

    // Pins the roster-based catalog against a revert to `SELECT DISTINCT user_id FROM audits`, which is
    // wrong twice: measured on a 400k-row tenant slice the planner picks a Parallel Seq Scan (6,168
    // buffers) to return twelve rows — Postgres has no loose index scan, so `audits_tenant_user_idx`
    // cannot serve a DISTINCT — and every id it returns beyond the roster is one the `users` join-shape
    // RLS policy will not let this process name anyway.
    $quiet = User::factory()->create(['name' => 'Aaron Quiet']);
    makeActiveMember($quiet, 'admin');

    app(FormService::class)->create($this->tenant, $this->owner, 'Survey');

    $this->get(auditPageUrl())->assertOk()->assertInertia(fn ($page) => $page
        // Alphabetical, so the member who has never touched the ledger leads.
        ->where('filters.actors.0.value', $quiet->id)
        ->where('filters.actors.0.label', 'Aaron Quiet')
        ->has('filters.actors', 2)
        // …and asking for their activity is a legitimate question with an honest empty answer.
        ->has('data', 1)
    );

    $this->get(auditPageUrl('user_id='.$quiet->id))->assertOk()->assertInertia(fn ($page) => $page
        ->has('data', 0)
        ->where('empty_reason', 'no_matches')
    );
});

it('distinguishes an empty ledger from a filter that matched nothing', function (): void {
    $this->withoutVite();

    $this->get(auditPageUrl())->assertOk()
        ->assertInertia(fn ($page) => $page->where('empty_reason', 'no_rows')->has('data', 0));

    // The tenant GUC dies with every HTTP request, so a write after one needs the context re-established.
    enterTenant($this->tenant->id, $this->owner->id);
    app(FormService::class)->create($this->tenant, $this->owner, 'Survey');

    $this->get(auditPageUrl('event=deleted'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('empty_reason', 'no_matches')->has('data', 0));
});

it('refuses a Viewer and a Form Editor, who are excluded from audit_log.view on purpose', function (): void {
    $this->withoutVite();

    // Both memberships are minted BEFORE any request: the tenant GUC dies with each one, and
    // `tenant_users` is strict-RLS, so a makeActiveMember() between two gets fails the INSERT policy.
    $viewer = User::factory()->create();
    makeActiveMember($viewer, 'viewer');
    $editor = User::factory()->create();
    makeActiveMember($editor, 'form_editor');

    // RolePermissionSeeder grants audit_log.view to owner and admin only, and its own comment says the
    // exclusion is deliberate. Playwright only ever loads the Owner fixture, so this is the only place the
    // refusal is proven.
    $this->actingAs($viewer)->get(auditPageUrl())->assertForbidden();
    $this->actingAs($editor)->get(auditPageUrl())->assertForbidden();
});

it('survives a bookmarked URL naming an alias the catalog no longer lists', function (): void {
    $this->withoutVite();

    // `auditable_type` is a loose string rather than Rule::in(catalog) precisely so this renders an empty
    // result set on the page instead of a 422 that redirects "back" — which on a cold visit is nowhere.
    $this->get(auditPageUrl('auditable_type=retired_alias'))->assertOk()->assertInertia(fn ($page) => $page
        ->has('data', 0)
        ->where('filters.applied.auditable_type', 'retired_alias')
    );
});

it('paginates at 25 and preserves the filter in the page links', function (): void {
    $this->withoutVite();

    for ($i = 0; $i < 26; $i++) {
        Audit::factory()->create(['event' => AuditEvent::Updated, 'auditable_type' => 'form']);
    }

    $this->get(auditPageUrl('event=updated&page=2'))->assertOk()->assertInertia(fn ($page) => $page
        ->has('data', 1)
        ->where('meta.current_page', 2)
        ->where('meta.last_page', 2)
        ->where('meta.total', 26)
        ->where('filters.applied.event', 'updated')
    );
});
