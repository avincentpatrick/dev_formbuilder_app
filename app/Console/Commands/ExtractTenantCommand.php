<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Tenancy\Extraction\ExtractManifest;
use App\Services\Tenancy\Extraction\ExtractWriter;
use App\Services\Tenancy\Extraction\TenantExtractService;
use App\Support\Tenancy\TenantLocator;
use Illuminate\Console\Command;

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
        // The three-way lookup moved to `TenantLocator` in K1c, when a second operator command needed the
        // identical one — including its uuid guard, which is the part a second copy would have lost.
        $tenant = TenantLocator::find((string) $this->argument('tenant'));

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
