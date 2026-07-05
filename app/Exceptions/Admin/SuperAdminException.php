<?php

declare(strict_types=1);

namespace App\Exceptions\Admin;

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
}
