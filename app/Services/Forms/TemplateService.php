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
     * Capture a version's live rows as a template blueprint.
     *
     * ── M90: ONE SNAPSHOT, NOT THREE (docs/feature-backlog.md:8943) ─────────────────────────────────
     * {@see SchemaSnapshotSerializer::snapshot()} issues exactly three reads — sections, fields,
     * validations. Outside a transaction those are three separately autocommitted statements, so a
     * builder edit landing between them produces a blueprint describing a tree that never existed at any
     * instant: a field whose `section_key` names a section the first read did not return.
     *
     * ⛔ A PLAIN `DB::transaction()` WOULD NOT FIX THAT, WHICH IS WHY THE ISOLATION LEVEL IS EXPLICIT.
     * No `pgsql` connection in `config/database.php` pins one, so READ COMMITTED applies and every
     * statement inside a transaction takes its OWN fresh snapshot. Wrapping alone changes nothing here.
     *
     * ⛔ AND `lockForUpdate()` — the instrument M89 used on the publish path — WOULD BE WORSE THAN
     * NOTHING ON THIS ONE. The `draft_child` policy's `USING` expression is applied to a locking SELECT
     * as a FILTER rather than an error, and BOTH entry points admit a NON-DRAFT version:
     * `FormTemplateController::templateSource()` falls back to `current_published_version_id`, and the
     * API request validates `form_version_id` with no status rule at all. On those inputs a lock here
     * would match zero rows and persist an EMPTY blueprint, with no error and no failing test.
     *
     * ✅ REPEATABLE READ is status-blind, takes no lock, and cannot raise a serialization failure here
     * because the transaction's only write is an INSERT of an unrelated row. The precedent is
     * `App\Services\Tenancy\Extraction\TenantExtractService` — named as text rather than as a `@see`,
     * because Pint's `fully_qualified_strict_types` hoists a docblock FQCN into a real import and an
     * import that exists only to satisfy a comment is the M88 trap in a milder key. Its reasoning
     * (`docs/adr/0018-per-tenant-extraction.md` §D6) describes this defect class in the same words.
     *
     * ⚠️ IT IS THE FIRST REQUEST-PATH USE IN THIS CODEBASE, AND THAT IS STATED RATHER THAN GLOSSED —
     * the precedent above is reached only from a console command. Whether the two remaining unwrapped
     * callers of the same serializer should follow is filed as a decision, not decided here.
     *
     * @param  array<string, mixed>  $meta  the validated template metadata: name (required) + optional description/category.
     */
    public function saveAsTemplate(FormVersion $version, User $actor, array $meta): FormTemplate
    {
        return DB::transaction(function () use ($version, $actor, $meta): FormTemplate {
            $this->useSnapshotIsolation();

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
        });
    }

    /**
     * Ask for one snapshot across this transaction's reads.
     *
     * ⚠️ SKIPPED WHEN NESTED, AND THAT CARVE-OUT IS INHERITED RATHER THAN INVENTED. PostgreSQL refuses
     * `SET TRANSACTION ISOLATION LEVEL` once any statement has run, so it can only be the first statement
     * of a real transaction. Under `RefreshDatabase` the outer transaction has already executed, the level
     * is inherited, and this is correctly a no-op — which means the isolation level itself is not
     * observable from the suite. `TenantExtractService` carries the identical guard for the identical
     * reason, and `TenantExtractTest` lives with it by reading back what it ACTUALLY ran under rather than
     * asserting what was requested. What the suite CAN see, and what `TemplateSnapshotIsolationTest`
     * pins, is that all four statements run inside one transaction — which is the half that was missing.
     */
    private function useSnapshotIsolation(): void
    {
        if (DB::transactionLevel() === 1) {
            DB::statement('set transaction isolation level repeatable read');
        }
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
