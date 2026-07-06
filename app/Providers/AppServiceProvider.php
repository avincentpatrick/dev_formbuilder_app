<?php

namespace App\Providers;

use App\Models\Form;
use App\Policies\FormPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Per-form `.any`/`.own` authorization (Increment D2). Registered explicitly rather than relying
        // on auto-discovery so the mapping is greppable alongside the other RBAC wiring.
        Gate::policy(Form::class, FormPolicy::class);
    }
}
