<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Data\PlatformTemplateBlueprints;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Seeds the platform (system) form-template gallery — the 10 NULL-tenant `form_templates` rows every tenant
 * sees in the onboarding gallery (Increment G9a / onboarding-content-plan §3-§4). Content lives in
 * {@see PlatformTemplateBlueprints}.
 *
 * Written on the `pgsql_privileged` (superuser) connection, exactly like {@see RolePermissionSeeder}: the
 * nullable-global RLS shape keeps its INSERT policy strict, so the ordinary app role is (correctly) forbidden
 * from authoring a NULL-tenant row — only the elevated role can. Idempotent, matched on `name` (the natural
 * key; the Postgres unique index treats NULL tenant_id as distinct, so app-layer matching is what prevents
 * duplicate global rows), and re-runnable: it refreshes each template's content on re-seed while preserving
 * `usage_count` (the curation signal). Registered from {@see DatabaseSeeder} after RolePermissionSeeder.
 */
class PlatformTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $connection = DB::connection('pgsql_privileged');
        $now = now();

        foreach (PlatformTemplateBlueprints::all() as $template) {
            $content = [
                'name' => $template['name'],
                'description' => $template['description'],
                'category' => $template['category'],
                'schema_blueprint' => json_encode($template['blueprint']),
                'is_public' => true,
                'updated_at' => $now,
            ];

            $existingId = $connection->table('form_templates')
                ->whereNull('tenant_id')
                ->where('name', $template['name'])
                ->value('id');

            if ($existingId !== null) {
                $connection->table('form_templates')->where('id', $existingId)->update($content);

                continue;
            }

            $connection->table('form_templates')->insert(array_merge($content, [
                'id' => Uuid::uuid7()->toString(),
                'tenant_id' => null,
                'cover_image_attachment_id' => null,
                'source_form_version_id' => null,
                'usage_count' => 0,
                'created_by' => null,
                'created_at' => $now,
            ]));
        }
    }
}
