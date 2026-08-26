<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SsoVerifiedDomain;
use App\Models\Tenant;
use App\Services\Sso\SsoDomainService;
use App\Services\Sso\SsoUserProvisioner;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantLocator;
use Illuminate\Console\Command;

/**
 * Claim, verify, list and release a workspace's SSO email domains (M18 — ADR-0016 §D34).
 *
 * ── ⚠️ WHY A COMMAND, AND WHY THAT IS AN INTERIM ANSWER RATHER THAN THE DESIGN ─────────────────────
 * This is NOT the `domains:activate` situation. That command is operator-only *on the merits* — a tenant
 * able to activate its own host could put respondents on an origin with no certificate — and it will still
 * be operator-only when Track B lands. Nothing of the kind is true here: proving control of an email domain
 * is entirely a workspace's own business, and the tenant-facing card on `/settings/sso` is where it belongs.
 *
 * That card is `resources/js/**`, which is the OTHER lane's tree under Standing Rule 7(b), and splitting one
 * paired change across two lanes is the one thing 7(b-bis) says cannot work. So it is **filed as its own row
 * in `docs/feature-backlog.md`**, and this command exists so the control is operable — by an operator, on
 * the box, on a workspace's behalf — from the moment the refusal goes live rather than from the moment a
 * screen for it does. Shipping the enforcement with no way at all to satisfy it would have been the worse
 * half of a security increment.
 *
 * ⚠️ WHOEVER BUILDS THAT CARD: this command's verbs are already the right ones and
 * {@see SsoDomainService} already holds every decision. What the card adds that this cannot is an
 * authenticated ACTOR — which is precisely what the service's docblock says the missing audit rows are
 * waiting for. Do not re-derive the lifecycle; add the actor and the audit.
 *
 * ── TENANT CONTEXT IS REQUIRED HERE, UNLIKE EVERY OTHER COMMAND IN THIS DIRECTORY ──────────────────
 * `sso_verified_domains` is under STRICT RLS (it is read only once a tenant is established, so nothing about
 * it is circular the way `domains` is). A console process has no context, so every verb runs inside
 * {@see TenantContext::runFor()} — and a query written outside one would silently return zero rows and read
 * as "this workspace has no domains", which is the fail-shape that looks like an answer.
 *
 * @see SsoUserProvisioner for what a verified domain actually authorises.
 */
final class SsoDomainCommand extends Command
{
    protected $signature = 'sso:domains {tenant : The tenant id, slug or primary domain}
                            {--claim= : Mint a challenge token for this email domain}
                            {--verify= : Look up the challenge TXT record for this email domain}
                            {--release= : Delete this email domain, giving up the proof of control}';

    protected $description = "Manage the email domains a workspace's identity provider is trusted to assert";

    public function handle(SsoDomainService $domains): int
    {
        $tenant = TenantLocator::find((string) $this->argument('tenant'));

        if ($tenant === null) {
            $this->error('No tenant matches that id, slug or domain.');

            return self::FAILURE;
        }

        // At most one verb. Silently preferring the first would make `--claim x --release y` do something an
        // operator did not ask for, on a command whose whole subject is a security control.
        $verbs = array_filter([
            'claim' => $this->option('claim'),
            'verify' => $this->option('verify'),
            'release' => $this->option('release'),
        ], static fn (mixed $value): bool => is_string($value) && trim($value) !== '');

        if (count($verbs) > 1) {
            $this->error('Give at most one of --claim, --verify or --release.');

            return self::FAILURE;
        }

        // The verb and its argument are pulled out TOGETHER, before the closure, so the match arms below
        // cannot index a key the analyser has no way to know is present — `array_key_first()` on a filtered
        // array tells PHPStan nothing about which of the three optional offsets survived.
        $verb = array_key_first($verbs);
        $subject = $verb === null ? '' : (string) $verbs[$verb];

        /** @var int $status */
        $status = TenantContext::runFor((string) $tenant->getKey(), function () use ($tenant, $domains, $verb, $subject): int {
            return match ($verb) {
                'claim' => $this->claim($tenant, $domains, $subject),
                'verify' => $this->verify($tenant, $domains, $subject),
                'release' => $this->release($tenant, $domains, $subject),
                default => $this->list($tenant, $domains),
            };
        });

        return $status;
    }

    private function claim(Tenant $tenant, SsoDomainService $domains, string $domain): int
    {
        $row = $domains->claim($tenant, $domain);

        if ($row->isVerified()) {
            $this->warn("{$row->domain} is already verified for {$tenant->name}. Its token was NOT rotated.");

            return self::SUCCESS;
        }

        $this->info("Claimed {$row->domain} for {$tenant->name}. Publish this TXT record, then run --verify:");
        $this->line('  Name:  '.$domains->challengeName($row->domain));
        $this->line('  Value: '.$domains->expectedValue($row->verification_token));

        return self::SUCCESS;
    }

    private function verify(Tenant $tenant, SsoDomainService $domains, string $domain): int
    {
        $row = $domains->findForTenant($tenant, $domain);

        if ($row === null) {
            $this->error("{$tenant->name} has not claimed that domain. Run --claim first.");

            return self::FAILURE;
        }

        if ($domains->verify($row)) {
            $this->info("{$row->domain} is verified. This workspace's identity provider may now assert addresses in it.");

            return self::SUCCESS;
        }

        // FAILURE rather than SUCCESS, and the distinction is for whoever scripts this: "the record is not
        // there yet" and "it is there" are different answers, and a retry loop needs to be able to tell them
        // apart without parsing prose.
        $this->error("{$row->domain} is NOT verified: ".($row->verification_failure_reason->value ?? 'unknown'));
        $this->line('  Expected at: '.$domains->challengeName($row->domain));
        $this->line('  Value:       '.$domains->expectedValue($row->verification_token));

        return self::FAILURE;
    }

    private function release(Tenant $tenant, SsoDomainService $domains, string $domain): int
    {
        $row = $domains->findForTenant($tenant, $domain);

        if ($row === null) {
            $this->error("{$tenant->name} has no row for that domain.");

            return self::FAILURE;
        }

        $wasVerified = $row->isVerified();
        $domains->release($row);

        $this->info("Released {$row->domain}.");

        if ($wasVerified) {
            // Said out loud because it is the consequence an operator is least likely to have in mind: the
            // people already in stay in, and only the next NEW joiner is refused.
            $this->warn('That domain was verified. New members can no longer arrive through single sign-on at it; existing active members are unaffected.');
        }

        return self::SUCCESS;
    }

    private function list(Tenant $tenant, SsoDomainService $domains): int
    {
        $rows = $domains->forTenant($tenant);

        if ($rows->isEmpty()) {
            $this->warn("{$tenant->name} has verified no email domains, so single sign-on will admit no NEW members.");
            $this->line('  Existing active members are unaffected. Run --claim <domain> to start.');

            return self::SUCCESS;
        }

        $this->table(
            ['Domain', 'State', 'Last checked'],
            $rows->map(static fn (SsoVerifiedDomain $row): array => [
                $row->domain,
                $row->isVerified()
                    ? 'verified'
                    : 'pending ('.($row->verification_failure_reason->value ?? 'not checked yet').')',
                $row->verification_checked_at?->toDateTimeString() ?? '—',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
