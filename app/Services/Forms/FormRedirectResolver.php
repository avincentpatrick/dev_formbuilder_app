<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Models\Form;
use App\Models\Tenant;
use App\Rules\RedirectUrl;
use App\Support\Forms\GuestReachability;
use App\Support\Tenancy\TenantUrl;

/**
 * Where a respondent of this form is sent after the thank-you screen, resolved NOW (M130, `R-db169c29`, `D76`).
 *
 * Read when a submission is accepted rather than when the form is loaded: the destination lives on `forms`, an
 * author changes it without republishing, and a respondent who opened the form an hour ago should go where the
 * author points today. Null means stay on the thank-you screen — and that is also the answer for a destination
 * that has stopped being one: a form that no longer takes guests or was unpublished since, and a stored address
 * this rule would refuse today. A respondent is never sent somewhere the author could not send them now.
 */
final class FormRedirectResolver
{
    /**
     * @return array{url: string, label: string}|null
     */
    public function resolve(Form $form): ?array
    {
        if ($form->redirect_form_id !== null) {
            $target = Form::query()->whereKey($form->redirect_form_id)->first();

            if ($target === null || ! GuestReachability::reachable($target)) {
                return null;
            }

            // `tenants` is RLS-exempt, so this reads under the guest context exactly as the resume link does.
            $tenant = Tenant::query()->whereKey($form->tenant_id)->firstOrFail();

            return [
                'url' => TenantUrl::toPublic($tenant, 'f/'.$target->public_slug),
                'label' => $target->title,
            ];
        }

        if ($form->redirect_url !== null && RedirectUrl::isSafe($form->redirect_url)) {
            return [
                'url' => $form->redirect_url,
                'label' => (string) parse_url($form->redirect_url, PHP_URL_HOST),
            ];
        }

        return null;
    }
}
