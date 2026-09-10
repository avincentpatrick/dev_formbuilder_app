<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Enums\FieldType;
use App\Enums\RequiredMode;
use App\Exceptions\Forms\BuilderConflictException;
use App\Exceptions\Forms\FormException;
use App\Models\FieldLibrary;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormFieldValidation;
use App\Models\FormSection;
use App\Models\FormVersion;
use App\Models\User;
use App\Support\Tenancy\PlatformRowCounter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The interactive builder's fine-grained mutation surface (Increment D4a; form-versioning-schema-migration
 * §3/§8). Every operation edits the form's CURRENT DRAFT version only — the draft_child RLS guard is the DB
 * backstop, and {@see self::assertDraftChild()} is the clean service-level guard that turns a write against a
 * published version into a 422 instead of a silent zero-row write (M89: this said `403`, and the only caller
 * has mapped it to 422 since it was written).
 *
 * Concurrency model (§8):
 *  - CONTENT edits (updateField/updateSection) are optimistic: the client echoes the `updated_at` token it
 *    last read; a drift throws {@see BuilderConflictException} (→ 409) carrying the fresh row.
 *  - STRUCTURAL edits (add/delete/duplicate/reorder) are serialized by a `forms`-row FOR UPDATE lock and
 *    carry no token. Reorder writes via the query builder so it does NOT bump `updated_at` — a positional
 *    change must not spuriously 409 the next content PATCH on a moved row.
 *
 * Expressions (`relevant_expression`, validation `expression`) are persisted UNVALIDATED — the expression
 * engine is the deferred ADR-0004 work.
 */
final class FormBuilderService
{
    /** Optimistic-concurrency token format. The client always echoes back the exact string. */
    private const VERSION_FORMAT = 'Y-m-d\TH:i:s.uP';

    /**
     * The optimistic-concurrency token for a content row — its `updated_at`, at SECOND precision.
     *
     * The format string carries `.u`, but the underlying column does not: Laravel's
     * `Schema\Builder::$defaultTimePrecision` is 0 and this repo never overrides it, so `timestampsTz()`
     * emits `timestamp(0) with time zone` and the sub-second digits are always zeroes. Corrected here in
     * H25 (it read "microsecond precision", which was never true). The consequence is real and is NOT
     * fixed here because it is not R5: two builder edits landing inside the same second produce the same
     * token, so the second one is an undetectable lost update.
     */
    public static function rowVersion(Model $row): ?string
    {
        $updatedAt = $row->getAttribute('updated_at');

        return $updatedAt instanceof Carbon ? $updatedAt->format(self::VERSION_FORMAT) : null;
    }

    // ── Sections ────────────────────────────────────────────────────────────────

    public function addSection(Form $form): FormSection
    {
        return DB::transaction(function () use ($form): FormSection {
            $draft = $this->lockDraft($form);

            return FormSection::create([
                'form_version_id' => $draft->id,
                'key' => $this->uniqueKey($draft, 'section', 'form_sections'),
                'label' => 'New section',
                'sequence' => $this->nextSectionSequence($draft),
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateSection(Form $form, FormSection $section, array $data, ?string $expectedVersion): FormSection
    {
        $this->assertDraftChild($form, $section);
        $this->assertNoDrift($section, $expectedVersion);

        return DB::transaction(function () use ($form, $section, $data): FormSection {
            $this->assertStillDraftChild($form, $section);

            $section->fill([
                'key' => $data['key'],
                'label' => $data['label'],
                'description' => $data['description'] ?? null,
                'is_repeatable' => (bool) ($data['is_repeatable'] ?? false),
                'min_instances' => $data['min_instances'] ?? null,
                'max_instances' => $data['max_instances'] ?? null,
                'relevant_expression' => $data['relevant_expression'] ?? null,
            ])->save();

            return $section->refresh();
        });
    }

    public function deleteSection(Form $form, FormSection $section): void
    {
        DB::transaction(function () use ($form, $section): void {
            $this->lockDraft($form);
            // M90: the RE-READING guard, not the route-bound one. Holding the `forms` lock does not bound
            // this staleness — `lockDraft()` re-reads and returns a FRESH draft, so the lock says nothing
            // about the `$form` this guard compares. See the note on assertStillDraftChild().
            $this->assertStillDraftChild($form, $section);
            // The FK is ON DELETE SET NULL — the section's fields become ungrouped, not deleted.
            $section->delete();
        });
    }

    // ── Fields ──────────────────────────────────────────────────────────────────

    public function addField(Form $form, User $user, FieldType $type, ?string $sectionId): FormField
    {
        return DB::transaction(function () use ($form, $user, $type, $sectionId): FormField {
            $draft = $this->lockDraft($form);
            $section = $this->resolveDraftSectionId($draft, $sectionId);

            return FormField::create([
                'form_version_id' => $draft->id,
                'form_section_id' => $section,
                'key' => $this->uniqueKey($draft, 'field', 'form_fields'),
                'field_type' => $type,
                'label' => $type->label(),
                'is_required' => RequiredMode::Optional,
                'config' => $this->defaultConfig($type),
                'sequence' => $this->nextFieldSequence($draft),
                'created_by' => $user->id,
            ]);
        });
    }

    /**
     * ⛔ THE ONLY MUTATOR HERE THAT CAN REACH A FOREIGN-KEY VIOLATION, AND THE REASON IS THE LOCK.
     * Every deleter of a draft child — deleteField(), deleteSection(), PublishService, RestoreService,
     * XlsformImporter, FormService::archive() — takes the same `forms`-row FOR UPDATE lock, so the
     * structural mutators here are serialized against them and their check-then-write cannot race.
     * updateField() and updateSection() are the two that deliberately do NOT lock (§3.4 declines to
     * serialize content edits), and of those only this one writes foreign keys: replaceValidations()
     * INSERTs `form_field_id` and `related_form_field_id`, both declared `cascadeOnDelete()` and both
     * IMMEDIATE — there is no DEFERRABLE anywhere in database/migrations/.
     *
     * ⚠ assertStillDraftChild() narrows the window and cannot close it: it re-reads before the write,
     * so a delete committing between that re-read and the INSERT still reaches the constraint. What
     * escaped was SQLSTATE 23503, which no renderable claims — the builder's `fetch` got the
     * framework's generic JSON 500 and showed "Server Error" — instead of the 422 respond() exists
     * to produce.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateField(Form $form, FormField $field, User $user, array $data, ?string $expectedVersion): FormField
    {
        $this->assertDraftChild($form, $field);
        $this->assertNoDrift($field, $expectedVersion);

        try {
            return $this->writeField($form, $field, $user, $data);
        } catch (QueryException $e) {
            // The CODE, never the driver's message text or the constraint name: the house rule stated in
            // SavedReportViewService::guardName() and SubmissionPipeline, and the reason is locale
            // independence. Anything that is not the foreign key — 23505 on the key-uniqueness race,
            // 23514 on the rule XOR check, 42501 when RLS refuses the row outright — keeps the
            // behaviour it has today rather than being silently relabelled.
            if ((string) $e->getCode() !== '23503') {
                throw $e;
            }

            // WHICH of the two foreign keys lost its target, decided by a re-read rather than by parsing
            // the constraint name out of the driver's message. The catch sits OUTSIDE the transaction, so
            // the violation has already rolled back and the connection is usable again; under
            // RefreshDatabase DB::transaction() opened a SAVEPOINT, so the caller's transaction survives.
            throw $field->newQuery()->whereKey($field->getKey())->exists()
                ? FormException::relatedFieldRemovedDuringEdit()
                : FormException::childRemovedDuringEdit();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function writeField(Form $form, FormField $field, User $user, array $data): FormField
    {
        return DB::transaction(function () use ($form, $field, $user, $data): FormField {
            $this->assertStillDraftChild($form, $field);

            // ⛔ M91 — THE ACQUISITION ORDER, MADE UNCONDITIONAL. Without this the order of row touches
            // depends on whether the payload is DIRTY. Eloquent's save() checks isDirty() before it
            // issues anything, so resubmitting an identical payload skips the UPDATE on `form_fields`
            // entirely and this transaction's first lock lands on `form_field_validations` instead —
            // the reverse of the publisher's, which takes `form_fields` (PublishService, all rows in one
            // statement) and only then the validation rows. Two transactions, opposite order, and
            // Postgres answers 40P01. ⚠️ THAT IS NOT A RENDERABLE FAILURE HERE: updateField()'s typed
            // catch re-throws anything that is not 23503, so the loser reached the builder as the
            // framework's generic JSON 500 — the same unrendered answer M89 fixed for the constraint case.
            //
            // ⛔ AND THIS IS NOT A REVERSAL OF §3.4, WHICH IS THE FIRST THING A READER WILL ASSUME.
            // `docs/form-versioning-schema-migration.md` §3.4 declines the **forms**-row lock for ordinary
            // field-level edits and says §8 covers them at finer grain. This is that finer grain: one row,
            // the one this transaction is already about to write, and no `forms` row is touched. The
            // deliberate no-lock note on assertStillDraftChild() below is about the same forms-row lock
            // and is untouched.
            //
            // ⚠️ A LOCKING SELECT IS FILTERED BY THE UPDATE POLICY'S USING EXPRESSION RATHER THAN
            // ERRORING, so a row that has stopped being a draft child between the guard above and here
            // matches nothing — which is the same verdict the guard would have reached, reported through
            // the same exception rather than as a silent zero-row UPDATE.
            $locked = $field->newQuery()->whereKey($field->getKey())->lockForUpdate()->first();

            if (! $locked instanceof FormField) {
                throw FormException::childRemovedDuringEdit();
            }

            $field->fill([
                'key' => $data['key'],
                'label' => $data['label'],
                'hint' => $data['hint'] ?? null,
                'placeholder' => $data['placeholder'] ?? null,
                'is_required' => RequiredMode::from((string) $data['is_required']),
                'relevant_expression' => $data['relevant_expression'] ?? null,
                'appearance' => $data['appearance'] ?? null,
                'config' => $data['config'] ?? [],
                'default_value' => $data['default_value'] ?? null,
                'is_pii' => (bool) ($data['is_pii'] ?? false),
                'is_sensitive' => (bool) ($data['is_sensitive'] ?? false),
                'is_queryable' => (bool) ($data['is_queryable'] ?? false),
                'indexed_data_type' => ($data['is_queryable'] ?? false) ? ($data['indexed_data_type'] ?? null) : null,
                'updated_by' => $user->id,
            ])->save();

            $this->replaceValidations($field, $data['validations'] ?? []);

            return $field->refresh();
        });
    }

    public function deleteField(Form $form, FormField $field): void
    {
        DB::transaction(function () use ($form, $field): void {
            $this->lockDraft($form);
            // M90, and this is the worst-symptom member of the family. With the stale guard the two snapshots
            // agree with each other, `draft_delete`'s USING clause (which requires the parent version be
            // `draft`) matches ZERO rows, Eloquent's delete() returns true regardless, and the controller
            // answers `['deleted' => true]` with a 200 — the field vanishes from the builder and is still
            // there on reload. That is M88's headline symptom, verbatim, on the delete path.
            $this->assertStillDraftChild($form, $field);
            $field->delete(); // cascades its validations
        });
    }

    public function duplicateField(Form $form, User $user, FormField $field): FormField
    {
        return DB::transaction(function () use ($form, $user, $field): FormField {
            $draft = $this->lockDraft($form);
            // M90. This one is not a silent write — it is a 500. The INSERT below straddles two versions:
            // `form_version_id` is replicated from the STALE `$field` while the key and sequence come from
            // the FRESH `$draft`. When they disagree, `draft_insert`'s WITH CHECK raises 42501, and
            // FormBuilderController::respond() catches only BuilderConflictException and FormException, so
            // it escapes as a bare JSON 500. ⚠️ M89 deliberately declined to RELABEL 42501 (see the
            // QueryException catch in updateField()); this does not relabel it, it makes it unreachable.
            $this->assertStillDraftChild($form, $field);

            $copy = $field->replicate(['created_by', 'updated_by']);
            $copy->key = $this->copyKey($draft, $field->key);
            $copy->label = $field->label.' (copy)';
            $copy->sequence = $this->nextFieldSequence($draft);
            $copy->created_by = $user->id;
            $copy->updated_by = null;
            $copy->save();

            // Copy the source field's validation rows onto the new field (same version, new field id).
            foreach ($field->validations()->orderBy('sequence')->get() as $validation) {
                $newValidation = $validation->replicate();
                $newValidation->form_field_id = $copy->id;
                // A field→field comparison that pointed at the source keeps pointing at the same sibling.
                $newValidation->save();
            }

            return $copy->refresh();
        });
    }

    // ── Question library (Increment G9b) ──────────────────────────────────────────

    /**
     * Insert a field-library item into the form's current draft. Mirrors {@see addField}'s structural-edit
     * shape (transaction + form lock + resolve section), then delegates the row write to the shared
     * {@see SchemaBlueprintMaterializer::materializeField} (mints a collision-free key, appends at the end,
     * reverses logic-group ordinals). The item's blueprint is validated up front
     * ({@see BlueprintValidator::validateField}) because materializeField — unlike materializeInto — does not.
     * `usage_count` bumps through {@see PlatformRowCounter} for a platform (NULL-tenant) item, ordinary
     * Eloquent for a tenant-owned one (the same strict-RLS reasoning as {@see TemplateService}).
     */
    public function insertFromLibrary(Form $form, User $user, FieldLibrary $item, ?string $sectionId): FormField
    {
        return DB::transaction(function () use ($form, $user, $item, $sectionId): FormField {
            $draft = $this->lockDraft($form);
            $section = $this->resolveDraftSectionId($draft, $sectionId);

            $blueprint = $item->toBlueprintField();
            app(BlueprintValidator::class)->validateField($blueprint);

            $field = app(SchemaBlueprintMaterializer::class)->materializeField($draft, $blueprint, $section, $user);

            $this->bumpLibraryUsage($item);

            return $field;
        });
    }

    /**
     * Capture a draft field into the tenant's question library. Mirrors {@see TemplateService::saveAsTemplate}:
     * guard the field is in THIS form's draft, then create a tenant-owned row (the model omits BelongsToTenant,
     * so `tenant_id` is set explicitly). One-click save sends no meta → the item name defaults to the field
     * label ({@see FieldLibrary::fromField}).
     *
     * @param  array<string, mixed>  $meta  optional name/description/category
     */
    public function saveFieldToLibrary(Form $form, User $user, FormField $field, array $meta): FieldLibrary
    {
        // M90. No lock is added and that is deliberate: §3.4 scopes the `forms` lock to STRUCTURAL builder
        // edits and form-level transitions, and this writes no draft child at all — taking one here would be
        // a documented reversal rather than a fix. The re-read costs nothing and refuses for the same reason
        // its siblings do. ⚠️ The item this would have captured in the race is CONTENT-IDENTICAL, because
        // publish clones the tree forward unchanged; refusing is the consistent answer rather than the
        // corruption-preventing one, and it is recorded that way so nobody later reads more into it.
        $this->assertStillDraftChild($form, $field);

        return FieldLibrary::create([
            ...FieldLibrary::fromField($field, $meta),
            'tenant_id' => TenantContext::currentTenantId(),
            'created_by' => $user->id,
            'is_active' => true,
            'usage_count' => 0,
        ]);
    }

    private function bumpLibraryUsage(FieldLibrary $item): void
    {
        if ($item->tenant_id === null) {
            app(PlatformRowCounter::class)->increment('field_library', $item->id);

            return;
        }

        $item->increment('usage_count');
    }

    // ── Reorder (structural; serialized by the form-row lock, does NOT bump updated_at) ───────────

    /**
     * @param  list<array{id: string, sequence: int}>  $sections
     * @param  list<array{id: string, form_section_id: ?string, sequence: int, section_sequence: ?int}>  $fields
     */
    public function reorder(Form $form, array $sections, array $fields): void
    {
        DB::transaction(function () use ($form, $sections, $fields): void {
            $draft = $this->lockDraft($form);
            $validSections = FormSection::query()->where('form_version_id', $draft->id)->pluck('id')->flip();

            foreach ($sections as $row) {
                FormSection::query()->whereKey($row['id'])->where('form_version_id', $draft->id)
                    ->update(['sequence' => $row['sequence']]);
            }

            foreach ($fields as $row) {
                $targetSection = $row['form_section_id'];
                if ($targetSection !== null && ! $validSections->has($targetSection)) {
                    $targetSection = null; // never let a field point at a section from another version
                }

                FormField::query()->whereKey($row['id'])->where('form_version_id', $draft->id)->update([
                    'form_section_id' => $targetSection,
                    'sequence' => $row['sequence'],
                    'section_sequence' => $row['section_sequence'],
                ]);
            }
        });
    }

    // ── Guards & helpers ──────────────────────────────────────────────────────────

    /** Lock the form row (serializes structural edits, §3.4) and return its editable draft version. */
    private function lockDraft(Form $form): FormVersion
    {
        $locked = Form::query()->whereKey($form->id)->lockForUpdate()->firstOrFail();

        if ($locked->draft_version_id === null) {
            throw FormException::formHasNoDraft();
        }

        return FormVersion::query()->whereKey($locked->draft_version_id)->firstOrFail();
    }

    /** A section/field must belong to the form's current draft version — else it is immutable (§2/§4). */
    private function assertDraftChild(Form $form, FormSection|FormField $child): void
    {
        if ($form->draft_version_id === null || $child->form_version_id !== $form->draft_version_id) {
            throw FormException::childNotInDraft();
        }
    }

    /**
     * The same question, re-decided INSIDE the transaction and from the database (Increment M88).
     *
     * ⛔ {@see self::assertDraftChild()} READS THE ROUTE-BOUND MODELS, WHICH IS A SNAPSHOT FROM
     * BEFORE THE REQUEST DID ANYTHING. `$form->draft_version_id` is whatever it was when route-model
     * binding loaded it, so if a publish, restore, XLSForm import or archive commits between that
     * load and this write, the guard compares two stale values, agrees with itself, and lets the
     * write through. `tests/Feature/Forms/BuilderDraftGuardTest`'s own header says that guard
     * "turns a write against a published version into a 422 instead of a silent zero-row write" —
     * true only when the version was ALREADY published at bind time, which is the case it tested.
     *
     * ⚠️ WHAT THE CALLER ACTUALLY SAW WITHOUT THIS. The `draft_child` RLS policy makes the UPDATE
     * match zero rows, `save()` reports success either way, and `updateField()` returns
     * `$field->refresh()` — so the builder received **200 OK carrying the pre-edit values** and the
     * user watched their edit revert with no error at all. That is the defect this closes.
     *
     * ⛔ NO LOCK, DELIBERATELY, AND THE PRECEDENT IS M85's. `docs/form-versioning-schema-migration.md`
     * §3.4 states that the `forms` row lock serializes create/publish/discard/restore and
     * "does not serialize ordinary field-level edits" — a written decision, not an oversight, so
     * adding {@see self::lockDraft()} here would be a reversal of it rather than a bug fix. A plain
     * re-read is enough: READ COMMITTED re-evaluates each statement, so a transaction that starts
     * after the other side commits sees the new `draft_version_id` and the deleted child. M85 closed
     * the promote door the same way, with a re-read and no lock.
     *
     * ⚠️ WHAT IT DOES NOT CLOSE, STATED SO THE NEXT READER DOES NOT ASSUME OTHERWISE: an edit that
     * commits *inside* a publish transaction — after its snapshot read and before its commit — still
     * lands on rows the frozen `schema_snapshot` and `checksum` no longer describe. That window is
     * lock-shaped and is filed as its own row.
     *
     * ── M90: SIX CALL SITES, NOT TWO, AND THE FOUR ADDED ARE NOT ALL THE SAME DEFECT ──────────────
     * M88 routed only `updateField()` and `updateSection()` through here. The backlog row that filed
     * the rest named two more and excluded the locking pair on the ground that *"the locking siblings'
     * exposure is bounded by their lock"*. ⛔ THAT REASON IS FALSE: {@see self::lockDraft()} re-reads
     * under `FOR UPDATE` and returns a FRESH draft, so the lock bounds nothing about a `$form` that
     * went stale at route-model binding — the two are different objects. The four sites differ in
     * SYMPTOM, which is why each carries its own note rather than a shared one:
     *
     *   deleteSection() · deleteField()  a silent zero-row DELETE under `draft_delete`'s USING clause,
     *                                    answered `['deleted' => true]` with a 200. M88's headline
     *                                    symptom exactly, on the delete path.
     *   duplicateField()                 NOT silent — a straddled INSERT, `draft_insert`'s WITH CHECK,
     *                                    42501, and out through respond() as a bare 500.
     *   saveFieldToLibrary()             no corruption at all: publish clones the tree forward
     *                                    unchanged, so the captured item is content-identical either
     *                                    way. It refuses for consistency, not for safety.
     */
    private function assertStillDraftChild(Form $form, FormSection|FormField $child): void
    {
        $current = Form::query()->whereKey($form->id)->first();

        if ($current === null || $current->draft_version_id === null) {
            throw FormException::childNotInDraft();
        }

        // The child is re-read too, and not only the form: a restore, an import or a delete removes
        // the row while leaving `draft_version_id` untouched, so the form alone cannot see it.
        $live = $child->newQuery()->whereKey($child->getKey())->first();

        if (! $live instanceof FormSection && ! $live instanceof FormField) {
            // REMOVED, not published. M88 raised childNotInDraft() here, whose message names a
            // publication that did not happen; the two events are told apart from M89 on.
            throw FormException::childRemovedDuringEdit();
        }

        if ($live->form_version_id !== $current->draft_version_id) {
            throw FormException::childNotInDraft();
        }
    }

    private function assertNoDrift(FormSection|FormField $row, ?string $expectedVersion): void
    {
        if ($expectedVersion !== null && self::rowVersion($row) !== $expectedVersion) {
            throw new BuilderConflictException($row);
        }
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function replaceValidations(FormField $field, array $rows): void
    {
        $field->validations()->delete();

        $siblingIdByKey = FormField::query()
            ->where('form_version_id', $field->form_version_id)
            ->pluck('id', 'key');

        foreach ($rows as $index => $row) {
            $relatedKey = $row['related_field_key'] ?? null;

            FormFieldValidation::create([
                'form_version_id' => $field->form_version_id,
                'form_field_id' => $field->id,
                'related_form_field_id' => $relatedKey !== null ? ($siblingIdByKey[$relatedKey] ?? null) : null,
                'rule_type' => $row['rule_type'] ?? null,
                'operator' => $row['operator'] ?? null,
                'rule_value' => $row['rule_value'] ?? null,
                'expression' => $row['expression'] ?? null,
                'error_message' => $row['error_message'] ?? null,
                'sequence' => $index,
            ]);
        }
    }

    private function resolveDraftSectionId(FormVersion $draft, ?string $sectionId): ?string
    {
        if ($sectionId === null) {
            return null;
        }

        return FormSection::query()->whereKey($sectionId)->where('form_version_id', $draft->id)->exists()
            ? $sectionId
            : null;
    }

    /** @return array<string, mixed> */
    private function defaultConfig(FieldType $type): array
    {
        return match (true) {
            $type === FieldType::CascadingSelect => ['levels' => [], 'options' => []],
            $type === FieldType::Matrix => ['rows' => [], 'columns' => [], 'cells' => []],
            $type === FieldType::LikertMatrix => ['rows' => [], 'columns' => []],
            $type->hasOptions() => ['options' => []],
            default => [],
        };
    }

    private function nextFieldSequence(FormVersion $draft): int
    {
        return (int) FormField::query()->where('form_version_id', $draft->id)->max('sequence') + 1;
    }

    private function nextSectionSequence(FormVersion $draft): int
    {
        return (int) FormSection::query()->where('form_version_id', $draft->id)->max('sequence') + 1;
    }

    /** A stable slug unique within the draft version (the composite unique index enforces it too). */
    private function uniqueKey(FormVersion $draft, string $base, string $table): string
    {
        $taken = DB::table($table)->where('form_version_id', $draft->id)->pluck('key')->flip();

        $n = 1;
        while ($taken->has($candidate = "{$base}_{$n}")) {
            $n++;
        }

        return $candidate;
    }

    private function copyKey(FormVersion $draft, string $sourceKey): string
    {
        $taken = FormField::query()->where('form_version_id', $draft->id)->pluck('key')->flip();

        $candidate = "{$sourceKey}_copy";
        $n = 2;
        while ($taken->has($candidate)) {
            $candidate = "{$sourceKey}_copy_{$n}";
            $n++;
        }

        return $candidate;
    }
}
