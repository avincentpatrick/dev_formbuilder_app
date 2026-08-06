<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The audit event vocabulary (data-dictionary §13, audit-compliance-logging-spec.md §1). Eight values:
 * the four base CRUD-lifecycle events legacy's `Auditable` trait carried (`created`/`updated`/`deleted`/
 * `restored`) plus four domain-specific events this schema's versioning, export and RBAC model need
 * tracked explicitly (`published`/`archived`/`exported`/`permission_changed`).
 *
 * This is DELIBERATELY a different namespace from {@see DomainEventType} (the dotted `submission.created`/
 * `form.published` vocabulary that webhooks + notifications consume) and from `NotificationType`: an audit
 * row records *what changed on which model* for the compliance ledger, a domain event announces *something
 * happened* to post-commit consumers. The publish action, for instance, writes an `AuditEvent::Published`
 * row AND dispatches a `DomainEventType::FormPublished` event — the same action, two orthogonal records.
 *
 * The backing values are pinned at the database by the `audits_event_check` CHECK, generated from
 * {@see self::values()} so the enum and the constraint cannot drift.
 */
enum AuditEvent: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
    case Restored = 'restored';
    case Published = 'published';
    case Archived = 'archived';
    case Exported = 'exported';
    case PermissionChanged = 'permission_changed';

    /**
     * Human label for the audit-log filter catalog, the viewer's event badge, and the CSV/XLSX export
     * column — one string, three surfaces (I2). Mirrors {@see SubmissionStatus::label()}.
     *
     * There is deliberately NO companion `badgeVariant()` here. Colour is presentation the export has no
     * opinion about, and — more importantly — in a compliance ledger every row is a *lawful recorded act*:
     * a `deleted => danger` mapping baked into the enum would paint expected administration red at every
     * consumer. The viewer owns its own coarse consequence bands
     * (`resources/js/components/audit/event-variant.ts`); the label is the part that must not diverge.
     */
    public function label(): string
    {
        return match ($this) {
            self::Created => 'Created',
            self::Updated => 'Updated',
            self::Deleted => 'Deleted',
            self::Restored => 'Restored',
            self::Published => 'Published',
            self::Archived => 'Archived',
            self::Exported => 'Exported',
            self::PermissionChanged => 'Permission changed',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
