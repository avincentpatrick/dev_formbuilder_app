<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\NotificationType;
use App\Models\Audit;
use App\Models\Notification;
use App\Models\Submission;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Policies\SubmissionPolicy;
use App\Services\Audit\AuditLogPresenter;
use App\Services\Entitlements\EntitlementService;
use App\Support\Notifications\NotificationCopy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Read model for the notification centre and its preferences card (Increment I4, PRD Feature #13b) — the
 * first surface that lets a human read what I3's dispatcher has been writing. Mirrors
 * {@see AuditLogPresenter}: flat rows with every label, every URL and every "is this even reachable"
 * decision resolved server-side, keeping the controller thin.
 *
 * ── `forUser()` on every read, and it is not defensiveness ──────────────────────────────────────────────
 * `notifications` is `strict` RLS, which scopes to the TENANT and stops there (the migration refuses the
 * `belongs_to_user` variant, whose policies carry no tenant predicate at all). So an Owner's unscoped
 * `SELECT * FROM notifications` returns every member's rows, and this class is the only reader outside
 * {@see NotificationDispatcher}. Drop the scope from either query below and the bell shows a Viewer the
 * Owner's backlog — including the invitee email addresses `member_invited` rows carry.
 *
 * ── No pagination, deliberately ─────────────────────────────────────────────────────────────────────────
 * The bell is a peek, not the ledger: {@see self::SUMMARY_LIMIT} rows and a "mark all as read". The
 * `unread_count` is a SEPARATE query rather than a count of the window, because the badge must show the
 * true total — a user with 40 unread must see 40, not 15.
 */
final class NotificationPresenter
{
    /** One popover-full. Read AND unread, so a row does not vanish the instant it is dealt with. */
    public const SUMMARY_LIMIT = 15;

    public function __construct(
        private readonly NotificationPreferenceResolver $preferences,
        private readonly EntitlementService $entitlements,
    ) {}

    /**
     * The bell's polled payload.
     *
     * @return array{unread_count: int, items: list<array{id: string, type: string, title: string, description: string, url: ?string, action_label: string, read_at: ?string, created_at: ?string}>}
     */
    public function summary(User $user): array
    {
        // Served index-only by notifications_tenant_user_read_idx (tenant_id, user_id, read_at).
        $unread = Notification::query()->forUser($user)->unread()->count();

        // ⚠️ ORDER BY created_at, NOT BY id — the INVERSE of AuditLogPresenter, for the SAME reason.
        // There the index is audits_tenant_recent_idx (tenant_id, id), so the uuidv7 key gives recency for
        // free. Here the only recency index is notifications_tenant_user_created_idx
        // (tenant_id, user_id, created_at) and there is no (tenant_id, user_id, id) index at all, so
        // ordering by the key would force a sort. The `id` tiebreak makes the order TOTAL: E2eSeeder
        // back-dates rows and two rows can share an instant, where uuidv7 then breaks the tie by insertion
        // order. Do not "tidy" this into orderByDesc('id').
        $rows = Notification::query()
            ->forUser($user)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::SUMMARY_LIMIT)
            ->get();

        $reachable = $this->resolveDestinations($user, $rows);

        // A foreach rather than `->map(...)->values()->all()`: the collection round trip returns
        // `array<int, …>` to PHPStan at level 8, not the `list<…>` this method's return type promises.
        $items = [];

        foreach ($rows as $notification) {
            $items[] = $this->row($notification, $reachable);
        }

        return ['unread_count' => $unread, 'items' => $items];
    }

    /**
     * Every type's channels for one person — the /settings card's read model.
     *
     * Row order is {@see NotificationType::cases()}, which `NotificationTypeTest` already pins as
     * load-bearing for the two generated CHECK constraints. This is its third consumer, and the first one
     * a human reads.
     *
     * @return list<array{type: string, label: string, in_app: bool, email: bool, email_locked: bool}>
     */
    public function preferences(User $user): array
    {
        $resolved = $this->preferences->resolveAll($user);

        return array_map(fn (NotificationType $type): array => [
            'type' => $type->value,
            'label' => $type->label(),
            'in_app' => $resolved[$type->value]['in_app'],
            'email' => $resolved[$type->value]['email'],
            // The card renders this control as UNAVAILABLE rather than as a switch wired to nothing:
            // export_ready and webhook_failed keep their purpose-built branded emails and are not
            // preference-gated at all ({@see NotificationType::honorsEmailPreference()}).
            'email_locked' => ! $type->honorsEmailPreference(),
        ], NotificationType::cases());
    }

    /**
     * @param  array<string, string>  $reachable  destination path keyed by notification id
     * @return array{id: string, type: string, title: string, description: string, url: ?string, action_label: string, read_at: ?string, created_at: ?string}
     */
    private function row(Notification $notification, array $reachable): array
    {
        $copy = NotificationCopy::inApp($notification->type, $notification->data);
        $id = (string) $notification->getKey();

        return [
            'id' => $id,
            // The raw value, for the icon and the colour band. Presentation is TS-owned — the enum says so
            // ("colour is presentation, owned by the TS side"), which is why there is no `variant` here.
            'type' => $notification->type->value,
            'title' => $copy['title'],
            'description' => $copy['description'],
            'url' => $reachable[$id] ?? null,
            'action_label' => $notification->type->actionLabel(),
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }

    /**
     * Resolve each row's destination to a URL THE RECIPIENT CAN ACTUALLY OPEN, or to nothing.
     *
     * ── This is the AuditLogPresenter::resolveTargets() call, and it is load-bearing here ───────────────
     * `NotificationType::pathFor()` answers "where does this type point", purely, so the bell and the email
     * cannot mint two maps. It cannot answer "does that page still exist, and may THIS person open it",
     * because it holds no database and no gate — and both answers are routinely no:
     *
     *  · {@see SubmissionPolicy::view()} is `submissions.view && (org-wide || collaborates on
     *    the form)` and has NO respondent clause, yet `submission_approved`/`submission_returned` are
     *    addressed to the submission's `respondent_user_id`. A form editor whose grant is later revoked,
     *    or whose form moves to another scope node, keeps a row pointing at a 403 — and
     *    `bootstrap/app.php` renders that as Laravel's bare 403 page, OUTSIDE the Inertia shell, with no
     *    navigation back. (Widening the policy so a respondent may always read their own submission is a
     *    real authorization change and belongs to I9's review/edit vertical, not here.)
     *  · `/webhooks/{id}` needs `feature:webhooks` as well as the policy, so a tenant that bought Starter,
     *    configured an endpoint and downgraded to Free still gets the paused-webhook notification and
     *    bounces off its own link.
     *  · `submissions`, `forms` and `webhook_endpoints` all soft-delete. A deleted target 404s.
     *
     * So the URL is computed, once, by asking the real gate — never inferred on the client, which would
     * encode "which of these is linkable" as frontend knowledge that breaks silently when a policy moves
     * (the argument `AuditLogPresenter::target()` already makes). An unreachable row renders as plain,
     * non-interactive text rather than a consolation link to /dashboard.
     *
     * Two `whereIn`s regardless of row count, and the gate checks are then nearly free:
     * `ResourceGrantResolver` memoizes a user's grants per request, and `EntitlementService` its snapshot.
     *
     * @param  Collection<int, Notification>  $rows
     * @return array<string, string> notification id => path with a LEADING SLASH
     */
    private function resolveDestinations(User $user, Collection $rows): array
    {
        $submissions = $this->keyById(
            Submission::query()
                ->whereIn('id', $this->payloadIds($rows, 'submission_id'))
                ->get(['id', 'form_id'])
        );

        $endpoints = $this->keyById(
            WebhookEndpoint::query()
                ->whereIn('id', $this->payloadIds($rows, 'webhook_endpoint_id'))
                ->get(['id'])
        );

        $canSeeMembers = $user->can('tenant.members.invite');
        // Read once rather than per row: the /webhooks routes stack `feature:webhooks` on top of the
        // policy, so a link that passes the policy alone still bounces.
        $hasWebhooks = $this->entitlements->feature('webhooks');

        $resolved = [];

        foreach ($rows as $notification) {
            $path = $notification->type->pathFor($notification->data);

            if ($path === null || ! $this->canOpen($user, $notification, $submissions, $endpoints, $canSeeMembers, $hasWebhooks)) {
                continue;
            }

            // THE LEADING SLASH IS JOINED HERE, ONCE. pathFor() returns a bare app-relative path with no
            // slash and no host precisely so each caller joins its own root — NotificationMailer joins the
            // tenant's APP host through TenantUrl::to(), the bell joins '/'. Ship it unjoined and
            // <Link href="submissions/abc"> resolves RELATIVE to the current URL: correct on /dashboard,
            // silently wrong on /forms/{id}/builder, and identical in every screenshot.
            $resolved[(string) $notification->getKey()] = '/'.$path;
        }

        return $resolved;
    }

    /**
     * @param  array<string, Submission>  $submissions
     * @param  array<string, WebhookEndpoint>  $endpoints
     */
    private function canOpen(
        User $user,
        Notification $notification,
        array $submissions,
        array $endpoints,
        bool $canSeeMembers,
        bool $hasWebhooks,
    ): bool {
        return match ($notification->type) {
            NotificationType::SubmissionReceived,
            NotificationType::SubmissionReturned,
            NotificationType::SubmissionApproved,
            NotificationType::ReviewRequested,
            NotificationType::ExportReady => $this->allows($user, 'view', $submissions[$this->payloadId($notification, 'submission_id')] ?? null),
            NotificationType::MemberInvited => $canSeeMembers,
            NotificationType::WebhookFailed => $hasWebhooks
                && $this->allows($user, 'view', $endpoints[$this->payloadId($notification, 'webhook_endpoint_id')] ?? null),
            // I11b — the row links to the tenant's own ledger, so the question is exactly the one
            // `routes/tenant.php` asks of /audit-log itself. A CLASS check rather than the `allows()`
            // helper above because there is no target row: this notification points at a page, not at a
            // record, which is also why `pathFor()` needs no id from the payload.
            //
            // In practice the recipient is the Owner and always passes. It is gated anyway because the
            // recipient ROLE is a decision that can be widened later (the enum's `recipientRoles()` says
            // so), and a link that 403s is a worse bell row than one that is not a link.
            NotificationType::ImpersonationStarted => Gate::forUser($user)->allows('viewAny', Audit::class),
        };
    }

    /** A target that no longer exists is not authorized, it is absent — either way, no link. */
    private function allows(User $user, string $ability, ?object $target): bool
    {
        return $target !== null && Gate::forUser($user)->allows($ability, $target);
    }

    /**
     * @param  Collection<int, Notification>  $rows
     * @return list<string>
     */
    private function payloadIds(Collection $rows, string $key): array
    {
        $ids = [];

        foreach ($rows as $notification) {
            $id = $this->payloadId($notification, $key);

            if ($id !== '') {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    private function payloadId(Notification $notification, string $key): string
    {
        $value = $notification->data[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Collection<int, TModel>  $models
     * @return array<string, TModel>
     */
    private function keyById(Collection $models): array
    {
        $keyed = [];

        foreach ($models as $model) {
            $keyed[(string) $model->getKey()] = $model;
        }

        return $keyed;
    }
}
