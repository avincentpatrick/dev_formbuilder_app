<?php

declare(strict_types=1);

namespace App\Exceptions\Admin;

use App\Enums\FeedbackStatus;
use RuntimeException;

/**
 * A super-admin platform-operation business-rule violation (RBAC §9) — e.g. suspending an
 * already-suspended tenant or reactivating an already-active one. Distinct from an authorization
 * failure (the `superadmin` middleware handles that with a 404) and from a missing tenant (route-model
 * binding 404s). Rendered as a redirect-back-with-errors for web requests by the handler registered in
 * bootstrap/app.php, mirroring MembershipException.
 */
final class SuperAdminException extends RuntimeException
{
    public static function alreadySuspended(): self
    {
        return new self('That tenant is already suspended.');
    }

    public static function alreadyActive(): self
    {
        return new self('That tenant is already active.');
    }

    /**
     * A feedback transition the lifecycle does not offer (I7a) — e.g. `new` straight to `resolved`, or
     * anything back to `new`. The console only renders legal verbs, so reaching this means a hand-crafted
     * request; it is still a business-rule violation rather than a 403, because the actor is authorized,
     * the target exists, and only the verb is wrong.
     */
    public static function invalidFeedbackTransition(FeedbackStatus $from, FeedbackStatus $to): self
    {
        return new self("A feedback report cannot go from {$from->label()} to {$to->label()}.");
    }
}
