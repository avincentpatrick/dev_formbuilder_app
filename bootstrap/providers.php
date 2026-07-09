<?php

use App\Providers\AppServiceProvider;
use App\Providers\ExpressionServiceProvider;
use App\Providers\FortifyServiceProvider;
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
];
