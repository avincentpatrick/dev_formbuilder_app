<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use App\Exceptions\Explorer\InvalidExplorerQueryException;
use App\Support\Explorer\ExplorerQuery;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A data-explorer declaration sent to be run (M236, ADR-0025 §D2).
 *
 * The rules below check one thing — that a declaration was sent at all — and that is deliberate.
 * {@see ExplorerQuery::fromArray()} is the single reader of the shape, for a request and for a stored
 * chart alike, and every bound lives in that class's constructor. Restating them here as validator rules
 * would be a second implementation of bounds that already have an owner, the mistake
 * `AnalyticsReportRequest` records declining for the 366-day range.
 */
class ExplorerQueryRequest extends FormRequest
{
    /** The route's `can:` middleware owns authorization. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'definition' => ['required', 'array'],
        ];
    }

    /** @throws InvalidExplorerQueryException when the declaration breaks one of its bounds */
    public function toQuery(): ExplorerQuery
    {
        /** @var array<array-key, mixed> $definition */
        $definition = $this->input('definition', []);

        return ExplorerQuery::fromArray($definition);
    }
}
