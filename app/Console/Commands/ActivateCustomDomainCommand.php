<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Domain;
use App\Services\Tenancy\CustomDomainService;
use Illuminate\Console\Command;

/**
 * The operator half of the custom-domain lifecycle (H22a / ADR-0012).
 *
 * A tenant can reach `verified` entirely on its own by publishing a TXT record. It CANNOT put the
 * hostname into service — that is this command, run on the box by whoever installed the certificate.
 *
 * WHY A COMMAND AND NOT AN API ROUTE. Per-domain TLS/ACME issuance is structurally Track B, and Track B
 * is deferred, so until it exists a certificate is installed by hand. A tenant able to activate its own
 * domain could therefore put its respondents on an origin with no certificate for it — and the resume
 * link they receive would carry that origin. Requiring a human who has just done the nginx work is the
 * ratified "verification + routing + a manual-cert runbook, failing closed" boundary, expressed as the
 * only code path that can set `activated_at`.
 *
 * Runs with NO tenant context and needs none: `domains` and `tenants` are the RLS-exempt central tables.
 *
 * See docs/deployment-infrastructure.md §8 for the certificate steps that must precede this.
 */
final class ActivateCustomDomainCommand extends Command
{
    protected $signature = 'domains:activate {domain : The fully-qualified custom domain to put into service}
                            {--deactivate : Take a live domain back out of service, keeping its verification}';

    protected $description = 'Put a verified custom domain into service (run AFTER installing its TLS certificate)';

    public function handle(CustomDomainService $domains): int
    {
        $host = mb_strtolower(trim((string) $this->argument('domain')));

        /** @var Domain|null $domain */
        $domain = Domain::unscopedQuery()->where('domain', $host)->first();

        if ($domain === null) {
            $this->error("No domain row for {$host}. A tenant must claim it first.");

            return self::FAILURE;
        }

        if ($this->option('deactivate')) {
            if (! $domains->deactivate($domain)) {
                $this->error("{$host} is not currently live.");

                return self::FAILURE;
            }

            $this->info("{$host} is out of service. It stays verified, so re-activating needs no new TXT record.");

            return self::SUCCESS;
        }

        if (! $domain->isCustom()) {
            $this->error("{$host} is a tenant subdomain, which is always live. There is nothing to activate.");

            return self::FAILURE;
        }

        if (! $domain->isVerified()) {
            $this->error("{$host} is not verified yet — the challenge TXT record has not been seen.");
            $this->line('  Expected at: '.$domains->challengeName($host));
            $this->line('  Value:       '.$domains->expectedValue((string) $domain->verification_token));

            return self::FAILURE;
        }

        if (! $domains->activate($domain)) {
            $this->warn("{$host} is already live.");

            return self::SUCCESS;
        }

        $this->info("{$host} is live. Confirm its certificate is installed and nginx serves it.");

        return self::SUCCESS;
    }
}
