<?php

declare(strict_types=1);

namespace App\Exceptions\Tenancy;

use App\Support\Tenancy\ExtractionGuard;
use RuntimeException;

/**
 * A precondition of {@see ExtractionGuard} was not met (Phase 4, P2a — ADR-0017 §D4).
 *
 * Deliberately NOT rendered for the web. Every one of these means "an operator-run process was about to
 * read tenant data under conditions where Row-Level Security is not doing what the code assumes", and the
 * correct outcome is a loud stop on a console, not a toast. There is no handler entry in
 * `bootstrap/app.php` for it and there should not be one until something on a request path throws it.
 *
 * The messages are written for the person reading a terminal at the moment it fires, so each one names the
 * condition AND the fix — a guard that says only "refused" costs the reader the investigation the guard
 * was supposed to save them.
 */
final class ExtractionGuardException extends RuntimeException
{
    public static function roleBypassesRls(string $role): self
    {
        return new self(
            "Refusing to read tenant data as `{$role}`: that role is SUPERUSER or BYPASSRLS, so Row-Level "
            .'Security is ignored entirely and every query would silently return EVERY tenant\'s rows. '
            .'Connect as the non-privileged application role (DB_USERNAME=meridian_app).'
        );
    }

    public static function roleUnreadable(): self
    {
        return new self(
            'Refusing to read tenant data: `pg_roles` has no row for the current user, so whether Row-Level '
            .'Security applies to this connection cannot be established. This is not a PostgreSQL connection '
            .'the tenancy guards understand.'
        );
    }

    public static function contextMissing(string $tenantId): self
    {
        return new self(
            "Refusing to read tenant data for {$tenantId}: the database session carries no tenant context, so "
            .'every Row-Level-Security policy would match zero rows and the result would be empty rather than '
            .'wrong. The usual cause is calling TenantContext::applyLocal() outside a transaction — it issues '
            .'SET LOCAL, which is a silent no-op there. Wrap the work in DB::transaction().'
        );
    }

    public static function contextMismatch(string $expected, string $actual): self
    {
        return new self(
            "Refusing to read tenant data for {$expected}: the database session is scoped to {$actual}. "
            .'Whatever this process is about to read belongs to a different workspace.'
        );
    }
}
