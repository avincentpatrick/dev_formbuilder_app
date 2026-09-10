<?php

declare(strict_types=1);

namespace App\Exceptions\Forms;

use App\Services\Forms\BlueprintValidator;
use RuntimeException;

/**
 * A form-lifecycle business-rule violation (form-versioning-schema-migration.md §3, §6, §9) — publishing
 * a form with no draft, restoring from a version that belongs to another form, restoring onto an
 * archived form, etc. Distinct from the pre-publish structural gate ({@see PublishValidationException})
 * and from an authorization failure (403 from FormPolicy).
 */
final class FormException extends RuntimeException
{
    public static function noDraftToPublish(): self
    {
        return new self('This form has no draft version to publish.');
    }

    public static function versionNotPublishable(): self
    {
        return new self('Only a draft version can be published.');
    }

    public static function restoreSourceNotAVersionOfThisForm(): self
    {
        return new self('That version does not belong to this form.');
    }

    public static function cannotRestoreOntoArchivedForm(): self
    {
        return new self('Restore a version by first un-archiving the form.');
    }

    public static function cannotImportOntoArchivedForm(): self
    {
        return new self('Import an XLSForm by first un-archiving the form.');
    }

    public static function restoreSourceMustBePublished(): self
    {
        return new self('Only a published or superseded version can be restored.');
    }

    /**
     * A scheduled-form window (Increment H12a) was set with an open time at or after its close time — the
     * service-level backstop behind the request's `closes_at` `after:opens_at` rule.
     */
    public static function invalidSchedule(): self
    {
        return new self("A form's open time must be before its close time.");
    }

    /**
     * A builder mutation targeted a section/field that is not part of the form's current draft version
     * (i.e. it belongs to a published/superseded version). The draft_child RLS guard is the DB backstop;
     * this is the service-level guard that returns a clean 422 instead of a silent zero-row write.
     *
     * ⚠ The `403` this docblock claimed until M89 was never what the surface returned: the only caller,
     * FormBuilderController::respond(), has mapped this type to 422 since it was written. The same stale
     * `403` stood in FormBuilderService's header docblock and is corrected there too.
     */
    public static function childNotInDraft(): self
    {
        return new self('That item belongs to a published version and can no longer be edited.');
    }

    /**
     * The row this builder edit is writing was REMOVED by a concurrent editor — a delete, a restore, an
     * import or an archive — rather than published. Distinct from {@see self::childNotInDraft()} because
     * the cause and the user's next move are different: nothing was published, and refetching the builder
     * will show the row simply gone.
     *
     * Raised from two places that observe the same event at different instants: the pre-write re-read in
     * FormBuilderService::assertStillDraftChild(), and — when the delete commits after that re-read
     * — the SQLSTATE 23503 mapping around the write itself.
     */
    public static function childRemovedDuringEdit(): self
    {
        return new self('That item was removed by another editor. Refresh the builder and try again.');
    }

    /**
     * A validation row on this field points at ANOTHER field (`related_form_field_id`) that a concurrent
     * editor removed. The edited field itself is fine, which is why this is not
     * {@see self::childRemovedDuringEdit()}: naming the wrong row would send the user to look at a field
     * that is still there.
     */
    public static function relatedFieldRemovedDuringEdit(): self
    {
        return new self('A field this rule refers to was removed by another editor. Refresh the builder and try again.');
    }

    public static function formHasNoDraft(): self
    {
        return new self('This form has no editable draft. Publish or restore a version first.');
    }

    /**
     * A schema blueprint (a template's `schema_blueprint`, or a field-library item's field shape) failed the
     * upfront structural check before materializing into rows — an unknown field_type, a duplicate or dangling
     * key, or a malformed top-level shape. Thrown by {@see BlueprintValidator}.
     */
    public static function invalidBlueprint(string $reason): self
    {
        return new self($reason);
    }
}
