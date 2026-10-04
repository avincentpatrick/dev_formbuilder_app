<?php

declare(strict_types=1);

namespace App\Support\Forms;

use App\Models\Form;

/**
 * Whether a respondent can open a form through its public link right now (M130, `R-db169c29`) — the conditions
 * `GuestFormController::mint()` answers 404 on, as one predicate: the form exists and is not trashed, it has a
 * public slug, it accepts guests, and it has a published version.
 *
 * ⛔ IT MIRRORS THE ROUTE, NOT WHAT A ROUTE SHOULD DO. An archived form still answers its link today (filed as a
 * row in M130), so it is reachable here too; `GuestReachabilityTest` drives the real route beside this predicate
 * for every case, so the day the route changes, this has to follow.
 *
 * Read by three places that must agree: the redirect resolver (where a respondent is sent), the publish gate
 * (whether a destination may be published) and the settings panel's `live` flag (what the author is warned of).
 */
final class GuestReachability
{
    public static function reachable(Form $form): bool
    {
        return ! $form->trashed()
            && is_string($form->public_slug)
            && $form->public_slug !== ''
            && $form->allow_guest_submissions
            && $form->current_published_version_id !== null;
    }
}
