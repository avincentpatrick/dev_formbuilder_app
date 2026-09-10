<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Enums\AuditEvent;
use App\Enums\FormStatus;
use App\Enums\FormVersionStatus;
use App\Events\FormPublished;
use App\Exceptions\Forms\FormException;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormFieldValidation;
use App\Models\FormSection;
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
        private readonly TemplateValidationGate $templateGate,
        private readonly SchemaChangeClassifier $classifier,
        private readonly SchemaSnapshotSerializer $serializer,
        private readonly SchemaTreeCloner $cloner,
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

            // 0. Lock the CHILD rows this transaction is about to freeze (M89).
            //
            // ⛔ THE `forms` LOCK ABOVE DOES NOT REACH THEM, AND §3.4 IS WHY THAT MATTERS. It declines
            // to serialize field-level edits, so a collaborator's updateField() runs lock-free and
            // concurrently. Under READ COMMITTED its RLS `EXISTS (... fv.status = 'draft')` is evaluated
            // per statement against the COMMITTED snapshot, and this version is committed-`draft` at every
            // instant before this transaction commits — so RLS refuses nothing, and an edit landing
            // between the reads below and the commit produces a published version whose frozen
            // `schema_snapshot` and `checksum` predate a row that belongs to it.
            //
            // ⛔ IT GOES HERE, BEFORE THE GATES, AND NOT AT THE SNAPSHOT. Row locks are held to the end
            // of the transaction, so one acquisition covers the gates, the classifier, the serializer and
            // the cloner. Locking at the snapshot instead would leave the gates reading rows that can still
            // move — publishing a snapshot that never passed the gate it was supposed to pass.
            //
            // ⛔ IT CANNOT GO IN SchemaTreeCloner OR SchemaSnapshotSerializer, AND BOTH FAIL SILENTLY.
            // Postgres applies the UPDATE policy's USING expression to a locking SELECT as a FILTER rather
            // than an error. The cloner runs AFTER the status flip below, so it would see its own
            // uncommitted `published`, match zero rows and clone an EMPTY tree with no error at all. The
            // serializer has three other callers — TemplateService and XlsformExporter, both outside any
            // transaction, the latter reading PUBLISHED versions — which would take a lock they
            // instantly release and export an empty workbook. The test arm pins both.
            //
            // A phantom INSERT is not covered by FOR UPDATE, and does not need to be: every insert path
            // into these tables takes the `forms` lock first, except replaceValidations(), whose INSERT
            // takes FOR KEY SHARE on the referenced form_fields row and therefore blocks on this.
            //
            // ⛔ M91 — THAT LAST CLAUSE STOPPED ONE STEP SHORT, AND THE STEP IT MISSED WAS A DEADLOCK.
            // "Blocks on this" is true only if the builder reaches its INSERT AFTER this statement
            // ran. It did not have to: Eloquent skips a clean save(), so a resubmitted identical
            // payload took no `form_fields` lock at all and went straight to the validation rows —
            // this transaction then held every field and wanted the validations, while the builder
            // held a validation row and wanted a field. 40P01, and the builder's typed catch rethrows
            // anything that is not 23503, so it surfaced as an unrendered 500. writeField() now takes
            // its field row before it touches any child, which is what makes the clause above true
            // unconditionally; tests/Feature/Forms/BuilderLockOrderTest.php is what keeps it true.
            //
            // ⛔ M92 — TABLE ORDER WAS NEVER THE WHOLE ORDER, AND THE SECOND CYCLE RAN INSIDE ONE TABLE.
            // M91 closed the cycle where the two sides disagreed about which TABLE to touch first. It
            // left the one where they agree on the table and disagree on the ROW. These three statements
            // each locked a whole child set with no ORDER BY, so the acquisition order was the plan's
            // scan order; the builder meanwhile locks its OWN field row and then, through
            // replaceValidations()'s `related_form_field_id` foreign key, takes FOR KEY SHARE on a
            // SIBLING field row. Publisher grabs Y then blocks on X while the builder holds X and wants
            // Y — 40P01, inside `form_fields` alone, reachable only through a cross-field validation
            // rule. Both sides now acquire `form_fields` in ascending id order and cannot interleave.
            //
            // ⚠️ ORDER BY CONTROLS THE LOCK ORDER HERE, AND THAT IS MEASURED RATHER THAN ASSUMED.
            // Postgres locks rows as they are pulled from the plan, so a sort that happened BELOW the
            // locking node would order the output and not the acquisition. `EXPLAIN` for this statement
            // plans `LockRows` ABOVE `Sort` — the lock is applied to the sorted stream. The arm in
            // tests/Feature/Forms/PublishLockingTest.php pins the clause; nothing can pin the plan, so
            // it is recorded here.
            //
            // ⚠️ All three take it, not just `form_fields`. Only the field set is in the measured cycle,
            // but an unordered bulk lock is the shape of the defect rather than one instance of it, and
            // a rule with an exception is the one a later reader breaks.
            FormSection::query()->where('form_version_id', $draft->id)->orderBy('id')->lockForUpdate()->pluck('id');
            FormField::query()->where('form_version_id', $draft->id)->orderBy('id')->lockForUpdate()->pluck('id');
            FormFieldValidation::query()->where('form_version_id', $draft->id)->orderBy('id')->lockForUpdate()->pluck('id');

            // 1. Validate the draft (throws the specific §4 violation) — structure, then expressions (F3),
            //    then templates (H6a). The template gate runs HERE so step 1 stays the single validation
            //    phase and a doomed publish is never serialized; the COMMITTED-snapshot guarantee comes
            //    from this transaction, not from the ordering. It takes the locked form too:
            //    `forms.confirmation_message` is a form-level column, so it is not frozen per version and
            //    its holes are validated against the version being published (Doc #26 §6.2 as amended).
            $this->gate->assertPublishable($draft);
            $this->expressionGate->assertExpressionsResolve($draft);
            $this->templateGate->assertTemplatesResolve($draft, $locked);
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

            // 8. Point the form at the new published version + recompute capability flags FROM THE
            //    SNAPSHOT frozen at step 3 — the same bytes the H8 retroactive backfill and H18a's
            //    eligibility check read later, so what we advertise and what we froze cannot disagree.
            $locked->forceFill([
                'current_published_version_id' => $draft->id,
                'status' => FormStatus::Published,
                'capability_flags' => CapabilityFlags::forSnapshot($snapshot),
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
