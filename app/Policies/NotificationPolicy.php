<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Notification;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;

/**
 * Ownership of one in-app notification (Increment I4, PRD Feature #13b).
 *
 * ── The ownership check is here because RLS does not do it ──────────────────────────────────────────────
 * `notifications` uses `strict` isolation, which scopes to the TENANT only — the migration refuses the
 * `belongs_to_user` variant, whose policies carry no tenant predicate at all, so a consultant in two
 * tenants would read across both. The consequence is that route-model binding happily resolves ANY
 * co-tenant's notification: `POST /notifications/{someone-elses-uuid}/read` would stamp `read_at` on a
 * colleague's row and silently decrement their badge, and nothing in the database would object.
 *
 * This is {@see SavedReportViewPolicy::owns()}'s case, feature for feature — same strict RLS, same
 * private-per-user row — and `routes/tenant.php` states it there in one sentence: "without it any
 * co-tenant could edit a colleague's view". Substitute "read a colleague's notification".
 *
 * ── Why `markRead` and not `update` ────────────────────────────────────────────────────────────────────
 * `read_at` is the only mutable column on this table (rows are written solely by
 * {@see NotificationDispatcher} from post-commit domain events, and nothing
 * edits `type` or `data` ever). A generic `update` would name a wider ability than the surface has, and
 * the next reader would reasonably assume something else can be edited.
 *
 * There is deliberately no `viewAny`/`view`: the LIST is filtered by `Notification::scopeForUser()` rather
 * than authorized per row, and a `viewAny` that returned true for every authenticated user would be a
 * policy method that is always true. `$user->can()` over `hasPermissionTo()` is the repo-wide rule (the
 * latter throws on an unseeded catalog), but no permission is consulted here at all — see the route block.
 */
final class NotificationPolicy
{
    public function markRead(User $user, Notification $notification): bool
    {
        return $notification->user_id === $user->getKey();
    }
}
