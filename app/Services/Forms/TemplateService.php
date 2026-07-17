<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Models\Form;
use App\Models\FormTemplate;
use App\Models\FormVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\PlatformRowCounter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Form-template instantiate + save-as (Increment G9a). The two halves of the template loop:
 *
 *  - instantiate() — clone a template into a BRAND-NEW form (data-dictionary §12 mandates clone-on-instantiate,
 *    never a live reference): scaffold a form + v1 draft via {@see FormService::create}, then materialize the
 *    template's `schema_blueprint` into that draft via {@see SchemaBlueprintMaterializer}. Works for both a
 *    platform (NULL-tenant) template and a tenant-owned one — neither is referenced afterward.
 *  - saveAsTemplate() — snapshot a version's live rows via {@see SchemaSnapshotSerializer} into a tenant-owned,
 *    private `form_templates` row (`is_public = false`), tracing back to the source version.
 *
 * `usage_count` is bumped through {@see PlatformRowCounter} for a platform template (whose strict UPDATE policy
 * would otherwise silently no-op a tenant-connection write) and through ordinary Eloquent for a tenant-owned one.
 */
final class TemplateService
{
    public function __construct(
        private readonly FormService $forms,
        private readonly SchemaBlueprintMaterializer $materializer,
        private readonly SchemaSnapshotSerializer $serializer,
        private readonly PlatformRowCounter $counter,
    ) {}

    public function instantiate(FormTemplate $template, Tenant $tenant, User $user): Form
    {
        return DB::transaction(function () use ($template, $tenant, $user): Form {
            $form = $this->forms->create($tenant, $user, $template->name, $template->description);

            $draft = FormVersion::query()->whereKey($form->draft_version_id)->firstOrFail();
            $this->materializer->materializeInto($draft, $template->schema_blueprint, $user);

            $this->bumpUsage($template);

            return $form->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $meta  the validated template metadata: name (required) + optional description/category.
     */
    public function saveAsTemplate(FormVersion $version, User $actor, array $meta): FormTemplate
    {
        $blueprint = $this->serializer->snapshot($version);

        return FormTemplate::create([
            'tenant_id' => TenantContext::currentTenantId(),
            'name' => $meta['name'],
            'description' => $meta['description'] ?? null,
            'category' => $meta['category'] ?? null,
            'schema_blueprint' => $blueprint,
            'source_form_version_id' => $version->id,
            'is_public' => false,
            'usage_count' => 0,
            'created_by' => $actor->id,
        ]);
    }

    /**
     * Bump `usage_count`: elevated path for a platform (NULL-tenant) row, ordinary Eloquent otherwise.
     *
     * The platform path runs on a SEPARATE connection ({@see PlatformRowCounter}), so it autocommits and is
     * NOT covered by instantiate()'s transaction: a rollback after this point leaves the count one high.
     * Accepted — the alternative (deferring to after commit) trades a durable "popular" signal for a lossy
     * one, and this counter drifting up by one on a failed instantiate is invisible in a gallery sort.
     */
    private function bumpUsage(FormTemplate $template): void
    {
        if ($template->tenant_id === null) {
            $this->counter->increment('form_templates', $template->id);

            return;
        }

        $template->increment('usage_count');
    }
}
