<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use App\Enums\QueueName;
use App\Notifications\Concerns\CarriesTenantBrand;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\Attributes\Queue;

/**
 * Queued email-verification (H3). A ShouldQueue subclass of the framework's VerifyEmail that reuses its
 * wording/localization but delivers on the `mail` queue (ADR-0007 §D6).
 *
 * WHY A PRE-BUILT URL. VerifyEmail::verificationUrl() reads $notifiable->getKey()/getEmailForVerification()
 * to sign the link. A queued notification is delivered through Illuminate\Notifications\SendQueuedNotifications,
 * which serializes the notifiable via SerializesModels BEFORE the handler runs, under a NULL GUC — and a
 * User model would then fail closed under RLS (the §D5 hazard). So User::sendEmailVerificationNotification()
 * signs the URL IN-REQUEST (where the User is live, and where the URL correctly captures the REQUEST host)
 * and hands it here as a scalar; delivery is to an on-demand notifiable (Notification::route('mail', …)),
 * so nothing is ever restored from the database on the worker. Listed in scripts/job-payload-lint.php's
 * EXEMPT_JOBS (R1); R2 (#[Queue]) and R3 (the single scalar payload) still apply and are satisfied.
 */
#[Queue(QueueName::Mail)]
final class QueuedVerifyEmail extends VerifyEmail implements ShouldQueue
{
    use CarriesTenantBrand;
    use Queueable;

    public function __construct(public readonly string $signedUrl) {}

    /**
     * Return the URL pre-signed in-request; the on-demand $notifiable is never inspected.
     *
     * @param  mixed  $notifiable
     */
    protected function verificationUrl($notifiable): string
    {
        return $this->signedUrl;
    }

    /**
     * Render the framework's own verification wording through the Meridian mail template.
     *
     * ── ALWAYS THE PRODUCT PALETTE, AND THAT IS THE HONEST ANSWER (H23a4) ───────────────────────
     * Every other outbound email carries its tenant's brand. This one cannot, for two independent reasons,
     * and neither is a gap to be closed later:
     *   1. Fortify's routes (`config/fortify.php`) carry NO tenancy middleware — they run on the central
     *      host — so there is no resolved tenant at send time to take a brand from.
     *   2. A `User` may be a member of several tenants, so "which brand does this person's verification
     *      email wear" has no correct answer even if a tenant were resolvable.
     *
     * AMENDED BY M99 (R-62fb2e05): BOTH REASONS ARE ABOUT COLOUR, AND ONE SENTENCE ABOVE IS WRONG.
     * Reason 1 says these routes "run on the central host"; `config/fortify.php` sets `domain => null`
     * and its own comment records that tenant users legitimately sign in at a workspace address, so they
     * answer on whichever host the member is on. That matters because the palette also carries the
     * header's HOME LINK, which is not a branding question and does have a correct answer: the dispatch
     * site now passes the origin of this message's own action URL. Previously it fell back to
     * `config('app.url')`, which D46 puts at the agency's public website, so every tester's verification
     * mail linked its header off this application. The palette is still the PRODUCT's — no tenant
     * colours, no tenant logo — and that half stands unchanged.
     * It still renders through the Meridian template rather than stock Laravel, which is the whole reason
     * for the override: an account-level email should look like the product, not like the framework.
     *
     * `buildMailMessage()` rather than `toMail()` is the seam because it keeps `parent::`'s `Lang::get()`
     * strings — reusing the framework's localized wording is what this subclass exists for.
     *
     * @param  string  $url
     */
    protected function buildMailMessage($url): MailMessage
    {
        return $this->branded(parent::buildMailMessage($url));
    }
}
