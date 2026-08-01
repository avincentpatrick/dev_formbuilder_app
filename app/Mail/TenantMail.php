<?php

declare(strict_types=1);

namespace App\Mail;

use App\Notifications\ResumeLinkNotification;
use App\Notifications\Submissions\SubmissionPdfReadyNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * The base for transactional Mailables (H3, the "app/Mail base" the H-map calls for).
 *
 * DELIBERATELY NOT ShouldQueue. A TenantMail is meant to be BUILT and SENT SYNCHRONOUSLY from inside a
 * substrate job — a queue entry (a TenantAwareJob, or a framework queued Notification) is what carries
 * the tenant-context / fairness / lifecycle guarantees of ADR-0007. Making a TenantMail ShouldQueue
 * would route it through the framework's SendQueuedMailable, bypassing the substrate and reintroducing
 * the model-payload hazard §D5 exists to prevent (SerializesModels would restore any model property
 * under a NULL GUC, before the handler, and RLS would fail it closed). Because it is not ShouldQueue it
 * is invisible to scripts/job-payload-lint.php.
 *
 * It ships with NO concrete subclass in H3 — H3's only transactional email (the tenant invitation) is a
 * Notification, and the auth emails subclass the framework's own notifications. This base is introduced
 * now to pin the convention ("plain Mailable, rendered inside a job, scalar-only public properties") and
 * to give the first real consumer a home: the resume email (H9/H10) and the submission-PDF receipt (H17),
 * where a Mailable with a Blade view earns its keep over a MailMessage. Global "from" stays
 * config('mail.from'); branded per-tenant envelopes/templates layer in at H23, and the H1h output-encoding
 * contract governs any tenant-controlled value a subclass interpolates into HTML.
 *
 * ── AMENDMENT (H17): BOTH predicted consumers declined, and the prediction is the thing that was wrong ──
 * The resume email shipped in H9b as {@see ResumeLinkNotification}, a queued
 * Notification with a MailMessage. The submission-PDF receipt shipped in H17 as
 * {@see SubmissionPdfReadyNotification}, likewise. So this class STILL has
 * no subclass, and the paragraph above should be read as a hypothesis the evidence has since gone against
 * rather than a plan someone forgot to execute.
 *
 * H17's reasoning, recorded here so the next increment does not re-derive it: all EIGHT outbound emails in
 * the application are Notifications with a MailMessage; there is no Blade mail layout, no
 * `resources/views/mail`, no published vendor mail views and no `lang/` directory — so a `TenantMail`
 * subclass is not "one class", it is the first of all of those. And because a TenantMail is not
 * ShouldQueue it is invisible to job-payload-lint (see above), where a queued Notification is bound by R2
 * and R3. For a one-link message that trade is the wrong way round.
 *
 * This base is still worth keeping: the case it was written for — a genuinely designed, multi-section
 * branded email — is H23's branding work, not a receipt. What is NOT true any more is that H9/H10 or H17
 * will be the consumer that proves it.
 *
 * Subclasses implement envelope()/content()/attachments() (the Laravel 11 Mailable API) and, because they
 * are constructed inside a job, carry only scalars/arrays as public properties.
 */
abstract class TenantMail extends Mailable
{
    use Queueable;
    use SerializesModels;
}
