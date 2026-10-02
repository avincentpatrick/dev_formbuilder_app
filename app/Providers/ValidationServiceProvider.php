<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Expressions\ExpressionEvaluator;
use App\Services\Expressions\ExpressionParser;
use App\Services\Expressions\StructuredRuleLowering;
use App\Services\Forms\ExpressionValidationGate;
use App\Services\Validation\SemanticValidator;
use App\Services\Validation\StructuredRuleEvaluator;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Support\ServiceProvider;

/**
 * Binds F3's semantic-validation services as singletons so they share the singleton
 * {@see ExpressionEvaluator} / {@see ExpressionParser}
 * (bound by {@see ExpressionServiceProvider}) whose parse memo must survive the request. Auto-wiring alone
 * would hand out a fresh {@see StructuredRuleLowering} per resolve; binding it here keeps one shared instance.
 */
final class ValidationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StructuredRuleLowering::class);
        $this->app->singleton(StructuredRuleEvaluator::class);
        $this->app->singleton(SemanticValidator::class);
        $this->app->singleton(ExpressionValidationGate::class);
    }

    /**
     * Increment M125: a note's content blocks keep their whitespace. `TrimStrings` would turn "Read the " + a linked
     * "consent form" into "Read theconsent form" on the server while the builder's own copy kept the space, so the
     * preview and the published form would disagree in silence. The keys are matched with `Str::is`, so the wildcard
     * covers every block and span. Registered here rather than in `bootstrap/app.php`, which is a census hub.
     */
    public function boot(): void
    {
        TrimStrings::except(['config.content.*']);
    }
}
