<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\FormAutomationAction;
use App\Models\FormAutomation;
use App\Models\User;

/**
 * Who may change one of a form's automations (M132, `R-b7bc5149`). Every route also carries `can:update,form`, so
 * this answers only the question that route cannot: an automation that sends the ANSWERS to a web address (`D83`)
 * is a channel out of the workspace, and only holders of `webhooks.manage` — Owners and Admins — may configure one,
 * exactly as for the workspace's own webhooks. An email automation sends a notice and a link (`D82`) and is the
 * form's editors' to manage.
 *
 * Permission checks go through `$user->can()`, matching {@see FormPolicy}.
 */
final class FormAutomationPolicy
{
    public function manage(User $user, FormAutomation $automation): bool
    {
        return $automation->action !== FormAutomationAction::Webhook || $user->can('webhooks.manage');
    }
}
