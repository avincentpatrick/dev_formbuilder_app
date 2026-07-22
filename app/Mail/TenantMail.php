<?php

declare(strict_types=1);

namespace App\Mail;

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
 * Subclasses implement envelope()/content()/attachments() (the Laravel 11 Mailable API) and, because they
 * are constructed inside a job, carry only scalars/arrays as public properties.
 */
abstract class TenantMail extends Mailable
{
    use Queueable;
    use SerializesModels;
}
