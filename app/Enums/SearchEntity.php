<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The entity kinds global search can return (Increment J1b).
 *
 * The set is `docs/PRD.md` §3.7's, verbatim and deliberately: "forms, submissions, members, and settings".
 * J1b ships the first two; J1c adds `Member` and `Destination` (the settings/navigation catalog).
 *
 * ⚠️ AUDIT ROWS ARE NOT ON THIS LIST AND ADDING THEM IS A WIDENING, NOT A COMPLETION. `audits` is
 * append-only, Owner/Admin-only, and its free text lives in `old_values`/`new_values` — jsonb whose contents
 * `AuditRedactor` deliberately masks. Full-text search over a compliance ledger's diff payload would be a new
 * disclosure channel for exactly the values that redaction exists to remove, and
 * `docs/multi-tenancy-rbac-design.md` already records a decision NOT to build cross-tenant audit search when
 * it was one line away. If someone later wants audit search, it is its own increment with its own argument.
 */
enum SearchEntity: string
{
    case Form = 'form';
    case Submission = 'submission';

    public function label(): string
    {
        return match ($this) {
            self::Form => 'Forms',
            self::Submission => 'Submissions',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
