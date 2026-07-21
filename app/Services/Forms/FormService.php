<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Enums\FormStatus;
use App\Enums\FormVersionStatus;
use App\Enums\ResourceCapacity;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\ResourceGrant;
use App\Models\ScopeNode;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Authorization\ResourceGrantResolver;
use Illuminate\Support\Facades\DB;

/**
 * Form creation (form-versioning-schema-migration.md §3.1). Creates the durable form, its initial draft
 * version (v1, empty snapshot), and the creator's editor grant — in one transaction. Assumes the caller
 * has established the tenant DB context (BelongsToTenant auto-fills tenant_id; RLS is the backstop).
 */
final class FormService
{
    public function __construct(private readonly ResourceGrantResolver $grants) {}

    public function create(Tenant $tenant, User $creator, string $title, ?string $description = null): Form
    {
        return DB::transaction(function () use ($tenant, $creator, $title, $description): Form {
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
