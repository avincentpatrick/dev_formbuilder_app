<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use App\Enums\QueueName;
use App\Notifications\Concerns\CarriesTenantBrand;
use App\Notifications\EventNotification;
use App\Support\Branding\BrandPalette;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Attributes\Queue;

/**
 * The first email a person gets that is not a task (Increment J3a).
 *
 * Every other outbound message in this product asks for something or reports something: verify this, reset
 * that, a submission arrived, an endpoint broke. Nothing has ever simply said "you're in, here is where to
 * start" — `app/Mail/TenantMail.php` is an abstract class with zero subclasses across four increments that
 * each predicted it would be the first.
 *
 * ── ⚠️ SENT ON `Verified`, NOT ON `Registered`, AND THAT IS THE WHOLE DESIGN ──────────────────────────
 * Registration already fires exactly one email — the verification link — and Laravel's own
 * `SendEmailVerificationNotification` listens on the same `Registered` event. A welcome raised there lands
 * in the same inbox in the same second, competing with the one message the person actually has to act on.
 * Ordering the two listeners does not fix that; it just picks which one is on top.
 *
 * Moving it to `Verified` makes the welcome the *reward* for the step J3a now requires, and — because
 * `Verified` is fired by Fortify's verification controller, by `Auth::login()` flows that stamp the column,
 * and by anything else that marks an address confirmed — one listener covers **every** door into the
 * product rather than only self-registration. The cost, stated rather than discovered: **someone who never
 * verifies never gets a welcome.** That is correct. There is nothing to welcome them to yet.
 *
 * ── ⚠️ THE PRODUCT PALETTE, NEVER THE TENANT'S — THE {@see QueuedVerifyEmail} ARGUMENT VERBATIM ───────
 * Both of that class's reasons apply unchanged: Fortify's routes carry no tenancy middleware, so there is
 * no resolved tenant at send time; and a `User` may belong to several tenants, so "whose brand" has no
 * correct answer. `$brand` is therefore left at `[]` and {@see CarriesTenantBrand::branded()} resolves
 * {@see BrandPalette::product()} at render.
 *
 * It still NAMES the workspace when there is one, which is a different thing from wearing its colours and
 * costs nothing: `tenants` is an RLS-exempt central table, so the listener can resolve the host's tenant
 * without borrowing a context. An empty `$tenantName` is the central-host case — an account that belongs to
 * no workspace yet, which is a real and pre-existing state of this product (`RegistrationGate` documents it).
 *
 * ── PAYLOAD ────────────────────────────────────────────────────────────────────────────────────────────
 * Three builtin scalars, one of them NULLABLE, satisfying `QueuedMailContractTest`'s reflection rule and R3
 * of `scripts/job-payload-lint.php` — that gate unwraps a `NullableType` at `:439`, so `?string` is read as
 * `string` and the whitelist is not widened. The action URL is pre-built IN-REQUEST for the reason every
 * sibling here does it: a worker has no tenant context and no request host from which to resolve one. Same
 * shape as {@see EventNotification}.
 *
 * ⛔ `$actionUrl` IS NULL FOR AN ACCOUNT THAT BELONGS TO NO WORKSPACE, AND THAT IS THE FIX (`M106`). It
 * used to fall back to `config('app.url')` — the agency's public website — so the one email a brand-new
 * central-host account receives told them to create a workspace they cannot create, over a button that
 * left the product entirely. `D52` priced this as one string; it was three places, and this is the third.
 */
#[Queue(QueueName::Mail)]
final class WelcomeNotification extends Notification implements ShouldQueue
{
    use CarriesTenantBrand;
    use Queueable;

    public function __construct(
        public readonly string $name,
        /** The workspace they landed in, or '' on the central host — see the class docblock. */
        public readonly string $tenantName,
        /**
         * Absolute, pre-built in-request on the host they actually registered on — or NULL when this
         * person has no honest destination yet, which is the central-host case. ⛔ NULL IS NOT AN
         * OVERSIGHT AND MUST NOT BE COLLAPSED BACK TO A STRING: the previous fallback was
         * `config('app.url')`, the agency's public website, so the button sent a brand-new account
         * somewhere it could do nothing. `?string` is safe for the payload gate —
         * `scripts/job-payload-lint.php:439` unwraps a `NullableType`, so R3 still reads `string`.
         */
        public readonly ?string $actionUrl,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $joinedAWorkspace = $this->tenantName !== '';

        $mail = (new MailMessage)
            ->subject($joinedAWorkspace ? "Welcome to {$this->tenantName}" : 'Welcome to Meridian')
            ->greeting("Welcome, {$this->name}.")
            ->line($joinedAWorkspace
                ? "Your email address is confirmed and you're a member of {$this->tenantName}."
                : 'Your email address is confirmed and your account is ready.')
            // ⛔ THE CENTRAL-HOST LINE MUST NOT OFFER WORKSPACE CREATION. Nothing on this product lets an
            // account make its own workspace — provisioning is an operator action — so the previous copy
            // ("The next step is to create a workspace, or to accept an invitation to one") named a task
            // the reader cannot perform and then linked them away to find it. A workspace reaches THEM.
            ->line($joinedAWorkspace
                ? 'Start by opening a form someone has already built, or create your own — the dashboard has both.'
                : 'A workspace administrator will add you, or send you an invitation by email. Nothing else is needed from you today.');

        // ⛔ NO ACTION AT ALL WHEN THERE IS NOWHERE HONEST TO SEND THEM — and this is the half `D52`
        // omitted when it priced the fix as "one string". `resources/views/mail/notification.blade.php`
        // guards BOTH the button and the subcopy on `@isset($actionText)`, so declining to call
        // `->action()` removes both cleanly and needs no change to the template.
        if ($this->actionUrl !== null) {
            $mail->action($joinedAWorkspace ? 'Go to your dashboard' : 'Get started', $this->actionUrl);
        }

        $mail
            // No "if you did not create this account" line: they just clicked a signed link in that mailbox,
            // which is the strongest evidence available that the address is theirs. A security disclaimer
            // here would undercut the one thing this email is for.
            ->salutation('— The Meridian team');

        return $this->branded($mail);
    }
}
