<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * H23a2 stores `attachments.attachable_type = 'tenant'` for a brand logo, and that alias is DELIBERATELY
 * absent from the global morph map. This file pins the absence, because it looks exactly like an omission.
 *
 * **Why it must stay absent.** `AppServiceProvider`'s morph-map block carries an explicit warning, written
 * for H4's `audits.auditable_type`: registering `tenant` (or `users`) globally would change how Sanctum's
 * `tokenable_type` and Spatie's `model_type` serialize a Tenant, splitting existing rows between the alias
 * and the FQCN — the same class of break that `enforceMorphMap` caused and that cost 90 test failures.
 *
 * **Why it is safe to store an unregistered alias.** Nothing resolves `$attachment->attachable` anywhere
 * in this codebase; the read path for a logo is `tenants.logo_attachment_id`, a real FK, in the other
 * direction. That is the same posture H4 took — store the alias as a plain string, never morph-resolve it.
 *
 * If a future increment genuinely needs `$attachment->attachable` on a branding logo, the fix is a LOCAL
 * resolution (a match on `kind`, or a dedicated relation), never a global registration.
 */
it('keeps the tenant morph alias out of the global map', function (): void {
    expect(Relation::morphMap())->not->toHaveKey('tenant')
        ->and(Relation::morphMap())->not->toHaveKey('users');
});

it('still registers the aliases the attachments table actually resolves', function (): void {
    // Anti-vacuity: if the morph map were empty (a provider that stopped running, a refactor that moved
    // the call), the assertion above would pass while proving nothing.
    expect(Relation::morphMap())->toHaveKeys(['submission', 'form_field', 'webhook_delivery']);
});
