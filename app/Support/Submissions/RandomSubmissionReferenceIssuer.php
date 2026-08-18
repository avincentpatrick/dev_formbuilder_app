<?php

declare(strict_types=1);

namespace App\Support\Submissions;

use App\Providers\AppServiceProvider;

/**
 * The production {@see SubmissionReferenceIssuer}: a fresh CSPRNG-drawn code, every time (Increment J2e).
 *
 * Stateless by construction — it holds no tenant, no counter and no cache — which is why
 * {@see AppServiceProvider} binds it as a `singleton` rather than `scoped`.
 */
final class RandomSubmissionReferenceIssuer implements SubmissionReferenceIssuer
{
    public function issue(): string
    {
        return SubmissionReference::mint();
    }
}
