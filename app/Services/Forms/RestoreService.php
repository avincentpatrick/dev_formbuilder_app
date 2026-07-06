<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Enums\FormStatus;
use App\Enums\FormVersionStatus;
use App\Exceptions\Forms\FormException;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Version restore / "rollback" (form-versioning-schema-migration.md §6) — a draft-population convenience,
 * NOT a special publish path. It overwrites the current draft's content with a copy of an old
 * published/superseded version's content (same keys), then returns; the caller reviews the restored draft
 * and publishes it through the ordinary {@see PublishService} path (forward-only — a restore never reuses
 * the source version's old number). Destructive to the current draft, never to history.
 *
 * @see PublishService for the actual publish that follows a restore.
 */
final class RestoreService
{
    public function __construct(private readonly SchemaTreeCloner $cloner) {}

    /** @param  User  $actor  reserved for the audit actor (emission deferred until the audits table lands). */
    public function restore(Form $form, FormVersion $source, User $actor): FormVersion
    {
        return DB::transaction(function () use ($form, $source): FormVersion {
            // Same form-row lock as the other three lifecycle transitions (§3.4).
            $locked = Form::query()->whereKey($form->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === FormStatus::Archived) {
                throw FormException::cannotRestoreOntoArchivedForm();
            }
            if ($source->form_id !== $locked->id) {
                throw FormException::restoreSourceNotAVersionOfThisForm();
            }
            if ($source->status === FormVersionStatus::Draft) {
                throw FormException::restoreSourceMustBePublished();
            }
            if ($locked->draft_version_id === null) {
                throw FormException::noDraftToPublish();
            }

            $draft = FormVersion::query()->whereKey($locked->draft_version_id)->firstOrFail();

            // Clear the current draft's content (validations → fields → sections), then clone the source in.
            $draft->validations()->delete();
            $draft->fields()->delete();
            $draft->sections()->delete();

            $this->cloner->clone($source, $draft);

            return $draft->refresh();
        });
    }
}
