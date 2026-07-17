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
     * A builder mutation targeted a section/field that is not part of the form's current draft version
     * (i.e. it belongs to a published/superseded version). The draft_child RLS guard is the DB backstop;
     * this is the service-level guard that returns a clean 403 instead of a silent zero-row write.
     */
    public static function childNotInDraft(): self
    {
        return new self('That item belongs to a published version and can no longer be edited.');
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
