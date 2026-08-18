<?php

declare(strict_types=1);

namespace App\Services\Sso;

use App\Enums\SsoAuthIntent;
use App\Http\Controllers\Tenant\Sso\SsoAcsController;
use App\Models\SsoAuthRequest;
use App\Models\User;

/**
 * What a validated assertion turned out to mean (Phase 4, P1c — ADR-0016 §D22).
 *
 * P1b's {@see SsoLoginService::consumeAssertion()} returned a bare {@see User}, which was the whole answer
 * while there was only one thing an assertion could be. P1c gives the ACS two arms that differ in almost
 * every observable — whether a session is created, whether provisioning runs, where the browser goes next —
 * so the service now returns the DECISION rather than one of its inputs.
 *
 * ⚠️ THE INTENT COMES FROM THE ROW, NEVER FROM THE DOCUMENT. `sso_auth_requests.intent` was written by this
 * SP before the redirect and is what {@see SsoAuthIntent}'s docblock calls "what we asked for". Nothing in a
 * SAML response says whether it is answering a login or a re-authentication, and a field that did would be
 * a field an attacker could set.
 *
 * Readonly and constructor-only: {@see SsoAcsController} reads it and does not own it.
 */
final readonly class SsoAuthOutcome
{
    public function __construct(
        public SsoAuthIntent $intent,
        public User $user,
        public SsoAuthRequest $request,
    ) {}
}
