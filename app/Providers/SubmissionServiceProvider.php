<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Submissions\AnswerIndexProjector;
use App\Services\Submissions\StructuralAnswerNormalizer;
use App\Services\Submissions\SubmissionPipeline;
use App\Services\Validation\SemanticValidator;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the Submission Pipeline (F4) and its Stage-1/Stage-4 helpers as singletons. The pipeline composes
 * the singleton {@see SemanticValidator} (bound by {@see ValidationServiceProvider}),
 * which in turn shares the memoised singleton expression parser/evaluator — so the whole write path reuses
 * one warm parse memo per request. All dependencies are concrete + already resolvable; singleton binding is
 * only about lifetime.
 */
final class SubmissionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StructuralAnswerNormalizer::class);
        $this->app->singleton(AnswerIndexProjector::class);
        $this->app->singleton(SubmissionPipeline::class);
    }
}
