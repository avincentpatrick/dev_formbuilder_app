<?php

declare(strict_types=1);

namespace App\Services\Explorer;

use App\Exceptions\Explorer\InvalidExplorerQueryException;
use App\Models\Form;
use App\Models\User;
use App\Services\Explorer\Sources\ActivitySource;
use App\Services\Explorer\Sources\AnswersSource;
use App\Services\Explorer\Sources\DeliveriesSource;
use App\Services\Explorer\Sources\ExplorerFormReach;
use App\Services\Explorer\Sources\ExplorerSource;
use App\Services\Explorer\Sources\FeedbackSource;
use App\Services\Explorer\Sources\FormsSource;
use App\Services\Explorer\Sources\MembersSource;
use App\Services\Explorer\Sources\PointsSource;
use App\Services\Explorer\Sources\ResponsesSource;
use App\Support\Explorer\ExplorerQuery;
use Illuminate\Contracts\Container\Container;

/**
 * The data explorer's catalogue: every data source, and which of them a reader may use (ADR-0025 §D2, §D5).
 *
 * This is the allow-list the whole feature rests on. A chart can only name a source registered in
 * {@see self::SOURCES}, and {@see resolve()} is the one door from a declaration to something that can be
 * queried — it checks the source exists, that this reader may use it, and that a form the chart names is one
 * they can reach. Nothing downstream re-asks, because nothing downstream can be reached another way.
 *
 * The order of `SOURCES` is the order of the source picker.
 */
final class ExplorerCatalog
{
    /** @var list<class-string<ExplorerSource>> */
    private const array SOURCES = [
        ResponsesSource::class,
        AnswersSource::class,
        FormsSource::class,
        MembersSource::class,
        ActivitySource::class,
        DeliveriesSource::class,
        PointsSource::class,
        FeedbackSource::class,
    ];

    /** @var array<string, ExplorerSource>|null */
    private ?array $sources = null;

    public function __construct(
        private readonly Container $container,
        private readonly ExplorerFormReach $reach,
    ) {}

    /**
     * The sources this reader may chart, in picker order.
     *
     * @return list<ExplorerSource>
     */
    public function sourcesFor(User $user): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (ExplorerSource $source): bool => $source->allows($user),
        ));
    }

    /**
     * Turn a declaration's `source` and `form_id` into a source this reader may query, and the form.
     *
     * @return array{0: ExplorerSource, 1: Form|null}
     */
    public function resolve(ExplorerQuery $query, User $user): array
    {
        $source = $this->all()[$query->source] ?? null;

        if ($source === null) {
            throw InvalidExplorerQueryException::unknownSource($query->source);
        }

        if (! $source->allows($user)) {
            throw InvalidExplorerQueryException::sourceForbidden(strtolower($source->label()));
        }

        if (! $source->requiresForm()) {
            return [$source, null];
        }

        if ($query->formId === null) {
            throw InvalidExplorerQueryException::formRequired();
        }

        $form = $this->form($user, $query->formId);

        if ($form === null) {
            throw InvalidExplorerQueryException::formUnavailable();
        }

        return [$source, $form];
    }

    /** A form this reader may chart the answers of, or null — including for an id that is not an id. */
    public function form(User $user, string $formId): ?Form
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $formId) !== 1) {
            return null;
        }

        return $this->reach->form($user, $formId);
    }

    public function source(string $key): ?ExplorerSource
    {
        return $this->all()[$key] ?? null;
    }

    /** @return array<string, ExplorerSource> */
    private function all(): array
    {
        if ($this->sources !== null) {
            return $this->sources;
        }

        $this->sources = [];

        foreach (self::SOURCES as $class) {
            $source = $this->container->make($class);
            $this->sources[$source->key()] = $source;
        }

        return $this->sources;
    }
}
