<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Enums\FormCollaboratorCapacity;
use App\Enums\FormStatus;
use App\Enums\FormVersionStatus;
use App\Models\Form;
use App\Models\FormCollaborator;
use App\Models\FormVersion;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Form creation (form-versioning-schema-migration.md §3.1). Creates the durable form, its initial draft
 * version (v1, empty snapshot), and the creator's editor collaborator row — in one transaction. Assumes
 * the caller has established the tenant DB context (BelongsToTenant auto-fills tenant_id; RLS is the
 * backstop).
 */
final class FormService
{
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

            // The creator gets an explicit editor collaborator row so `forms.edit.own` resolves for them
            // (data-dictionary §2 design note; RBAC §8). Owner/Admin get tenant-wide `.any` without a row.
            FormCollaborator::create([
                'form_id' => $form->id,
                'user_id' => $creator->id,
                'capacity' => FormCollaboratorCapacity::Editor,
                'added_by' => $creator->id,
            ]);

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
