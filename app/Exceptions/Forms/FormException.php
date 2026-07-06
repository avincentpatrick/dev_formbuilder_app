<?php

declare(strict_types=1);

namespace App\Exceptions\Forms;

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

    public static function restoreSourceMustBePublished(): self
    {
        return new self('Only a published or superseded version can be restored.');
    }
}
