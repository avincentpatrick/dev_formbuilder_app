<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Admin\SuperAdminProvisioner;
use App\Services\Auth\OperatorAccounts;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;

/**
 * Create the first platform super-admin, or grant the flag to an existing account (M95).
 *
 * WHY A COMMAND. Nothing in the application writes `is_super_admin` except two seeders that both return early
 * in production, and there is deliberately no route that could: a promotion endpoint would be the most valuable
 * thing on the platform to reach. Requiring shell access on the box is the authorization model, on the
 * `ActivateCustomDomainCommand` and `ExtractTenantCommand` precedent. It sits in `platform:` because a grant is
 * not a tenant operation; its sibling `tenants:create` is.
 *
 * This class is INPUT AND OUTPUT ONLY. {@see SuperAdminProvisioner} holds the decisions and the writes, and
 * {@see OperatorAccounts} the address, password and single-INSERT rules it shares with `tenants:create`.
 *
 * EXIT CODES, so a runbook script can tell "fix your input" from "fix the server":
 *   0  created, granted, or already a super-admin (a re-run writes nothing)
 *   1  a state this command will not act on: a role that does not bypass RLS, a deleted account, an account
 *      with a workspace membership, a change while running, a failed write check or read-back
 *   2  bad input: the address, the name, the password policy, a confirmation mismatch, or a non-interactive
 *      run that would have to create an account
 *
 * ⚠️ RUN IT FROM AN INTERACTIVE CONSOLE SESSION. A new account's password is typed twice at a hidden prompt;
 * there is no stdin or argument form, for the reasons {@see OperatorAccounts} records.
 *
 * ⚠️ IT WRITES NO AUDIT ROW. That is a deliberate gap recorded in {@see SuperAdminProvisioner}'s docblock, with
 * a system-actor audit shape filed as a before-launch row in `docs/feature-backlog.md`.
 */
final class PlatformSuperAdminCommand extends Command
{
    protected $signature = 'platform:super-admin
                            {email : The operator email address (stored lower-case, because sign-in lower-cases what is typed)}
                            {--name= : Display name, required when no account exists yet (at most 150 characters)}';

    protected $description = 'Create a platform super-admin, or grant the flag to an existing account that belongs to no workspace';

    public function handle(SuperAdminProvisioner $provisioner, OperatorAccounts $accounts): int
    {
        $email = $accounts->canonicalEmail((string) $this->argument('email'));

        $errors = $accounts->emailErrors($email);

        $nameOption = $this->option('name');
        $name = is_string($nameOption) ? trim($nameOption) : null;

        if ($name !== null) {
            $errors = [...$errors, ...$accounts->nameErrors($name)];
        }

        if ($errors !== []) {
            return $this->invalid($errors);
        }

        $role = $provisioner->privilegedRole();

        if (! $role['bypasses']) {
            $this->error(
                "pgsql_privileged connects as {$role['role']}, which has neither SUPERUSER nor BYPASSRLS. Under FORCE "
                .'row-level security on users this grant would affect no rows, and the check meant to catch that '
                .'would see no rows either. Point DB_PRIVILEGED_USERNAME at a role that bypasses RLS. Nothing was '
                .'written.'
            );

            return self::FAILURE;
        }

        $found = $provisioner->inspect($email);

        return match ($found['state']) {
            SuperAdminProvisioner::TRASHED => $this->refuse(
                "An account for {$email} exists but is deleted. Restore it deliberately before granting it the "
                .'console. Nothing was written.'
            ),
            SuperAdminProvisioner::MEMBER => $this->refuse(
                "{$email} holds {$found['memberships']} workspace membership row(s). A platform super-admin must "
                .'be a separate identity that belongs to no workspace: support can never impersonate a '
                .'super-admin, and the console two-factor gate does not cover workspace hosts. Use a different '
                .'address, for example a plus-address. Nothing was written.'
            ),
            SuperAdminProvisioner::ALREADY => $this->reportAlready($provisioner, $found['user'], $email),
            SuperAdminProvisioner::EXISTING => $this->grant($provisioner, $email),
            default => $this->create($provisioner, $accounts, $email, $name),
        };
    }

    private function create(SuperAdminProvisioner $provisioner, OperatorAccounts $accounts, string $email, ?string $name): int
    {
        if ($name === null || $name === '') {
            return $this->invalid([
                "No account exists for {$email}, so --name is required to create one (at most "
                .OperatorAccounts::NAME_MAX.' characters).',
            ]);
        }

        if (! $this->input->isInteractive()) {
            return $this->invalid([
                "No account exists for {$email}, and a new account's password is typed at a hidden prompt, which "
                .'this non-interactive run cannot show. Run it again from an interactive console session, without '
                .'--no-interaction.',
            ]);
        }

        $this->line("Creating a new account for {$email}. Type its password twice; the input is hidden.");

        $prompted = $accounts->promptForNewPassword($this);

        if ($prompted['password'] === null) {
            return $this->invalid($prompted['errors']);
        }

        try {
            $result = $provisioner->create($email, $name, $prompted['password']);
        } catch (UniqueConstraintViolationException) {
            return $this->changedWhileRunning($email);
        }

        if ($result['outcome'] !== SuperAdminProvisioner::NEW) {
            return $this->changedWhileRunning($email);
        }

        return $this->finish($provisioner, $email, "Created {$email} as a platform super-admin.");
    }

    private function grant(SuperAdminProvisioner $provisioner, string $email): int
    {
        try {
            $result = $provisioner->promote($email);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($result['outcome'] !== SuperAdminProvisioner::EXISTING || $result['user'] === null) {
            return $this->changedWhileRunning($email);
        }

        $headline = "Granted platform super-admin to {$email}."
            .($result['verifiedNow'] ? ' Its address is now marked verified.' : '');

        if ($result['user']->password_set_at === null) {
            $this->warn(
                'This account holds a password no person chose (it came from an invitation, SSO or Google sign-in). '
                .'Sign in the way it was created, or reset its password first.'
            );
        }

        return $this->finish($provisioner, $email, $headline);
    }

    private function reportAlready(SuperAdminProvisioner $provisioner, ?User $user, string $email): int
    {
        $this->info("{$email} is already a platform super-admin. Nothing was written.");

        $enrolled = $user?->two_factor_confirmed_at !== null;
        $this->nextSteps($email, $enrolled);
        $this->listOtherSuperAdmins($provisioner, $email);

        return self::SUCCESS;
    }

    /** The read-back, then the operator's next steps. Success is reported only on a write a second session saw. */
    private function finish(SuperAdminProvisioner $provisioner, string $email, string $headline): int
    {
        $readBack = $provisioner->readBack($email);

        if ($readBack === null || ! $readBack->is_super_admin) {
            $this->error(
                'The grant committed on '.SuperAdminProvisioner::CONNECTION.', but a second session on '
                .SuperAdminProvisioner::READ_BACK_CONNECTION.' cannot see it. Check the database before trusting '
                .'the console.'
            );

            return self::FAILURE;
        }

        $this->info($headline);
        $this->nextSteps($email, $readBack->two_factor_confirmed_at !== null);
        $this->listOtherSuperAdmins($provisioner, $email);

        return self::SUCCESS;
    }

    private function nextSteps(string $email, bool $enrolled): void
    {
        $this->newLine();
        $this->line('Next, before this server is reachable by anyone else:');
        $this->line('  1. Sign in at '.route('login')." as {$email}. A direct sign-in lands on a workspace page, so then open ".route('admin.tenants.index').' yourself.');
        $this->line($enrolled
            ? '  2. Two-factor is already enrolled for this account, so the console opens after a password confirmation.'
            : '  2. The console sends you to '.route('admin.mfa.setup').' first. Enrol an authenticator app there.');
        $this->line('  3. Turn off Open signup at '.route('admin.settings.index').', so nobody can register an account on this server.');
    }

    private function listOtherSuperAdmins(SuperAdminProvisioner $provisioner, string $email): void
    {
        $others = $provisioner->otherSuperAdmins($email);

        if ($others === []) {
            $this->line('No other platform super-admin exists on this database.');

            return;
        }

        $this->warn('Other platform super-admins on this database: '.implode(', ', $others));
        $this->warn(
            'Each of them can sign in to the console. One seeded by the demo seeder has a password published in '
            .'the repository.'
        );
    }

    private function changedWhileRunning(string $email): int
    {
        return $this->refuse("The account for {$email} changed while this command was running. Nothing was written; run it again.");
    }

    private function refuse(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }

    /** @param  list<string>  $errors */
    private function invalid(array $errors): int
    {
        foreach ($errors as $error) {
            $this->error($error);
        }

        $this->line('Nothing was written.');

        return self::INVALID;
    }
}
