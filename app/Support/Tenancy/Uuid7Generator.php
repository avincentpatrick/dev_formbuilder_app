<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Ramsey\Uuid\Uuid;
use Stancl\Tenancy\Contracts\UniqueIdentifierGenerator;

/**
 * stancl/tenancy id generator that emits RFC-9562 UUIDv7 (time-ordered) instead of the package's
 * default UUIDv4 — so `tenants.id` follows the same client-generated UUIDv7 convention as every other
 * table in this schema (Data Dictionary PK strategy). Wired via config('tenancy.id_generator').
 */
class Uuid7Generator implements UniqueIdentifierGenerator
{
    /**
     * @param  mixed  $resource  the model the id is being generated for (unused — v7 needs no seed)
     */
    public static function generate($resource): string
    {
        return Uuid::uuid7()->toString();
    }
}
