<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Enums\AuditEvent;
use App\Enums\FormStatus;
use App\Enums\FormVersionStatus;
use App\Events\FormPublished;
use App\Exceptions\Forms\FormException;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\User;
use App\Support\Audit\AuditLogger;
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
        private readonly ExpressionValidationGate $expressionGate,
        private readonly SchemaChangeClassifier $classifier,
        private readonly SchemaSnapshotSerializer $serializer,
        private readonly SchemaTreeCloner $cloner,
        private readonly CapabilityFlags $capabilityFlags,
        private readonly AuditLogger $audit,
    ) {}

    public function publish(Form $form, User $publisher, ?string $note = null): FormVersion
    {
        $published = DB::transaction(function () use ($form, $publisher, $note): FormVersion {
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

            // 1. Validate the draft (throws the specific §4 violation) — structure, then expressions (F3).
            $this->gate->assertPublishable($draft);
            $this->expressionGate->assertExpressionsResolve($draft);
            // 2. Classify the change.
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

            // Audit the publish IN this transaction (technical-architecture §4.1 — the ledger row is atomic
            // with the version flip, so a rolled-back publish leaves no `published` audit). The most
            // consequential event this schema tracks (spec §1: form_version → published).
            $this->audit->record(
                AuditEvent::Published,
                'form_version',
                (string) $draft->id,
                old: ['status' => FormVersionStatus::Draft->value],
                new: [
                    'status' => FormVersionStatus::Published->value,
                    'version_number' => $draft->version_number,
                    'checksum' => $checksum,
                ],
                actorId: (string) $publisher->getKey(),
            );

            return $draft->refresh();
        });

        // The post-commit domain event (technical-architecture §7.4) — the H13 webhook + notification seam,
        // raised AFTER the transaction commits so `form.published` never fires for a publish that rolled
        // back. Carries a scalar envelope, so a queued listener is §D5-safe. This is the SECOND record of
        // one action: the audit above is the in-transaction ledger; this is the outbound announcement.
        event(FormPublished::for($published, $publisher));

        return $published;
    }
}
