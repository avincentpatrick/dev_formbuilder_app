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
 * Three builtin scalars, satisfying `QueuedMailContractTest`'s reflection rule and R3 of
 * `scripts/job-payload-lint.php`. The action URL is pre-built IN-REQUEST for the reason every sibling here
 * does it: a worker has no tenant context and no request host from which to resolve one. Same shape as
 * {@see EventNotification}.
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
        /** Absolute, pre-built in-request on the host they actually registered on. */
        public readonly string $actionUrl,
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
            ->line($joinedAWorkspace
                ? 'Start by opening a form someone has already built, or create your own — the dashboard has both.'
                : 'The next step is to create a workspace, or to accept an invitation to one.')
            ->action($joinedAWorkspace ? 'Go to your dashboard' : 'Get started', $this->actionUrl)
            // No "if you did not create this account" line: they just clicked a signed link in that mailbox,
            // which is the strongest evidence available that the address is theirs. A security disclaimer
            // here would undercut the one thing this email is for.
            ->salutation('— The Meridian team');

        return $this->branded($mail);
    }
}
