<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\ScanStatus;
use App\Models\Attachment;
use App\Models\User;

/**
 * Authorization for the authenticated read-side of a stored file (Increment G6). RLS already scopes every
 * `attachments` query to the current tenant, so a cross-tenant id never resolves (route-model binding 404s
 * before this runs) — this policy only adds the role gate: viewing an attachment requires the same
 * `submissions.view` permission the inbox does. Serving is additionally gated on the scan status
 * ({@see ScanStatus::servable()}) in the controller, so an unscanned/infected file is withheld
 * regardless of permission.
 *
 * (Respondent-facing preview needs no server round-trip — a just-captured file previews from a local
 * `blob:` URL — so there is no guest read path in G6.)
 */
final class AttachmentPolicy
{
    public function view(User $user, Attachment $attachment): bool
    {
        return $user->can('submissions.view');
    }
}
