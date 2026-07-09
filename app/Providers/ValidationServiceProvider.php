<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Expressions\ExpressionEvaluator;
use App\Services\Expressions\ExpressionParser;
use App\Services\Expressions\StructuredRuleLowering;
use App\Services\Forms\ExpressionValidationGate;
use App\Services\Validation\SemanticValidator;
use App\Services\Validation\StructuredRuleEvaluator;
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
}
