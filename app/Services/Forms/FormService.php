<?php

declare(strict_types=1);

namespace App\Services\Forms;

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
use App\Support\Forms\FormSchedule;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Form creation (form-versioning-schema-migration.md §3.1). Creates the durable form, its initial draft
 * version (v1, empty snapshot), and the creator's editor grant — in one transaction. Assumes the caller
 * has established the tenant DB context (BelongsToTenant auto-fills tenant_id; RLS is the backstop).
 */
final class FormService
{
    public function __construct(
        private readonly ResourceGrantResolver $grants,
        private readonly QuotaGuard $quota,
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

            return $form->refresh();
        });
    }

    /**
     * Update a form's metadata, keeping the denormalized title/description in sync on the current draft
     * version (data-dictionary §2 — the form's copy tracks the draft's on every save).
     */
    public function updateMetadata(Form $form, string $title, ?string $description): Form
    {
        return DB::transaction(function () use ($form, $title, $description): Form {
            $form->forceFill(['title' => $title, 'description' => $description])->save();

            if ($form->draft_version_id !== null) {
                FormVersion::query()->whereKey($form->draft_version_id)
                    ->update(['title' => $title, 'description' => $description]);
            }

            return $form->refresh();
        });
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
    public function assignScope(Form $form, ?string $scopeNodeId): Form
    {
        $node = $scopeNodeId === null
            ? null
            : ScopeNode::query()->whereKey($scopeNodeId)->where('is_active', true)->firstOrFail();

        $form->forceFill(['scope_node_id' => $node?->getKey()])->save();

        return $form->refresh();
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
    public function setSaveAndResume(Form $form, bool $enabled): Form
    {
        $form->forceFill(['save_and_resume' => $enabled])->save();

        return $form->refresh();
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
     * failure is cosmetic rather than an outage. Doc #26 §8 records the gap and assigns closing it to H6b,
     * which owns the confirmation surface.
     *
     * @param  array<string, string>|null  $translations
     */
    public function setConfirmationMessage(Form $form, ?string $message, ?array $translations): Form
    {
        $form->forceFill([
            'confirmation_message' => $message,
            'confirmation_message_translations' => $translations === null || $translations === [] ? null : $translations,
        ])->save();

        return $form->refresh();
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
    ): Form {
        if ($opensAt !== null && $closesAt !== null && $opensAt->greaterThanOrEqualTo($closesAt)) {
            throw FormException::invalidSchedule();
        }

        $form->forceFill([
            'opens_at' => $opensAt,
            'closes_at' => $closesAt,
            'timezone' => $timezone,
            'max_responses' => $maxResponses,
        ]);
        $form->schedule_state = FormSchedule::initialState($form, CarbonImmutable::now());
        $form->save();

        return $form->refresh();
    }

    /**
     * Archive a form (form-versioning-schema-migration.md §9): discard the current draft (deleting the
     * draft version cascades its sections/fields/validations away), clear draft_version_id, and mark the
     * form archived. Published/superseded versions and current_published_version_id are untouched.
     */
    public function archive(Form $form): Form
    {
        return DB::transaction(function () use ($form): Form {
            $locked = Form::query()->whereKey($form->id)->lockForUpdate()->firstOrFail();

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

            return $locked->refresh();
        });
    }
}
