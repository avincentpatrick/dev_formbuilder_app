<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Enums\AuditEvent;
use App\Enums\FormStatus;
use App\Enums\FormVersionStatus;
use App\Exceptions\Forms\FormException;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\User;
use App\Support\Audit\AuditLogger;
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
    public function __construct(
        private readonly SchemaTreeCloner $cloner,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  User  $actor  the audit actor — emitted since I2 (the parameter was accepted and unused before,
     *                       and was notably absent from the closure's `use()` list, which is the shape of that gap)
     */
    public function restore(Form $form, FormVersion $source, User $actor): FormVersion
    {
        return DB::transaction(function () use ($form, $source, $actor): FormVersion {
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

            // `auditable_type = 'form'`, not `form_version`: spec §1 assigns `restored` to `form` and gives
            // `form_version` only `published`. The row names the draft that was overwritten and the version
            // it was overwritten FROM, which is the whole fact.
            //
            // `old` is deliberately null. The prior draft's CONTENT is not representable as a few scalars,
            // and putting a schema snapshot on the ledger is exactly the noise §1's "Deliberately NOT
            // audited" clause forbids ("a pile of intermediate draft-edit audit rows"). The verb carries the
            // meaning; the version numbers carry the fact.
            $this->audit->record(
                AuditEvent::Restored,
                'form',
                (string) $locked->getKey(),
                new: [
                    'draft_version_id' => (string) $draft->getKey(),
                    'draft_version_number' => $draft->version_number,
                    'source_version_id' => (string) $source->getKey(),
                    'source_version_number' => $source->version_number,
                ],
                actorId: (string) $actor->getKey(),
            );

            return $draft->refresh();
        });
    }
}
