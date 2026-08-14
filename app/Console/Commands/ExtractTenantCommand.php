<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Domain;
use App\Models\Tenant;
use App\Services\Tenancy\Extraction\ExtractManifest;
use App\Services\Tenancy\Extraction\ExtractWriter;
use App\Services\Tenancy\Extraction\TenantExtractService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * The operator surface for a per-tenant extract (Phase 4, P2b — ADR-0018 §D1).
 *
 * WHY A COMMAND AND NOT A ROUTE, on `ActivateCustomDomainCommand`'s precedent and for a sharper reason.
 * An extract is one workspace's entire record in one directory. Exposing it over HTTP would need a new
 * permission key — an authorization widening — and would make the blast radius of any session-handling
 * defect on that route the whole tenant rather than one page of it. It would also have to answer "where
 * does the file go, and who may fetch it afterwards", which is a storage and retention question nobody
 * has asked yet. Requiring shell access on the box is not friction here; it is the authorization model.
 *
 * ⚠️ RUN IT AS THE APPLICATION ROLE. The extractor refuses to start on a SUPERUSER or BYPASSRLS connection
 * (ExtractionGuard), because there Row-Level Security is ignored and the artefact would be every tenant's
 * rows under one tenant's name. That refusal is the guard doing its job, not a misconfiguration to route
 * around by switching `DB_USERNAME` to `meridian`.
 */
final class ExtractTenantCommand extends Command
{
    protected $signature = 'tenants:extract {tenant : The tenant id, slug or primary domain}
                            {--path= : Destination directory (default: storage/app/tenant-extracts/<slug>-<timestamp>)}';

    protected $description = "Write one tenant's record to a directory of NDJSON files plus a manifest";

    public function handle(TenantExtractService $extractor): int
    {
        $tenant = $this->resolveTenant((string) $this->argument('tenant'));

        if ($tenant === null) {
            $this->error('No tenant matches that id, slug or domain.');

            return self::FAILURE;
        }

        $writer = new ExtractWriter($this->destination($tenant));

        $this->line("Extracting <info>{$tenant->name}</info> ({$tenant->id}) into {$writer->directory}");

        $manifest = $extractor->extract($tenant, $writer);

        $this->report($manifest, $writer);

        return self::SUCCESS;
    }

    private function resolveTenant(string $needle): ?Tenant
    {
        // `tenants` and `domains` are the RLS-exempt central tables, so this runs with no context and needs
        // none — the same property ActivateCustomDomainCommand relies on.
        //
        // ⚠️ THE UUID CHECK IS NOT DEFENSIVE TIDINESS. `tenants.id` is a uuid column, so handing `find()`
        // a slug makes PostgreSQL raise 22P02 `invalid input syntax for type uuid` — a stack trace instead
        // of "no tenant matches that", for the input an operator is most likely to type.
        $tenant = Str::isUuid($needle) ? Tenant::query()->find($needle) : null;

        // Domain::unscopedQuery(), never $tenant->domains() or whereHas(): the Domain model carries a
        // tenant-scoping global scope, and this command deliberately runs before any context exists, so the
        // scoped query would match nothing and report the domain as unknown.
        $resolved = $tenant
            ?? Tenant::query()->where('slug', $needle)->first()
            ?? Domain::unscopedQuery()->where('domain', mb_strtolower($needle))->first()?->tenant;

        // The `tenant` relation is typed against stancl's Tenant CONTRACT, not this application's model, so
        // the narrowing is real rather than a cast to satisfy the analyser.
        return $resolved instanceof Tenant ? $resolved : null;
    }

    private function destination(Tenant $tenant): string
    {
        $path = $this->option('path');

        if (is_string($path) && $path !== '') {
            return rtrim($path, '/\\');
        }

        return storage_path(
            'app/tenant-extracts/'.($tenant->slug ?? $tenant->id).'-'.now()->format('Ymd-His')
        );
    }

    private function report(ExtractManifest $manifest, ExtractWriter $writer): void
    {
        $this->newLine();
        $this->table(
            ['Table', 'Filter', 'Rows', 'Platform rows excluded'],
            array_map(static fn (array $t): array => [
                $t['table'],
                $t['filter'],
                (string) $t['rows'],
                array_key_exists('platform_rows_excluded', $t) ? (string) $t['platform_rows_excluded'] : '—',
            ], $manifest->toArray()['tables'])
        );

        $this->info("{$manifest->rowTotal()} rows written to {$writer->directory}");
        $this->line("Snapshot: {$manifest->snapshot['role']} @ {$manifest->snapshot['database']}, "
            ."isolation {$manifest->snapshot['isolation_level']}");

        // ⚠️ SURFACED, NOT BURIED IN THE MANIFEST. This is the number ADR-0017's first entry criterion was
        // about, and the operator handing the artefact over is the person who has to decide whether a
        // dangling reference is an outstanding invitation (fine) or something that needs explaining.
        if (($unresolved = $manifest->unresolvedReferenceTotal()) > 0) {
            $this->warn(
                "{$unresolved} referenced user(s) are NOT in this extract — the `users` policy admits only "
                .'active members. See `unresolved_user_references` in manifest.json for which columns point '
                .'at them; outstanding invitations, removed members and platform operators all land here.'
            );
        }
    }
}
