<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Data\PlatformFieldLibraryItems;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Seeds the platform (system) question library (Increment G9b) — the NULL-tenant `field_library` rows every
 * tenant sees in the builder's Library picker. Content lives in {@see PlatformFieldLibraryItems}.
 *
 * Written on the `pgsql_privileged` (superuser) connection, exactly like {@see PlatformTemplateSeeder}: the
 * nullable-global RLS shape keeps its INSERT policy strict, so the ordinary app role is (correctly) forbidden
 * from authoring a NULL-tenant row. Idempotent, matched on `name`, and re-runnable — it refreshes each item's
 * content on re-seed while preserving `usage_count` (the curation signal). Registered from {@see DatabaseSeeder}
 * after PlatformTemplateSeeder.
 */
class PlatformFieldLibrarySeeder extends Seeder
{
    public function run(): void
    {
        $connection = DB::connection('pgsql_privileged');
        $now = now();

        foreach (PlatformFieldLibraryItems::all() as $item) {
            $content = [
                'name' => $item['name'],
                'description' => $item['description'],
                'category' => $item['category'],
                'field_type' => $item['field_type'],
                'default_label' => $item['default_label'],
                'default_config' => json_encode($item['default_config']),
                'default_validations' => json_encode($item['default_validations']),
                'is_active' => true,
                'updated_at' => $now,
            ];

            $existingId = $connection->table('field_library')
                ->whereNull('tenant_id')
                ->where('name', $item['name'])
                ->value('id');

            if ($existingId !== null) {
                $connection->table('field_library')->where('id', $existingId)->update($content);

                continue;
            }

            $connection->table('field_library')->insert(array_merge($content, [
                'id' => Uuid::uuid7()->toString(),
                'tenant_id' => null,
                'default_label_translations' => null,
                'default_hint' => null,
                'usage_count' => 0,
                'created_by' => null,
                'created_at' => $now,
            ]));
        }
    }
}
