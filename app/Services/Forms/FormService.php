<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Enums\AuditEvent;
use App\Enums\FormStatus;
use App\Enums\FormVersionStatus;
use App\Enums\ResourceCapacity;
use App\Enums\UsageMetric;
use App\Exceptions\Forms\FormException;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\ResourceGrant;
use App\Models\ScopeNode;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Authorization\ResourceGrantResolver;
use App\Services\Entitlements\QuotaGuard;
use App\Support\Audit\AuditLogger;
use App\Support\Forms\FormSchedule;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Form creation (form-versioning-schema-migration.md §3.1). Creates the durable form, its initial draft
 * version (v1, empty snapshot), and the creator's editor grant — in one transaction. Assumes the caller
 * has established the tenant DB context (BelongsToTenant auto-fills tenant_id; RLS is the backstop).
 *
 * ── Every write on this service is audited (Increment I2) ─────────────────────────────────────────────
 * I1 audited {@see self::setShareSettings()} alone and left the rest silent; I2 closed them AS A SET,
 * which is the only honest way to ship a viewer: a partial `form` trail looks complete and is not.
 * The shape is I1's throughout — a `DB::transaction` (required by {@see AuditLogger}, so the row is
 * atomic with the change it records), explicit `$old`/`$new` literal arrays, `auditable_type = 'form'`,
 * and a trailing `?User $actor = null` threaded from the controller. `create()` is the one exception to
 * the trailing parameter: it already takes `$creator`, who IS the actor.
 *
 * Two payload rules that are not obvious and are load-bearing:
 *  - **Carbon values are stringified.** A raw Carbon serializes into jsonb as
 *    `{"date":…,"timezone_type":3,…}` — unreadable, and it drives the diff renderer down its array
 *    branch. {@see self::setSchedule()} passes `?->toIso8601String()`.
 *  - **Derived and bulk values stay out.** {@see self::setConfirmationMessage()} records the translation
 *    LOCALE KEYS, not the map: N locales of the same copy in an append-only, never-pruned table is how a
 *    ledger becomes unqueryable (the same reason `brand_ramp` is excluded in TenantBrandingService).
 */
final class FormService
{
    public function __construct(
        private readonly ResourceGrantResolver $grants,
        private readonly QuotaGuard $quota,
        private readonly AuditLogger $audit,
    ) {}

    public function create(Tenant $tenant, User $creator, string $title, ?string $description = null): Form
    {
        return DB::transaction(function () use ($tenant, $creator, $title, $description): Form {
            // Hard-block the forms_count quota (H5b / ADR-0008 §D4) before anything is written. Inside the
            // transaction so a refusal rolls back cleanly; covers the template-instantiate caller too (its
            // outer transaction wraps this one). A live COUNT under RLS — archived forms free a slot.
            $this->quota->assertCanCreate(UsageMetric::FormsCount);

            $form = Form::create([
                'title' => $title,
                'description' => $description,
                'status' => FormStatus::Draft,
                'default_locale' => $tenant->default_locale ?? 'en',
                'owner_user_id' => $creator->id,
                'created_by' => $creator->id,
            ]);

            $draft = FormVersion::create([
                'form_id' => $form->id,
                'version_number' => 1,
                'status' => FormVersionStatus::Draft,
                'title' => $title,
                'description' => $description,
                'schema_snapshot' => [],
            ]);

            $form->forceFill(['draft_version_id' => $draft->id])->save();

            // The creator gets an explicit editor grant so `forms.edit.own` resolves for them
            // (data-dictionary §2 design note; RBAC §8). Owner/Admin get tenant-wide `.any` without one.
            //
            // associate() rather than a literal 'form' string: the alias comes from the registered morph
            // map, so the type can never drift from what the RLS guard's CHECK constraint accepts.
            $creatorId = (string) $creator->getKey();

            $grant = new ResourceGrant([
                'user_id' => $creatorId,
                'capacity' => ResourceCapacity::Editor,
            ]);
            $grant->scopeable()->associate($form);
            $grant->granted_by = $creatorId;
            $grant->save();

            // The resolver memoizes per (user, tenant) for the request; this write must be visible to any
            // policy check later in the SAME request (e.g. the redirect's Inertia render).
            $this->grants->forget($creatorId);

            // No `?User $actor` parameter here, unlike every sibling: $creator IS the actor, and the
            // template-instantiate caller (TemplateService) passes the acting user through as well.
            $this->audit->record(
                AuditEvent::Created,
                'form',
                (string) $form->getKey(),
                new: ['title' => $title, 'status' => FormStatus::Draft->value],
                actorId: $creatorId,
            );

            return $form->refresh();
        });
    }

    /**
     * Update a form's metadata, keeping the denormalized title/description in sync on the current draft
     * version (data-dictionary §2 — the form's copy tracks the draft's on every save).
     */
    public function updateMetadata(Form $form, string $title, ?string $description, ?User $actor = null): Form
    {
        return DB::transaction(function () use ($form, $title, $description, $actor): Form {
            $old = ['title' => $form->title, 'description' => $form->description];
            $new = ['title' => $title, 'description' => $description];

            $form->forceFill($new)->save();

            if ($form->draft_version_id !== null) {
                // The `status` predicate is load-bearing, not belt-and-braces (H25 / Risk R5). This method
                // takes an in-memory $form with no lock and no re-read, so if that model was loaded before
                // a concurrent publish, `draft_version_id` now names the version that publish just FROZE —
                // and without this clause an ordinary form-settings save silently rewrites a published
                // version's title/description, which is precisely the corruption R5 describes. With it the
                // stale write degrades to a no-op instead (and, since H25, to a 23001 rather than silence).
                FormVersion::query()->whereKey($form->draft_version_id)
                    ->where('status', FormVersionStatus::Draft->value)
                    ->update(['title' => $title, 'description' => $description]);
            }

            $this->recordFormUpdate($form, $old, $new, $actor);

            return $form->refresh();
        });
    }

    /**
     * The one emission site the six `form`/`updated` setters share (I2). Keeping it private rather than
     * repeating six `record()` calls is what makes "every form-config write is audited the same way" a
     * property of the code instead of a review convention.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    private function recordFormUpdate(Form $form, array $old, array $new, ?User $actor): void
    {
        $this->audit->record(
            AuditEvent::Updated,
            'form',
            (string) $form->getKey(),
            old: $old,
            new: $new,
            actorId: $actor?->getKey() === null ? null : (string) $actor->getKey(),
        );
    }

    /**
     * Assign a form to a scope node, or un-assign it (Increment G10b2) — the only writer of
     * `forms.scope_node_id`.
     *
     * This column is an AUTHORIZATION INPUT, not metadata. Writing it confers capacity on the form — and,
     * through `SubmissionPolicy`, on its entire submission history — to every holder of a grant on that node
     * and on any ancestor whose grant sets `includes_descendants`. Clearing it strips every node-derived
     * reviewer while leaving direct grants intact. Both directions are grant-equivalent acts, which is why
     * the route stacks `can:viewAny,ScopeNode` (i.e. `scopes.manage`) on top of `can:update,form`.
     *
     * `forceFill` with an explicit key rather than the request's validated array: `scope_node_id` IS in
     * `Form::$fillable`, so a `$form->update($validated)` anywhere on the `can:update,form` gate would hand a
     * plain form editor this capability. Centralizing the write here is what keeps that structural.
     *
     * The node is re-resolved through the tenant-scoped model and must be ACTIVE — a foreign id 404s instead
     * of tripping the composite FK, and parking a form on a deactivated node would be a disguised un-assign
     * (the resolver discards inactive paths), so it is refused rather than silently accepted.
     *
     * No resolver invalidation: nothing memoized is keyed by form id — `$formPaths` is keyed by NODE id, and
     * `holds()` reads `scope_node_id` live off the model. See ResourceGrantResolver::$formPaths.
     */
    public function assignScope(Form $form, ?string $scopeNodeId, ?User $actor = null): Form
    {
        $node = $scopeNodeId === null
            ? null
            : ScopeNode::query()->whereKey($scopeNodeId)->where('is_active', true)->firstOrFail();

        // The transaction is new in I2 and is not decoration: AuditLogger requires the row to be atomic
        // with the change. Of the six setters I2 audited this is the one that most needs it — the
        // docblock above calls this column an AUTHORIZATION INPUT, so a write that landed without its
        // audit row would be a silent capacity change across a whole subtree.
        return DB::transaction(function () use ($form, $node, $actor): Form {
            $old = ['scope_node_id' => $form->scope_node_id];
            $new = ['scope_node_id' => $node?->getKey()];

            $form->forceFill($new)->save();

            $this->recordFormUpdate($form, $old, $new, $actor);

            return $form->refresh();
        });
    }

    /**
     * Toggle a form's per-form save-and-resume opt-in (Increment H10, UX §5.2) — the only writer of
     * `forms.save_and_resume`.
     *
     * `forceFill` with an explicit key for the same reason as {@see self::assignScope()}: `save_and_resume` is
     * in `Form::$fillable`, so centralizing the write keeps a plain `$form->update($validated)` from ever
     * setting it. The tenant-plan half of the gate lives at the route (`feature:save_and_resume`); this method
     * writes the per-form half. No pipeline/version effect — it only governs whether the guest runtime offers
     * the control and whether the guest draft channel accepts a save.
     */
    public function setSaveAndResume(Form $form, bool $enabled, ?User $actor = null): Form
    {
        return DB::transaction(function () use ($form, $enabled, $actor): Form {
            $old = ['save_and_resume' => $form->save_and_resume];
            $new = ['save_and_resume' => $enabled];

            $form->forceFill($new)->save();

            $this->recordFormUpdate($form, $old, $new, $actor);

            return $form->refresh();
        });
    }

    /**
     * Set a form's public link + guest-access toggle (Increment I1, PRD Feature #3) — the only writer of
     * `forms.public_slug` and `forms.allow_guest_submissions` outside the XLSForm importer.
     *
     * `forceFill` with explicit keys for the reason given at {@see self::assignScope()}, which applies here
     * with more force than anywhere else it is stated: both columns ARE in `Form::$fillable`, and together
     * they are the switch that makes a form collectable by anyone on the internet. A `$form->update($validated)`
     * behind `can:update,form` would make "publish this to the world" reachable as a side effect of an
     * unrelated edit. Centralizing it here is what keeps that structural rather than a review convention.
     *
     * THIS IS THE FIRST AUDITED FORM-CONFIG WRITE, and it is deliberately first. Nothing else on this service
     * emits an audit row — `updateMetadata`, `assignScope`, `setSaveAndResume`, `setConfirmationMessage`,
     * `setSchedule` and `archive` are all silent, and Increment I2 closes that as a set. This one does not
     * wait for I2 because it is the highest-blast-radius toggle a form has: it is the difference between a
     * private draft and an open collection endpoint, and "who turned guest access on, and when" is the first
     * question anyone asks about an unexpected submission. It establishes `auditable_type = 'form'`, which I2
     * then follows for the rest.
     *
     * The transaction is not decoration: {@see AuditLogger} requires the row to be atomic with the change it
     * records, and the sibling single-column setters open none.
     */
    public function setShareSettings(Form $form, ?string $slug, bool $allowGuests, ?User $actor = null): Form
    {
        return DB::transaction(function () use ($form, $slug, $allowGuests, $actor): Form {
            $old = [
                'public_slug' => $form->public_slug,
                'allow_guest_submissions' => $form->allow_guest_submissions,
            ];
            $new = [
                'public_slug' => $slug,
                'allow_guest_submissions' => $allowGuests,
            ];

            $form->forceFill($new)->save();

            $this->audit->record(
                AuditEvent::Updated,
                'form',
                (string) $form->getKey(),
                old: $old,
                new: $new,
                actorId: $actor?->getKey() === null ? null : (string) $actor->getKey(),
            );

            return $form->refresh();
        });
    }

    /**
     * Set (or clear) a form's confirmation message (Increment H6a,
     * `docs/piping-output-encoding-design.md` §6.2) — the only writer of `forms.confirmation_message` and
     * `confirmation_message_translations`. When both are null the hardcoded runtime default stands, so the
     * column is additive for every existing form.
     *
     * `forceFill` with explicit keys, and — unlike `save_and_resume` — these columns are deliberately NOT
     * in `Form::$fillable` at all, so mass-assignment cannot reach a template-bearing value even by
     * accident.
     *
     * This INVERTS {@see self::setSaveAndResume()}'s "No pipeline/version effect" note: the message is
     * TEMPLATE-BEARING, so its `${key}` holes are validated at publish by
     * {@see TemplateValidationGate} against the version being published. Two consequences worth knowing:
     * the request rule checks grammar only (there is no version to resolve against at edit time), and an
     * edit made AFTER the last publish can introduce a reference that dangles until the next publish
     * refuses it — a hole renders as the empty string in the meantime and never throws (§3.4), so the
     * failure is cosmetic rather than an outage.
     *
     * Increment H6b closed the EDIT side of that gap: `FormConfirmationMessageController` now resolves the
     * saved message against the currently-published version and warns in its toast, without refusing the
     * write (Doc #26 amendment A3 + A10). What remains open is narrower and belongs with
     * `SchemaChangeClassifier` hole-diffing: DELETING a field after the message was saved still dangles it
     * silently, because nothing re-validates this column on a schema change.
     *
     * @param  array<string, string>|null  $translations
     */
    public function setConfirmationMessage(Form $form, ?string $message, ?array $translations, ?User $actor = null): Form
    {
        return DB::transaction(function () use ($form, $message, $translations, $actor): Form {
            $normalized = $translations === null || $translations === [] ? null : $translations;

            // The audit records which LOCALES carry a translation, never the translations themselves:
            // N locales of the same copy, in an append-only never-pruned table, on every confirmation-copy
            // tweak. The message body is recorded because it is the thing that changed and it is short;
            // the locale set is the part that answers "who added Spanish, and when".
            $old = [
                'confirmation_message' => $form->confirmation_message,
                'confirmation_message_locales' => array_keys($form->confirmation_message_translations ?? []),
            ];
            $new = [
                'confirmation_message' => $message,
                'confirmation_message_locales' => array_keys($normalized ?? []),
            ];

            $form->forceFill([
                'confirmation_message' => $message,
                'confirmation_message_translations' => $normalized,
            ])->save();

            $this->recordFormUpdate($form, $old, $new, $actor);

            return $form->refresh();
        });
    }

    /**
     * Set (or clear) a form's schedule + response cap (Increment H12a) — the only writer of `forms.opens_at`,
     * `closes_at`, `timezone`, `max_responses` and `schedule_state`. `forceFill` with explicit keys for the same
     * reason as {@see self::setSaveAndResume()}: it keeps a plain `$form->update($validated)` from ever setting
     * them. `opens_at`/`closes_at` are absolute instants (the request supplies parsed Carbon values); `timezone`
     * is authoring metadata only.
     *
     * `schedule_state` is recomputed from the new window on every write, so re-configuring is coherent: pushing
     * `closes_at` into the future reopens a closed form and re-arms a later `form.closed`, while a "born
     * open/closed" window (a boundary already in the past at config time) initializes directly and the sweep
     * emits no transition event for it. Enforcement is live and never reads `schedule_state`.
     *
     * The ordering guard is a backstop behind the request's `after:opens_at` rule.
     */
    public function setSchedule(
        Form $form,
        ?CarbonInterface $opensAt,
        ?CarbonInterface $closesAt,
        string $timezone,
        ?int $maxResponses,
        ?User $actor = null,
    ): Form {
        // Outside the transaction: a refusal is not an act and leaves nothing to record.
        if ($opensAt !== null && $closesAt !== null && $opensAt->greaterThanOrEqualTo($closesAt)) {
            throw FormException::invalidSchedule();
        }

        return DB::transaction(function () use ($form, $opensAt, $closesAt, $timezone, $maxResponses, $actor): Form {
            // ISO strings, not Carbon instances — a Carbon serializes into jsonb as
            // {"date":…,"timezone_type":3,…}, which no reader can parse and no diff can render.
            $old = [
                'opens_at' => $form->opens_at?->toIso8601String(),
                'closes_at' => $form->closes_at?->toIso8601String(),
                'timezone' => $form->timezone,
                'max_responses' => $form->max_responses,
                'schedule_state' => $form->schedule_state?->value,
            ];

            $form->forceFill([
                'opens_at' => $opensAt,
                'closes_at' => $closesAt,
                'timezone' => $timezone,
                'max_responses' => $maxResponses,
            ]);
            $form->schedule_state = FormSchedule::initialState($form, CarbonImmutable::now());
            $form->save();

            $this->recordFormUpdate($form, $old, [
                'opens_at' => $opensAt?->toIso8601String(),
                'closes_at' => $closesAt?->toIso8601String(),
                'timezone' => $timezone,
                'max_responses' => $maxResponses,
                'schedule_state' => $form->schedule_state?->value,
            ], $actor);

            return $form->refresh();
        });
    }

    /**
     * Archive a form (form-versioning-schema-migration.md §9): discard the current draft (deleting the
     * draft version cascades its sections/fields/validations away), clear draft_version_id, and mark the
     * form archived. Published/superseded versions and current_published_version_id are untouched.
     */
    public function archive(Form $form, ?User $actor = null): Form
    {
        return DB::transaction(function () use ($form, $actor): Form {
            $locked = Form::query()->whereKey($form->id)->lockForUpdate()->firstOrFail();

            // Snapshotted off $locked, AFTER the lock — never off the passed-in $form, which may be stale.
            // Re-reading under lockForUpdate is the whole reason that line exists, and an audit built from
            // the stale copy would record a `status` this method never actually saw.
            //
            // `draft_version_id` is in the payload because archiving DESTROYS that version and cascades its
            // whole section/field/validation subtree away: the audit row is the only surviving record of
            // which version died.
            $old = [
                'status' => $locked->status->value,
                'draft_version_id' => $locked->draft_version_id,
            ];

            if ($locked->draft_version_id !== null) {
                // Only a draft is deletable (form_version RLS guard) — the current draft is one by
                // definition; the FK ON DELETE SET NULL clears the pointer and CASCADE drops its content.
                FormVersion::query()->whereKey($locked->draft_version_id)->delete();
            }

            $locked->forceFill([
                'status' => FormStatus::Archived,
                'archived_at' => now(),
                'draft_version_id' => null,
            ])->save();

            $this->audit->record(
                AuditEvent::Archived,
                'form',
                (string) $locked->getKey(),
                old: $old,
                new: [
                    'status' => FormStatus::Archived->value,
                    'archived_at' => $locked->archived_at?->toIso8601String(),
                    'draft_version_id' => null,
                ],
                actorId: $actor?->getKey() === null ? null : (string) $actor->getKey(),
            );

            return $locked->refresh();
        });
    }
}
