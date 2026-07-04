<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Ramsey\Uuid\Uuid;

/**
 * Client-generated RFC-9562 UUIDv7 primary keys — the project-wide PK strategy (Data Dictionary
 * "Primary key strategy"). Time-ordered for B-tree locality, and available before the row reaches
 * Postgres (the offline-PWA / public-API requirement), independent of PostgreSQL 18's native uuidv7().
 */
trait HasUuidv7
{
    use HasUuids;

    public function newUniqueId(): string
    {
        return Uuid::uuid7()->toString();
    }
}
