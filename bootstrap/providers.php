<?php

use App\Providers\AppServiceProvider;
use App\Providers\ExpressionServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\QueueServiceProvider;
use App\Providers\SubmissionServiceProvider;
use App\Providers\TenancyServiceProvider;
use App\Providers\ValidationServiceProvider;

return [
    AppServiceProvider::class,
    ExpressionServiceProvider::class,
    ValidationServiceProvider::class,
    SubmissionServiceProvider::class,
    FortifyServiceProvider::class,
    TenancyServiceProvider::class,
    // ADR-0007 §D4/§D9. If this is ever removed, the tenant-context listeners stop firing AND the
    // fairness limiter FAILS OPEN silently (RateLimited passes every job through when the named
    // limiter is unregistered) — neither of which announces itself.
    QueueServiceProvider::class,
];
