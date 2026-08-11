<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Models\User;
use App\Policies\FormPolicy;
use App\Services\Forms\FormHubPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The form hub — `GET /forms/{form}` (Increment J2b, PRD §3.7).
 *
 * Until this route existed a GET on `/forms/{form}` matched the URI and not the method, so it answered
 * **405**, and nothing anywhere in the product could link to "a form": the audit ledger sent form targets
 * to `/forms`, global search sent them to the builder (a 403 for two of the five roles), and a submission
 * printed its form's title as an unlinked heading.
 *
 * Its own controller rather than a `show()` on {@see FormController} — the `PlatformAuditController` /
 * `PlatformSettingsController` precedent, where `scripts/controller-gate.php`'s 250-line cap is cited as
 * the deciding argument for splitting rather than growing. Page assembly lives in
 * {@see FormHubPresenter}, which the gate does not scan.
 *
 * ⚠️ GATED ON `viewOverview`, WHICH IS NOT `view`. See {@see FormPolicy::viewOverview()} —
 * `view` delegates to `canEdit`, so it refuses the Reviewer and the Viewer, who are precisely the readers
 * this page exists to give a destination to. Do not "tidy" the two together.
 */
final class FormHubController extends Controller
{
    public function __invoke(Request $request, Form $form, FormHubPresenter $presenter): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('forms/Show', $presenter->show($form, $user));
    }
}
