<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Actions\Fortify\PasswordValidationRules;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\Console\Exception\RuntimeException as ConsoleRuntimeException;

/**
 * The account half that both operator commands share (M95): `platform:super-admin` and `tenants:create`.
 *
 * The two commands write on DIFFERENT connections for different reasons — the grant on `pgsql_privileged`,
 * the workspace owner on the ordinary app role — so the connection is always the caller's argument and never
 * a decision made here. What IS decided here is everything a hand-rolled copy would get wrong the second time:
 *
 *  1. THE ADDRESS IS STORED LOWER-CASE. `config/fortify.php` sets `lowercase_usernames`, so sign-in lower-cases
 *     what a person types before `RlsAwareUserProvider`'s exact-match lookup, while `users_email_unique` is a
 *     plain case-sensitive btree. A mixed-case row can therefore never sign in, and the command that wrote it
 *     would have reported success. {@see canonicalEmail()}, and {@see createVerifiedAccount()} refuses anything
 *     else outright rather than trusting every future caller to remember.
 *
 *  2. A NEW ACCOUNT IS ONE `forceFill` INSERT. `User` declares `#[Fillable(['name', 'email', 'password'])]` as
 *     an ATTRIBUTE, so `email_verified_at`, `password_set_at` and `is_super_admin` passed to `create()` are
 *     discarded in silence. The obvious repair — create, then stamp — is worse: `users` carries FORCE row-level
 *     security whose SELECT policy is join-shaped, so the follow-up UPDATE matches no row on any connection that
 *     cannot see it, affects zero rows and throws nothing. `DemoSeeder::resolveOrCreateUser()` records paying
 *     for that once. No two-factor column is ever written: a placeholder secret makes Fortify challenge at sign-in
 *     with a code nobody can compute, which is a permanent lockout of the only operator.
 *
 *  3. THE PASSWORD IS TYPED TWICE AT A HIDDEN PROMPT, COMPARED, AND VALIDATED WITH THE REGISTRATION POLICY
 *     BEFORE ANY TRANSACTION OPENS. The policy is {@see PasswordValidationRules::passwordRulesUnconfirmed()} —
 *     the unconfirmed arm, because the confirmation was compared here — so a later change to
 *     `Password::defaults()` reaches the operator path by construction. Its `uncompromised()` rule is a live
 *     HTTPS lookup that FAILS OPEN when the server has no egress (the verifier catches the failure and reads it
 *     as "not breached", after its timeout), which is why the operator is told the check is happening rather
 *     than left to assume it protected them. It runs outside the transaction so no row lock is ever held across
 *     a prompt or a network call.
 *
 * ⛔ THERE IS NO `--password-stdin` AND NO GENERATED PASSWORD, AND BOTH OMISSIONS ARE DECISIONS (M95, C2).
 * Windows PowerShell 5.1 pipes to a native executable as US-ASCII (measured on the development host), so any
 * non-ASCII character in a piped password reaches PHP as `?` and the account can never be signed into with
 * what the operator typed. A generated password does not guarantee mixed case (`Str::password()` draws upper
 * and lower case from one pool, while the policy requires both), lands in console scrollback, and — for a
 * super-admin, who has no workspace and therefore no in-app page to change it — would stay until a reset email
 * arrived, which needs a mail transport and a running worker the first boot may not have yet.
 *
 * The hidden prompt is Symfony's: on Windows it runs the vendored `hiddeninput.exe`, which needs an interactive
 * console session. `$fallback` is passed as FALSE so that, where hiding is impossible, the prompt refuses rather
 * than echoing the password back to the screen.
 */
final class OperatorAccounts
{
    use PasswordValidationRules;

    /** The first prompt's exact text — the tests answer it by this string. */
    public const string PASSWORD_PROMPT = 'Password';

    /** The confirmation prompt's exact text. */
    public const string CONFIRM_PROMPT = 'Confirm password';

    /** `users.name` is `varchar(150)`; a longer value is a 22001 (a 500), never a validation message. */
    public const int NAME_MAX = 150;

    /** `users.email` is `varchar(255)`. */
    public const int EMAIL_MAX = 255;

    /** The stored form of an address: trimmed and lower-cased, which is what sign-in looks up. */
    public function canonicalEmail(string $typed): string
    {
        return Str::lower(trim($typed));
    }

    /** @return list<string> every validation message for the address, or an empty list */
    public function emailErrors(string $email): array
    {
        return $this->messages(
            ['email' => $email],
            ['email' => ['required', 'string', 'email', 'max:'.self::EMAIL_MAX]],
        );
    }

    /** @return list<string> every validation message for a display name, or an empty list */
    public function nameErrors(string $name, string $attribute = 'name'): array
    {
        return $this->messages(
            [$attribute => $name],
            [$attribute => ['required', 'string', 'max:'.self::NAME_MAX]],
        );
    }

    /**
     * The account for an exact address on the named connection, INCLUDING a soft-deleted one.
     *
     * Trashed rows are included deliberately: `users_email_unique` covers them, so an INSERT for a trashed
     * address raises 23505, and a trashed account cannot sign in anyway. Both callers refuse such an account by
     * name rather than meeting it as a constraint violation.
     *
     * Exact equality on an address the caller has already canonicalised — never a pattern — because on
     * `pgsql_auth` multi-tenancy-rbac-design.md §9 forbids a user-supplied predicate.
     */
    public function find(string $connection, string $email, bool $lockForUpdate = false): ?User
    {
        $query = User::on($connection)->withTrashed()->where('email', $email);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * Ask for a new password twice at a hidden prompt, compare, and validate it with the registration policy.
     *
     * Returns the password only when every check passed. The mismatch is decided BEFORE the breach lookup, so
     * a typo costs no network request and no timeout.
     *
     * @return array{password: string|null, errors: list<string>}
     */
    public function promptForNewPassword(Command $command): array
    {
        try {
            $first = (string) $command->secret(self::PASSWORD_PROMPT, false);
            $second = (string) $command->secret(self::CONFIRM_PROMPT, false);
        } catch (ConsoleRuntimeException $e) {
            return ['password' => null, 'errors' => [
                'The password could not be read at a hidden prompt ('.$e->getMessage().'). Run this command '
                .'from an interactive console session on the server, such as an RDP session, not through a pipe '
                .'or a remote shell.',
            ]];
        }

        if ($first !== $second) {
            return ['password' => null, 'errors' => ['The two passwords do not match.']];
        }

        $command->line(
            'Checking the password against the public breach list. If this server cannot reach the internet, '
            .'that check is skipped without an error.'
        );

        $errors = $this->messages(['password' => $first], ['password' => $this->passwordRulesUnconfirmed()]);

        return $errors === []
            ? ['password' => $first, 'errors' => []]
            : ['password' => null, 'errors' => $errors];
    }

    /**
     * Write a new, verified account as ONE INSERT on `$connection` — the class docblock's point 2.
     *
     * `password_set_at` is stamped because a person chose this password at the prompt: it is the positive half
     * of `TenantMembershipService::identityIsEstablished()`, so an invitation token can never overwrite it.
     * `email_verified_at` is stamped because the operator is attesting the address, and because the tenant
     * route group carries `verified` while a first boot may have no mail transport to send a link with.
     */
    public function createVerifiedAccount(
        string $connection,
        string $name,
        string $email,
        string $password,
        bool $superAdmin,
    ): User {
        if ($email !== $this->canonicalEmail($email)) {
            throw new InvalidArgumentException(
                "Refusing to store '{$email}': it is not in canonical (trimmed, lower-case) form, so sign-in could "
                .'never find it.'
            );
        }

        $user = new User;
        $user->setConnection($connection);
        $user->forceFill([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'email_verified_at' => now(),
            'password_set_at' => now(),
            'is_super_admin' => $superAdmin,
        ])->save();

        return $user;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $rules
     * @return list<string>
     */
    private function messages(array $data, array $rules): array
    {
        return array_values(Validator::make($data, $rules)->errors()->all());
    }
}
