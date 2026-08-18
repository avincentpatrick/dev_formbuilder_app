<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which half of the `settings` table a key lives on (data-dictionary §20) — Increment I5.
 *
 * The table holds both kinds of row and distinguishes them by a NULLABLE `tenant_id`, which is why it needs
 * TWO partial unique indexes rather than one composite constraint. This enum is the PHP mirror of that
 * split, so a caller states which side it means instead of passing a nullable string around and hoping.
 */
enum SettingScope
{
    /** A tenant's own setting — `tenant_id = <that tenant>`, written by an Owner/Admin. */
    case Tenant;

    /** A platform-wide setting — `tenant_id IS NULL`, writable only by the super-admin console. */
    case Platform;
}
