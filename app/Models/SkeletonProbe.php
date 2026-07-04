<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Ramsey\Uuid\Uuid;

/**
 * Throwaway Phase-0 harness model.
 *
 * Its only job is to prove, end-to-end, that migrations run and a client-generated
 * UUIDv7 primary key round-trips on real PostgreSQL. It is removed the moment
 * Increment A introduces the first real (tenant-scoped) domain tables.
 */
class SkeletonProbe extends Model
{
    use HasUuids;

    protected $fillable = ['note'];

    /**
     * Client-generate an RFC-9562 UUIDv7 before insert: time-ordered (good B-tree locality)
     * and available before the row reaches Postgres — the offline-PWA / public-API requirement,
     * and version-independent of PostgreSQL 18's native uuidv7().
     */
    public function newUniqueId(): string
    {
        return Uuid::uuid7()->toString();
    }
}
