<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PlanTier;
use App\Models\Plan;
use Database\Seeders\Data\PlanCatalog;
use Illuminate\Database\Seeder;

/**
 * Seeds the global `plans` catalog (H5a / ADR-0008). Content lives in {@see PlanCatalog}.
 *
 * Unlike the platform template/library seeders, this writes on the **default** connection, not
 * `pgsql_privileged`: `plans` has NO row-level security (it is the one tenant_id-free global catalog), so
 * the ordinary app role writes it directly — the elevated connection exists only to bypass strict RLS on
 * NULL-tenant writes, which does not apply here (least privilege). Idempotent, matched on the unique `code`,
 * and re-runnable (it refreshes each plan's flags/quotas on re-seed). Registered from {@see DatabaseSeeder}
 * alongside the other catalog seeders.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PlanCatalog::all() as $attributes) {
            /** @var PlanTier $code */
            $code = $attributes['code'];

            Plan::query()->updateOrCreate(
                ['code' => $code->value], // match on the unique code (string, not the enum, for the where)
                $attributes,
            );
        }
    }
}
