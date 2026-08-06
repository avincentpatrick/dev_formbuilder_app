<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Models\Audit;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Branding\TenantBrandingService;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Forms\RestoreService;
use App\Services\Submissions\SubmissionReviewService;
use App\Services\Tenancy\TenantSettingsService;
use App\Support\Audit\AuditRedactor;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The Increment I2 audit sites — the coverage half of "audit trail end-to-end".
|
| AuditSitesTest pins H4's six. This file pins the ones that were still silent when I2 started: every
| FormService write (create, metadata, scope, save-and-resume, confirmation copy, schedule, archive),
| RestoreService, tenant draft settings, all four branding verbs, and the SEVENTH TODO(audit) site at
| SubmissionReviewService::apply() — the one H4's inventory never walked, because four public verbs funnel
| through one private method.
|
| The custom-domain sites are deliberately elsewhere (AuditDomainSitesTest): they carry two RLS hazards
| that deserve their own file rather than being buried in a list.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run();

    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $this->admin = User::factory()->create();
    enterTenant($this->tenant->id, $this->admin->id);
    makeActiveMember($this->admin, 'admin');
    $this->actingAs($this->admin);
});

afterEach(fn () => app(PermissionRegistrar::class)->setPermissionsTeamId(null));

/** The newest `form` row for one event — every FormService write targets the same auditable. */
function formAudit(string $formId, AuditEvent $event): ?Audit
{
    return Audit::query()
        ->where('auditable_type', 'form')
        ->where('auditable_id', $formId)
        ->where('event', $event->value)
        ->orderByDesc('id')
        ->first();
}

/** The newest `settings` row for the tenant — branding and draft settings share the alias. */
function settingsAudit(string $tenantId): ?Audit
{
    return Audit::query()
        ->where('auditable_type', 'settings')
        ->where('auditable_id', $tenantId)
        ->orderByDesc('id')
        ->first();
}

// ── FormService ───────────────────────────────────────────────────────────────────────────────────────

it('audits form creation as `created`, with the creator as the actor', function (): void {
    $form = app(FormService::class)->create($this->tenant, $this->admin, 'Clinic Intake');

    $audit = formAudit($form->id, AuditEvent::Created);

    expect($audit)->not->toBeNull();
    expect($audit->new_values)->toBe(['title' => 'Clinic Intake', 'status' => 'draft']);
    // create() takes no ?User $actor: $creator IS the actor, which is why it is the one exception to the
    // trailing-parameter convention every sibling follows.
    expect($audit->user_id)->toBe($this->admin->id);
});

it('audits a metadata edit with both sides of the change', function (): void {
    $form = app(FormService::class)->create($this->tenant, $this->admin, 'Old title');

    app(FormService::class)->updateMetadata($form, 'New title', 'A description', $this->admin);

    $audit = formAudit($form->id, AuditEvent::Updated);

    expect($audit->old_values)->toBe(['title' => 'Old title', 'description' => null]);
    expect($audit->new_values)->toBe(['title' => 'New title', 'description' => 'A description']);
    expect($audit->user_id)->toBe($this->admin->id);
});

it('audits a scope assignment — the grant-equivalent write', function (): void {
    // The highest-value of the six newly-audited setters: assignScope's own docblock calls scope_node_id an
    // AUTHORIZATION INPUT, so a write landing without its ledger row would be a silent capacity change
    // across a whole subtree.
    $form = app(FormService::class)->create($this->tenant, $this->admin, 'Survey');
    $node = makeScopeNode(null, 'Region A');

    app(FormService::class)->assignScope($form, (string) $node->getKey(), $this->admin);

    $audit = formAudit($form->id, AuditEvent::Updated);

    expect($audit->old_values)->toBe(['scope_node_id' => null]);
    expect($audit->new_values)->toBe(['scope_node_id' => (string) $node->getKey()]);
});

it('audits the save-and-resume toggle', function (): void {
    $form = app(FormService::class)->create($this->tenant, $this->admin, 'Survey');

    app(FormService::class)->setSaveAndResume($form, true, $this->admin);

    $audit = formAudit($form->id, AuditEvent::Updated);

    expect($audit->old_values)->toBe(['save_and_resume' => false]);
    expect($audit->new_values)->toBe(['save_and_resume' => true]);
});

it('audits confirmation copy by LOCALE KEYS, never the translations map', function (): void {
    $form = app(FormService::class)->create($this->tenant, $this->admin, 'Survey');

    app(FormService::class)->setConfirmationMessage(
        $form,
        'Thank you.',
        ['fr' => 'Merci.', 'es' => 'Gracias.'],
        $this->admin,
    );

    $audit = formAudit($form->id, AuditEvent::Updated);

    expect($audit->new_values['confirmation_message'])->toBe('Thank you.');
    expect($audit->new_values['confirmation_message_locales'])->toEqualCanonicalizing(['fr', 'es']);
    // N locales of the same copy, in an append-only never-pruned table, on every confirmation-copy tweak
    // is how a ledger becomes unqueryable. The locale set answers "who added Spanish, and when".
    expect($audit->new_values)->not->toHaveKey('confirmation_message_translations');
    expect(json_encode($audit->new_values))->not->toContain('Merci');
});

it('audits a schedule as ISO STRINGS, never raw Carbon instances', function (): void {
    // A Carbon handed to record() serializes into jsonb as {"date":…,"timezone_type":3,…} — unreadable,
    // and it drives the diff renderer down its array branch. This is the assertion that catches it.
    $form = app(FormService::class)->create($this->tenant, $this->admin, 'Survey');
    $opens = CarbonImmutable::parse('2026-09-01 08:00:00', 'UTC');
    $closes = CarbonImmutable::parse('2026-09-30 17:00:00', 'UTC');

    app(FormService::class)->setSchedule($form, $opens, $closes, 'Asia/Manila', 500, $this->admin);

    $audit = formAudit($form->id, AuditEvent::Updated);

    expect($audit->new_values['opens_at'])->toBeString()->toBe($opens->toIso8601String());
    expect($audit->new_values['closes_at'])->toBeString()->toBe($closes->toIso8601String());
    expect($audit->new_values['timezone'])->toBe('Asia/Manila');
    expect($audit->new_values['max_responses'])->toBe(500);
    expect($audit->old_values['opens_at'])->toBeNull();
});

it('audits an archive as `archived`, naming the draft version it destroys', function (): void {
    $form = app(FormService::class)->create($this->tenant, $this->admin, 'Survey');
    $draftId = $form->draft_version_id;

    app(FormService::class)->archive($form, $this->admin);

    $audit = formAudit($form->id, AuditEvent::Archived);

    expect($audit)->not->toBeNull();
    // Archiving DELETES that version and cascades its whole section/field/validation subtree away — this
    // row is the only surviving record of which version died.
    expect($audit->old_values)->toBe(['status' => 'draft', 'draft_version_id' => $draftId]);
    expect($audit->new_values['status'])->toBe('archived');
    expect($audit->new_values['draft_version_id'])->toBeNull();
    expect($audit->new_values['archived_at'])->toBeString();
});

// ── RestoreService ────────────────────────────────────────────────────────────────────────────────────

it('audits a version restore as `restored` on the FORM, naming both versions', function (): void {
    $form = app(FormService::class)->create($this->tenant, $this->admin, 'Survey');
    addFormField($form->draftVersion, $this->admin, 'q1');
    $published = app(PublishService::class)->publish($form->refresh(), $this->admin);

    // Publishing mints the next draft; restore overwrites it from the published version.
    $draft = app(RestoreService::class)->restore($form->refresh(), $published, $this->admin);

    $audit = formAudit($form->id, AuditEvent::Restored);

    expect($audit)->not->toBeNull();
    // `form`, not `form_version`: spec §1 assigns `restored` to `form` and gives `form_version` only
    // `published`.
    expect($audit->new_values['source_version_id'])->toBe($published->id);
    expect($audit->new_values['source_version_number'])->toBe($published->version_number);
    expect($audit->new_values['draft_version_id'])->toBe($draft->id);
    // old is deliberately null: the prior draft's CONTENT is not representable as a few scalars, and a
    // schema snapshot on the ledger is exactly the noise spec §1's "Deliberately NOT audited" clause bars.
    expect($audit->old_values)->toBeNull();
    // The actor was accepted-and-unused before I2 — notably absent from the closure's use() list.
    expect($audit->user_id)->toBe($this->admin->id);
});

// ── Tenant settings ───────────────────────────────────────────────────────────────────────────────────

it('audits a draft-settings write as `settings`, keyed on the TENANT id', function (): void {
    app(TenantSettingsService::class)->updateDraftSettings($this->tenant, ['draft_ttl_days' => 45], $this->admin);

    $audit = settingsAudit($this->tenant->id);

    expect($audit)->not->toBeNull();
    expect($audit->event)->toBe(AuditEvent::Updated);
    expect($audit->new_values)->toBe(['draft_ttl_days' => 45]);
    // Keyed off what was SENT, so a partial PATCH produces a one-key diff rather than a full-row snapshot.
    expect(array_keys($audit->old_values))->toBe(['draft_ttl_days']);
    // …and the key is PRESENT on the old side holding null, not missing from it. That distinction is the
    // whole reason AuditDiff separates "absent" from "present and null": `draft_ttl_days` is nullable with
    // no database default, so null genuinely means "no override, use the system default" — a real prior
    // value the ledger must show as `— → 45`, never as `(absent) → 45`.
    //
    // This is the assertion that caught the partial-hydration trap the service now refreshes past: a model
    // built by `Tenant::create([...])` without this column carries no such attribute, so the pre-refresh
    // snapshot omitted the key entirely and the diff claimed the setting had not existed before.
    expect($audit->old_values)->toHaveKey('draft_ttl_days');
    expect($audit->old_values['draft_ttl_days'])->toBeNull();
});

it('records NO row for an empty PATCH — an empty request is not an act', function (): void {
    $before = Audit::query()->count();

    app(TenantSettingsService::class)->updateDraftSettings($this->tenant, [], $this->admin);

    // The ONE exemption from "a no-change save still emits a row". A request that changed nothing is still
    // an act; a request that sent no field at all is not.
    expect(Audit::query()->count())->toBe($before);
});

// ── Branding ──────────────────────────────────────────────────────────────────────────────────────────

it('audits a brand colour under `settings` and NEVER carries the ramp blob', function (): void {
    app(TenantBrandingService::class)->setBrandColor($this->tenant, '#2f6f4e', $this->admin);

    $audit = settingsAudit($this->tenant->id);

    expect($audit)->not->toBeNull();
    // The NORMALIZED hex, which is what the column holds — the ledger records what was stored, not what
    // was typed.
    expect($audit->new_values['primary_color'])->toBe('#2F6F4E');
    expect($audit->old_values)->toBe(['primary_color' => null]);
    // ~2 KB of derived contrast tables that are a PURE FUNCTION of primary_color, in a table that is never
    // pruned. Recording the colour records both. This assertion is why the exclusion is structural.
    expect($audit->new_values)->not->toHaveKey('brand_ramp');
    expect(json_encode($audit->new_values))->not->toContain('contrast');
});

it('audits clearing the brand colour as `updated`, not `deleted`', function (): void {
    app(TenantBrandingService::class)->setBrandColor($this->tenant, '#2f6f4e', $this->admin);

    app(TenantBrandingService::class)->clearBrandColor($this->tenant->refresh(), $this->admin);

    $audit = settingsAudit($this->tenant->id);

    // Nothing leaves the system — a tenant column is nulled. Spec §1 gives `settings` only
    // created/updated, so this is also the one spec-legal choice.
    expect($audit->event)->toBe(AuditEvent::Updated);
    expect($audit->old_values)->toBe(['primary_color' => '#2F6F4E']);
    expect($audit->new_values)->toBe(['primary_color' => null]);
});

it('audits removing the logo, capturing the superseded attachment id', function (): void {
    $attachment = brandingLogoAttachment($this->tenant->id);
    $this->tenant->forceFill(['logo_attachment_id' => $attachment->id])->save();

    app(TenantBrandingService::class)->removeLogo($this->tenant->refresh(), $this->admin);

    $audit = settingsAudit($this->tenant->id);

    expect($audit->old_values)->toBe(['logo_attachment_id' => $attachment->id]);
    expect($audit->new_values)->toBe(['logo_attachment_id' => null]);
});

// ── The 7th TODO(audit) site ──────────────────────────────────────────────────────────────────────────

it('audits every review transition from the ONE site the four verbs share', function (): void {
    $tenant = $this->tenant;
    $form = publishedInboxForm($tenant, $this->admin, 'Reviewed');
    $submission = seedInboxSubmission($form, $this->admin, SubmissionStatus::Submitted, ['q1' => 'a'], SubmissionSource::Manual);

    app(SubmissionReviewService::class)->approve($submission, $this->admin, 'looks good');

    $audit = Audit::query()
        ->where('auditable_type', 'submission')
        ->where('auditable_id', $submission->id)
        ->where('event', AuditEvent::Updated->value)
        ->first();

    expect($audit)->not->toBeNull();
    expect($audit->old_values['status'])->toBe('submitted');
    expect($audit->new_values['status'])->toBe('approved');
    expect($audit->new_values['validated_by'])->toBe($this->admin->id);
    // ISO strings, never Carbon instances.
    expect($audit->new_values['validated_at'])->toBeString();
    // The actor is the PASSED reviewer, not Auth::id(): approve() stamps validated_by from the same value,
    // so a caller passing a non-authenticated reviewer must not leave the ledger contradicting the row.
    expect($audit->user_id)->toBe($this->admin->id);
});

it('redacts a reviewer\'s remarks on the ledger while keeping the returned reason', function (): void {
    $form = publishedInboxForm($this->tenant, $this->admin, 'Returned');
    $submission = seedInboxSubmission($form, $this->admin, SubmissionStatus::Submitted, ['q1' => 'a'], SubmissionSource::Manual);

    app(SubmissionReviewService::class)->returnToRespondent(
        $submission,
        $this->admin,
        'Missing page 2',
        'called the mother, number is 555-0101',
    );

    $audit = Audit::query()
        ->where('auditable_type', 'submission')
        ->where('auditable_id', $submission->id)
        ->where('event', AuditEvent::Updated->value)
        ->first();

    // `remarks` is internal free text with no audience discipline — a reviewer can paste respondent PII
    // into it, and `audits` is never deleted. The ledger records THAT it changed, never what it said.
    expect($audit->new_values['remarks'])->toBe(AuditRedactor::PLACEHOLDER);
    expect($audit->redacted_fields)->toContain('remarks');
    expect(json_encode($audit->new_values))->not->toContain('555-0101');
    // …but the reason WAS authored for the respondent and already emailed to them, so it is exactly the
    // accountability the ledger exists to preserve. The asymmetry is the decision.
    expect($audit->new_values['returned_reason'])->toBe('Missing page 2');
});

it('audits a reviewer archive as a submission update, not a form archive', function (): void {
    $form = publishedInboxForm($this->tenant, $this->admin, 'Archived');
    $submission = seedInboxSubmission($form, $this->admin, SubmissionStatus::Approved, ['q1' => 'a'], SubmissionSource::Manual);

    app(SubmissionReviewService::class)->archive($submission, $this->admin);

    $audit = Audit::query()
        ->where('auditable_type', 'submission')
        ->where('auditable_id', $submission->id)
        ->where('event', AuditEvent::Updated->value)
        ->first();

    // AuditEvent::Archived belongs to the FORM lifecycle; a submission reaching its terminal retention
    // state is a status transition on the same `updated` row shape as the other three verbs.
    expect($audit)->not->toBeNull();
    expect($audit->old_values['status'])->toBe('approved');
    expect($audit->new_values['status'])->toBe('archived');
});
