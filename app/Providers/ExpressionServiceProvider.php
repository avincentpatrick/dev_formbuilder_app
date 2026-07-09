<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Expressions\ExpressionEvaluator;
use App\Services\Expressions\ExpressionLexer;
use App\Services\Expressions\ExpressionParser;
use App\Services\Expressions\FunctionRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the expression engine as singletons (technical-architecture.md §4.3 §9) so the parser's bounded
 * LRU memo survives the whole request and the lexer/registry are shared. Auto-wiring alone would hand
 * out a fresh parser (and empty memo) per resolve.
 */
final class ExpressionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FunctionRegistry::class);
        $this->app->singleton(ExpressionLexer::class);
        $this->app->singleton(ExpressionParser::class);
        $this->app->singleton(ExpressionEvaluator::class);
    }
}
