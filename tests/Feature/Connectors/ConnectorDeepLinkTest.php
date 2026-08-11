<?php

declare(strict_types=1);

use App\Enums\DomainEventType;
use App\Models\User;
use App\Support\Connectors\ConnectorEventContextResolver;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment J2d — A SLACK DEEP LINK MUST OPEN FOR WHOEVER IS WATCHING THE CHANNEL.
|
| ⚠️ THIS FILE EXISTS BECAUSE THE ADVERSARIAL REVIEW FOUND THE CLASS HAD NO TEST AT ALL, in an increment
| whose whole thesis is that a URL nobody navigates is a URL nobody has checked. J2d changed the
| form-lifecycle destination from `/forms/{id}/builder` to the hub and changed the lookup from
| `->value('title')` to a model fetch; both shipped unpinned until now.
|
| ⚠️ THERE IS NO USER HERE, AND THAT IS THE ARGUMENT FOR THE HUB. A delivery runs in a queue worker with no
| actor, so the destination cannot be gated per reader — it is followed by whoever happens to be in the
| channel. `/forms/{id}/builder` is `can:update,form` → `canEdit()`, which a Reviewer and a Viewer fail; the
| hub's `viewOverview` admits all five seeded roles. The same `match` already gave submission events this
| treatment ("pointing a Reviewer at an edit URL they cannot open turns an informative message into a 403")
| and the form arm never got it.
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
    $this->resolver = app(ConnectorEventContextResolver::class);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** The envelope shape the delivery job hands the resolver. */
function formEventEnvelope(DomainEventType $type, string $tenantId, ?string $formId): array
{
    return [
        'event_type' => $type->value,
        'tenant_id' => $tenantId,
        'data' => $formId === null ? [] : ['form_id' => $formId],
    ];
}

it('deep-links a form lifecycle event to the hub, not the builder', function (DomainEventType $type): void {
    /*
     * The string assertion, and here it is the PRIMARY one rather than a supplement: there is no reader to
     * navigate as, so "does it resolve for the role that receives it" is not a question this surface can
     * ask. What can be pinned is the exact path — and `/builder` is the specific wrong answer that shipped.
     */
    $context = $this->resolver->resolve(formEventEnvelope($type, $this->tenant->id, $this->form->id));

    expect($context->deepLink)->toBe('http://acme.meridian.test/forms/'.$this->form->id)
        ->and($context->deepLink)->not->toContain('/builder')
        ->and($context->formName)->toBe('Clinic Intake');
})->with([
    'published' => DomainEventType::FormPublished,
    'opened' => DomainEventType::FormOpened,
    'closed' => DomainEventType::FormClosed,
]);

it('is reachable by the narrowest role that could be watching the channel', function (): void {
    /*
     * The half a string cannot prove. A Viewer holds no `forms.*` key and fails `canEdit()`, so the OLD
     * destination 403'd for them; this drives the emitted path as that role and requires a success.
     *
     * ⚠️ ONE REQUEST, AFTER EVERY WRITE — the tenant GUC is torn down on the way out (`FormHubGateTest`).
     */
    $viewer = User::factory()->create();
    makeActiveMember($viewer, 'viewer');

    $context = $this->resolver->resolve(
        formEventEnvelope(DomainEventType::FormPublished, $this->tenant->id, $this->form->id),
    );

    $this->withoutVite()->actingAs($viewer)
        ->get((string) $context->deepLink)
        ->assertSuccessful();
});

it('omits the link entirely for a form that no longer resolves', function (): void {
    /*
     * The behaviour change J2d made alongside the destination: the lookup went from `->value('title')` to a
     * model fetch, so `$form === null` became the liveness check rather than `$formId === null`. A delivery
     * is retried for hours, so the window between the event and the click is real — a link built from the
     * id alone would 404.
     *
     * Degrading rather than throwing is the file's standing contract: a delivery must never fail because a
     * display string could not be resolved.
     */
    $context = $this->resolver->resolve(
        formEventEnvelope(DomainEventType::FormPublished, $this->tenant->id, '00000000-0000-7000-8000-000000000000'),
    );

    expect($context->deepLink)->toBeNull()
        ->and($context->formName)->toBeNull();
});

it('leaves member.invited without a link, which is deliberate', function (): void {
    // Pinned so the `default => null` arm is not "tidied" into a members link later: an invitation is not
    // yet a member, so the row a recipient would arrive looking for is not there until they accept.
    $context = $this->resolver->resolve([
        'event_type' => DomainEventType::MemberInvited->value,
        'tenant_id' => $this->tenant->id,
        'data' => [],
    ]);

    expect($context->deepLink)->toBeNull();
});
