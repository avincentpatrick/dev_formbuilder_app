<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\ResourceGrant;
use App\Policies\ResourceGrantPolicy;

/**
 * What a {@see ResourceGrant} row grants its holder (multi-tenancy-rbac-design.md §8, Increment G10a).
 * The direct successor to `FormCollaboratorCapacity`, generalized off `forms` onto any scopeable
 * resource: `editor` → `forms.edit.own`/`forms.publish.own`, `reviewer` → `submissions.review.own`.
 * A person holds exactly one capacity per target (the `resource_grants` unique key deliberately
 * excludes `capacity`, so a demotion REPLACES the row rather than leaving edit rights standing).
 *
 * The backing values are byte-identical to the retired `FormCollaboratorCapacity` on purpose — the
 * G10a backfill copies `form_collaborators.capacity` straight across with no translation step, and the
 * `resource_grants_capacity_check` CHECK constraint pins the same two literals at the database.
 */
enum ResourceCapacity: string
{
    case Editor = 'editor';
    case Reviewer = 'reviewer';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * The tenant-wide `.any` permission corresponding to what this capacity confers per-resource
     * (Increment G10b). Lives here so the capacity → permission mapping stays in one place: the class
     * docblock above already names the `.own` half, and the anti-amplification rule in
     * {@see ResourceGrantPolicy} needs the `.any` half.
     *
     * Used to enforce "you cannot hand out an authority you do not hold yourself" — a grant activates the
     * `.own` permission for its holder, so the granter must hold the `.any` counterpart.
     */
    public function anyPermission(): string
    {
        return match ($this) {
            self::Editor => 'forms.edit.any',
            self::Reviewer => 'submissions.review.any',
        };
    }
}
