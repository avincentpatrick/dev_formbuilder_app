<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The audit event vocabulary (data-dictionary §13, audit-compliance-logging-spec.md §1). Ten values:
 * the four base CRUD-lifecycle events legacy's `Auditable` trait carried (`created`/`updated`/`deleted`/
 * `restored`), four domain-specific events this schema's versioning, export and RBAC model need tracked
 * explicitly (`published`/`archived`/`exported`/`permission_changed`), and the two impersonation
 * boundaries added by I11b (`impersonation_started`/`impersonation_ended`).
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
     * The two impersonation boundaries (I11b, rbac §9's resolved decision 1).
     *
     * These are the only cases in this enum that describe an ACCESS rather than a change to a model — the
     * `auditable` is the impersonated user and neither row carries `old_values`/`new_values`. That is the
     * point: §9's transparency posture is that the affected tenant learns platform staff were in their
     * workspace, and "nothing was edited" is exactly the case where no other audit row would ever appear.
     *
     * Both rows are written with `user_id` = the TARGET and `acting_as_user_id` = the OPERATOR, under the
     * target tenant's context, so they read uniformly with every action taken in between and satisfy
     * `audits_acting_as_not_self_check` for free.
     */
    case ImpersonationStarted = 'impersonation_started';
    case ImpersonationEnded = 'impersonation_ended';

    /**
     * An administrative clearing of somebody's two-factor enrolment (`M107`, `D37`).
     *
     * Like the two impersonation boundaries above, this records an ACCESS decision rather than a change a
     * model would otherwise report, and it carries no `old_values`/`new_values`: the interesting fact is
     * *that the enrolment was cleared and by whom*, and the secret it cleared must never reach the ledger.
     *
     * ⚠️ IT IS WRITTEN FROM TWO SURFACES WITH THE SAME SHAPE — a workspace owner on the tenant host and the
     * platform operator in the console — because `D37` answered both. The `auditable` is the TARGET user
     * under the target tenant's context, so an owner reading their own `/audit-log` sees the reset whoever
     * performed it, which is the same transparency posture rbac §9 takes for impersonation.
     */
    case TwoFactorReset = 'two_factor_reset';

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
            // Named from the TENANT's point of view, because the tenant's own /audit-log is the surface
            // §9 requires these to appear on. "Platform access started" says what happened to them;
            // "Impersonation started" is the operator's word for it.
            self::ImpersonationStarted => 'Platform access started',
            self::ImpersonationEnded => 'Platform access ended',
            // "Two-step sign-in reset" rather than "2FA reset": the same words the settings panel and the
            // challenge page use with the person whose account it is.
            self::TwoFactorReset => 'Two-step sign-in reset',
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
