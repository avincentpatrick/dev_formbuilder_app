<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Exceptions\Forms\PublishValidationException;
use App\Models\Form;
use App\Rules\RedirectUrl;
use App\Support\Forms\GuestReachability;

/**
 * Publish refuses a form whose after-submit destination is not one (M130, `R-db169c29`, `D76`): another form no
 * respondent can open, or a stored address the redirect rule refuses. The fourth gate beside structure,
 * expressions and templates, and run in the same step, so a doomed publish is never serialized.
 *
 * ⚠️ NOT THE ONLY GUARD, BECAUSE THE DESTINATION OUTLIVES A PUBLISH. It lives on `forms` and changes without one,
 * and the target form can be unpublished at any time afterwards, so the submit response resolves the destination
 * again at acceptance ({@see FormRedirectResolver}) and sends nobody to a form that has stopped taking guests.
 * This gate is the early, named refusal an author can act on; the resolver is what a respondent relies on.
 */
final class RedirectValidationGate
{
    /**
     * @throws PublishValidationException
     */
    public function assertRedirectResolves(Form $form): void
    {
        if ($form->redirect_form_id !== null) {
            $target = Form::query()->whereKey($form->redirect_form_id)->first();

            if ($target === null || ! GuestReachability::reachable($target)) {
                throw PublishValidationException::redirectTargetUnavailable();
            }
        }

        if ($form->redirect_url !== null && ! RedirectUrl::isSafe($form->redirect_url)) {
            throw PublishValidationException::redirectUrlInvalid();
        }
    }
}
