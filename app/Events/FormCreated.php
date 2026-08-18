<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\DomainEventType;
use App\Enums\PointRule;
use App\Listeners\Entitlements\MeterSubmissionUsage;
use App\Models\Form;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A form was created (Increment K1a). Raised by {@see FormService::create()}, post-commit.
 *
 * ── ⚠️ A PLAIN EVENT, NOT A {@see DomainEvent} — THE {@see MemberJoined} PRECEDENT, FOR ITS REASONS ─────
 * {@see DomainEventType} is not a catalog, it is the webhook and native-connector SUBSCRIPTION vocabulary
 * and it feeds `openapi.json`. A case there is a four-file act with a **published contract attached
 * forever**: once `form.created` is subscribable it can never be withdrawn, and gamification — an internal
 * read-model — is a poor reason to widen a public integration surface. `form.published` already IS a domain
 * event, which is the one a tenant's own systems have a real reason to react to; creating a draft nobody
 * can answer yet is an internal milestone.
 *
 * **What that costs, stated rather than discovered:** a tenant cannot fire a webhook when somebody starts a
 * form. Promoting this to a `DomainEvent` later is additive and breaks nothing.
 *
 * ── ⚠️ POST-COMMIT, WHICH IS THE OPPOSITE OF {@see MemberJoined}'S CHOICE, AND FOR THE SAME REASON ──────
 * `MemberJoined` fires inside its transaction because `joinOpenTenant()` is the one membership write with
 * no ambient tenant context, where `SET LOCAL`'s GUC dies at commit and a post-commit strict-RLS INSERT is
 * refused. `FormService::create()` is the ordinary case: it runs in-request under session-scoped
 * {@see TenantContext::apply()}, so context outlives the commit — the
 * {@see MeterSubmissionUsage} shape, which increments strict-RLS
 * `usage_counters` from a post-commit listener today. Post-commit is preferred wherever it is available,
 * because it keeps a gamification failure from ever rolling back a real user's form.
 *
 * Scalars only, {@see DomainEvent}'s payload rule, even though this is not one of its subclasses: a
 * listener that needs the row reads it back under live RLS.
 */
final class FormCreated
{
    use Dispatchable;

    private function __construct(
        public readonly string $tenantId,
        public readonly string $formId,
        /** The creator — the member {@see PointRule::FormCreated} credits. */
        public readonly string $createdByUserId,
        public readonly string $title,
    ) {}

    public static function for(Form $form, User $creator): self
    {
        return new self(
            tenantId: (string) $form->tenant_id,
            formId: (string) $form->getKey(),
            createdByUserId: (string) $creator->getKey(),
            title: (string) $form->title,
        );
    }
}
