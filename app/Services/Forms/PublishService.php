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
 * The publish transaction (form-versioning-schema-migration.md §3.2) — the 9 steps in order, under a
 * `SELECT ... FOR UPDATE` lock on the owning form (§3.4) that serializes create/publish/discard/restore
 * against the same form. Step order is load-bearing: the snapshot is taken while the version is still a
 * draft; the version is flipped to published; the prior published version is superseded; the just-published
 * structure is cloned forward into a brand-new draft so editing can continue immediately.
 */
final class PublishService
{
    public function __construct(
        private readonly StructuralValidationGate $gate,
        private readonly SchemaChangeClassifier $classifier,
        private readonly SchemaSnapshotSerializer $serializer,
        private readonly SchemaTreeCloner $cloner,
        private readonly CapabilityFlags $capabilityFlags,
    ) {}

    public function publish(Form $form, User $publisher, ?string $note = null): FormVersion
    {
        return DB::transaction(function () use ($form, $publisher, $note): FormVersion {
            // §3.4 — lock the form row for the transaction's duration.
            $locked = Form::query()->whereKey($form->id)->lockForUpdate()->firstOrFail();

            if ($locked->draft_version_id === null) {
                throw FormException::noDraftToPublish();
            }

            $draft = FormVersion::query()->whereKey($locked->draft_version_id)->firstOrFail();
            if ($draft->status !== FormVersionStatus::Draft) {
                throw FormException::versionNotPublishable();
            }

            $currentPublished = $locked->current_published_version_id !== null
                ? FormVersion::query()->whereKey($locked->current_published_version_id)->first()
                : null;

            // 1. Validate the draft (throws the specific §4 violation). 2. Classify the change.
            $this->gate->assertPublishable($draft);
            $classification = $this->classifier->classify($draft, $currentPublished);

            // 3+4. Snapshot + checksum — while the version is STILL a draft (its content is readable/writable).
            $snapshot = $this->serializer->snapshot($draft);
            $checksum = $this->serializer->checksumOf($snapshot);

            // 5. change_summary = generated classification, then the optional publisher note appended.
            $summary = $classification->changeSummary();
            if ($note !== null && trim($note) !== '') {
                $summary .= "\n\n".trim($note);
            }

            // 6. Publish this version (version_number was reserved at draft creation).
            $draft->forceFill([
                'status' => FormVersionStatus::Published,
                'schema_snapshot' => $snapshot,
                'checksum' => $checksum,
                'change_summary' => $summary,
                'published_at' => now(),
                'published_by' => $publisher->id,
            ])->save();

            // 7. Supersede the prior published version — a guarded no-op on the first publish.
            $currentPublished?->forceFill([
                'status' => FormVersionStatus::Superseded,
                'superseded_at' => now(),
            ])->save();

            // 8. Point the form at the new published version + recompute capability flags.
            $locked->forceFill([
                'current_published_version_id' => $draft->id,
                'status' => FormStatus::Published,
                'capability_flags' => $this->capabilityFlags->compute($draft),
                'published_at' => $locked->published_at ?? now(),
            ])->save();

            // 9. Clone the just-published structure forward into a brand-new draft (same keys, new ids,
            //    version_number + 1) so builder editing continues without touching the published version.
            $newDraft = FormVersion::create([
                'form_id' => $locked->id,
                'version_number' => $draft->version_number + 1,
                'status' => FormVersionStatus::Draft,
                'title' => $draft->title,
                'description' => $draft->description,
                'schema_snapshot' => [],
            ]);
            $this->cloner->clone($draft, $newDraft);
            $locked->forceFill(['draft_version_id' => $newDraft->id])->save();

            // TODO(audits/webhooks, Phase 1): emit AuditEvent.published + the form.published webhook once
            // those tables land (data-dictionary §13/§15), inside this transaction (technical-architecture
            // §7 — never pre-commit). Deferred, same pattern as TenantMembershipService.

            return $draft->refresh();
        });
    }
}
