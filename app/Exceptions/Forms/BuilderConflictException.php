<?php

declare(strict_types=1);

namespace App\Exceptions\Forms;

use App\Models\FormField;
use App\Models\FormSection;
use RuntimeException;

/**
 * Optimistic-concurrency drift on a builder content edit (form-versioning-schema-migration.md §8). The
 * client PATCHed a field/section carrying the `updated_at` token it last read, but the row has since been
 * changed by someone else. The mutation is refused with HTTP 409 and the CURRENT server state is attached
 * so the controller can hand it back — the builder's ConflictDialog preserves the user's in-flight value
 * and offers merge/overwrite rather than silently discarding either side.
 */
final class BuilderConflictException extends RuntimeException
{
    public function __construct(public readonly FormField|FormSection $current)
    {
        parent::__construct('This item was changed elsewhere since you last loaded it.');
    }
}
