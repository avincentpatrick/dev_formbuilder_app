<?php

declare(strict_types=1);

namespace App\Support\Forms;

use App\Models\Form;

/**
 * Whether a respondent can open a form through its public link right now (M130, `R-db169c29`) — the conditions
 * `GuestFormController::mint()` answers 404 on, as one predicate: the form exists and is not trashed, it has a
 * public slug, its link is open (`Form::allowsGuestAccess()` — guest access on and not archived, M146 `D99` A),
 * and it has a published version.
 *
 * ⛔ IT MIRRORS THE ROUTE, NOT WHAT A ROUTE SHOULD DO. `GuestReachabilityTest` drives the real route beside this
 * predicate for every case, so the day the route changes, this has to follow — M146 was that day for an archived
 * form, and the clause moved into the model so the route and this predicate cannot disagree about it.
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
            && $form->allowsGuestAccess()
            && $form->current_published_version_id !== null;
    }
}
