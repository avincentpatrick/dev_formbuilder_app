<?php

declare(strict_types=1);

namespace App\Enums;

use App\Jobs\Submissions\GeneratePdfJob;
use App\Jobs\Webhooks\DeliverWebhookJob;
use App\Models\Submission;
use App\Notifications\Submissions\SubmissionPdfReadyNotification;
use App\Notifications\Webhooks\WebhookAutoDisabledNotification;
use App\Policies\SubmissionPolicy;
use App\Services\Notifications\NotificationRecipientResolver;

/**
 * The human-facing notification vocabulary (data-dictionary §22/§23 and its enum catalog row, PRD Feature
 * #13). Backs `notifications.type` and `notification_preferences.notification_type`, and is pinned at the
 * database by CHECKs generated from {@see self::values()} on both columns, so the enum and the constraints
 * cannot drift. **The case ORDER is therefore load-bearing** — `tests/Unit/Notifications/NotificationTypeTest.php` pins
 * it, and a reorder silently changes the generated constraint on the next `migrate:fresh`.
 *
 * ── A THIRD vocabulary, orthogonal to the other two ─────────────────────────────────────────────────────
 * {@see AuditEvent} records *what changed on which model* for the compliance ledger; {@see DomainEventType}
 * announces *that something happened* to post-commit consumers (webhooks, connectors); this one names *what
 * a person is told about it*. One action can produce all three rows. The mapping is deliberately NOT
 * one-to-one in either direction: a single `submission.created` event raises TWO notification types
 * ({@see self::SubmissionReceived} to the people who own the data, {@see self::ReviewRequested} to the
 * people who review it), and two of the cases here have no domain event at all (below).
 *
 * ── `review_requested` has no `DomainEventType` case, on purpose ────────────────────────────────────────
 * The I-map named one. There is no "request review" verb in the product — `SubmissionReviewService::
 * markUnderReview()` is a reviewer CLAIMING a submission, the opposite of requesting one — and
 * `DomainEventType` doubles as the webhook + Slack SUBSCRIPTION vocabulary, so a case with no emission site
 * would be a subscribable event that can never fire. It is a second fan-out of `submission.created`
 * instead, which is what the PRD's "a review is requested" actually describes.
 *
 * ── Two cases whose email arm is owned by the emitting site ─────────────────────────────────────────────
 * {@see self::ExportReady} and {@see self::WebhookFailed} predate this table: {@see GeneratePdfJob} already
 * sends {@see SubmissionPdfReadyNotification} and {@see DeliverWebhookJob} already sends
 * {@see WebhookAutoDisabledNotification}, both purpose-built and branded. I3 gives them an in-app row and
 * leaves their email exactly where it is rather than minting a second, blander copy — see
 * {@see self::honorsEmailPreference()} for what that costs.
 */
enum NotificationType: string
{
    case SubmissionReceived = 'submission_received';
    case SubmissionReturned = 'submission_returned';
    case SubmissionApproved = 'submission_approved';
    case ReviewRequested = 'review_requested';
    case ExportReady = 'export_ready';
    case MemberInvited = 'member_invited';
    case WebhookFailed = 'webhook_failed';

    /**
     * A platform operator began impersonating one of this workspace's members (I11b).
     *
     * ⚠️ APPENDED LAST, and that is not stylistic — the case ORDER is load-bearing (see the class docblock)
     * and `tests/Unit/Notifications/NotificationTypeTest.php` pins it. Inserting this beside a thematically similar case
     * would reorder the ones after it.
     *
     * This is the only type in the catalog raised by an actor who is NOT a member of the tenant. That is
     * what makes it worth notifying at all: rbac §9's resolved decision 2 is that platform access is
     * transparent to the affected tenant, and an audit row nobody is looking at is transparency in name
     * only. The bell is what turns "it is in your log" into "you were told".
     */
    case ImpersonationStarted = 'impersonation_started';

    /**
     * Someone self-registered on this workspace's subdomain and became a member (J3a).
     *
     * ⚠️ APPENDED LAST, for the same reason {@see self::ImpersonationStarted} was: the case ORDER is
     * load-bearing (see the class docblock) and `tests/Unit/Notifications/NotificationTypeTest.php` pins it.
     * Inserting this next to {@see self::MemberInvited}, where it thematically belongs, would reorder every
     * case after it and silently change the generated CHECK constraints on the next `migrate:fresh`.
     *
     * ── WHY THIS IS NOT ALSO A `DomainEventType`, AND WHAT THAT COSTS ──────────────────────────────────
     * A deliberate scope decision, not an omission. {@see DomainEventType} doubles as the webhook and
     * connector SUBSCRIPTION vocabulary and feeds `openapi.json`, so a case there is a four-file act with a
     * published contract attached. The cost, stated plainly rather than discovered: **a tenant cannot make
     * their own systems react to a self-registration** — no webhook delivery, no Slack or Sheets fan-out.
     * Recorded in `docs/feature-backlog.md` as the row to open if anyone asks for it.
     *
     * ── THE TWIN OF `member_invited`, AND SCOPED LIKE IT ───────────────────────────────────────────────
     * Same page, same question ("who is in this workspace"), same recipients. The two are the two doors into
     * a tenant: `member_invited` fires when a seat is OFFERED, this when one is TAKEN through the open-
     * registration door instead. An Admin told about the first and not the second has half a ledger, which is
     * why this does not follow `ImpersonationStarted`'s narrower owner-only scoping — that case is narrow
     * because it discloses PROCESSOR access to the party accountable for the workspace, a different question.
     */
    case MemberJoined = 'member_joined';

    /**
     * Human label for the notification center, the preferences card, and any email subject built from the
     * type alone — one string, three surfaces. Mirrors {@see AuditEvent::label()}, and like it carries no
     * companion `badgeVariant()`: colour is presentation, owned by the TS side (I4).
     */
    public function label(): string
    {
        return match ($this) {
            self::SubmissionReceived => 'New submission',
            self::SubmissionReturned => 'Submission returned',
            self::SubmissionApproved => 'Submission approved',
            self::ReviewRequested => 'Review requested',
            self::ExportReady => 'Export ready',
            self::MemberInvited => 'Member invited',
            self::WebhookFailed => 'Webhook paused',
            // The tenant's words, not the operator's — matching AuditEvent::ImpersonationStarted's label,
            // because the bell row and the ledger row describe the same event to the same reader.
            self::ImpersonationStarted => 'Platform access',
            self::MemberJoined => 'Member joined',
        };
    }

    /**
     * Whether this type appears in the recipient's notification center when they have expressed no
     * preference. Every type defaults on: the bell is the whole point of the feature, and a user who
     * wants less turns it off per type (§23, "absence means default").
     */
    public function defaultInApp(): bool
    {
        return true;
    }

    /**
     * Whether an email copy goes out when the recipient has expressed no preference.
     *
     * Only {@see self::SubmissionReceived} departs from the columns' `default true`, and it departs exactly
     * where the PRD says to: a submission on a busy public form is the high-volume case, so
     * "a lead-gen owner isn't emailed per response unless they opt in" (PRD Feature #13, §23 design note).
     */
    public function defaultEmail(): bool
    {
        return $this !== self::SubmissionReceived;
    }

    /**
     * Whether a recipient's `email_enabled` preference actually governs this type's email.
     *
     * False for the two site-owned cases above, and the reason is not an oversight: an
     * {@see self::ExportReady} email answers a file the recipient asked for by hand, and a
     * {@see self::WebhookFailed} email tells a tenant owner an integration has stopped delivering. Both are
     * operational rather than promotional, both are addressed to exactly one person who caused or owns the
     * thing, and both shipped before this table existed. I4 renders their email toggle as unavailable
     * rather than offering a switch that is not wired to anything.
     */
    public function honorsEmailPreference(): bool
    {
        return $this !== self::ExportReady && $this !== self::WebhookFailed;
    }

    /**
     * The tenant roles that receive this type when the emitting site does NOT name a recipient.
     *
     * An EMPTY list means the site addresses the notification explicitly — the submission's
     * `respondent_user_id` for the two review outcomes, the requesting user for an export, the tenant owner
     * for a paused webhook — and {@see NotificationRecipientResolver} must not
     * guess. Roles rather than permissions because the permission that would express "gets told about
     * submissions" is `submissions.view`, which all five roles hold (including the read-only Viewer), so a
     * permission-derived set would be "everybody" for the noisiest type in the catalog.
     *
     * @return list<string>
     */
    public function recipientRoles(): array
    {
        return match ($this) {
            self::SubmissionReceived => ['owner', 'admin', 'form_editor'],
            self::ReviewRequested => ['owner', 'admin', 'reviewer'],
            self::MemberInvited => ['owner', 'admin'],
            // OWNER ONLY, deliberately narrower than `member_invited`'s owner+admin. §9 frames platform
            // access as a Processor-access disclosure to the party accountable for the workspace, and the
            // Owner is that party (`tenants.owner_user_id` is a real column; "Admin" is a delegated role).
            // Widening it later is additive; narrowing it after people have relied on it is not.
            self::ImpersonationStarted => ['owner'],
            // The twin of `member_invited` above, and scoped identically on purpose — see the case's docblock.
            self::MemberJoined => ['owner', 'admin'],
            self::SubmissionReturned, self::SubmissionApproved, self::ExportReady, self::WebhookFailed => [],
        };
    }

    /**
     * Whether this type's recipients are narrowed by per-form visibility before delivery.
     *
     * True for the two `submission.created` fan-outs, whose role sets include the collaborator-scoped
     * Form Editor and Reviewer: those roles see only forms they hold a grant on
     * ({@see SubmissionPolicy::view()}, mirrored by
     * {@see Submission::scopeVisibleTo()}), so without this the bell would announce submissions
     * the inbox then 403s on. {@see self::MemberInvited} goes to org-wide roles only and needs no narrowing.
     */
    public function isFormScoped(): bool
    {
        return $this === self::SubmissionReceived || $this === self::ReviewRequested;
    }

    /**
     * Where this notification points, as a tenant-app-relative path with no leading slash and no host.
     *
     * It lives on the enum for the reason {@see self::label()} does, only more so: the bell's link (I4) and
     * the email's action button describe the SAME destination, and a second copy of that map is how they
     * come to disagree — which is a bug nobody notices, because each surface looks right on its own.
     * `ConnectorEventContextResolver` is the counter-example already in the tree: a second place that
     * answers "where does a submission event point", which now has to be kept in step by hand.
     *
     * The enum stays pure — no config, no `TenantUrl`, no database. Joining the host is the caller's job,
     * because only the caller knows which tenant and which arm (the APP host, never the public one).
     *
     * Exhaustive, no `default`: an eighth case must be a fatal here rather than a silent dead link.
     *
     * @param  array<string, mixed>  $data  the notification's stored payload
     */
    public function pathFor(array $data): ?string
    {
        $submissionId = is_string($data['submission_id'] ?? null) ? $data['submission_id'] : null;
        $endpointId = is_string($data['webhook_endpoint_id'] ?? null) ? $data['webhook_endpoint_id'] : null;

        return match ($this) {
            self::SubmissionReceived,
            self::SubmissionReturned,
            self::SubmissionApproved,
            self::ReviewRequested,
            self::ExportReady => $submissionId === null ? null : "submissions/{$submissionId}",
            self::MemberInvited => 'members',
            self::WebhookFailed => $endpointId === null ? null : "webhooks/{$endpointId}",
            // Unconditional, unlike every id-bearing case above: the destination is the tenant's OWN ledger
            // (`routes/tenant.php`:667), which is where §9 says the access is visible, and there is no
            // per-row page to deep-link to — `/audit-log` has no `{audit}` detail route by design.
            self::ImpersonationStarted => 'audit-log',
            // Unconditional, and the same destination `member_invited` points at: the members page is where a
            // new arrival is acted on (promote, or remove).
            self::MemberJoined => 'members',
        };
    }

    /** The words on the email's action button, paired with {@see self::pathFor()}. */
    public function actionLabel(): string
    {
        return match ($this) {
            self::SubmissionReceived,
            self::SubmissionReturned,
            self::SubmissionApproved,
            self::ReviewRequested,
            self::ExportReady => 'View submission',
            self::MemberInvited => 'View members',
            self::WebhookFailed => 'View webhook',
            self::ImpersonationStarted => 'View audit log',
            self::MemberJoined => 'View members',
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
