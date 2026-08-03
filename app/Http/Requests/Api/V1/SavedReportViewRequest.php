<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Support\Analytics\AnalyticsQuery;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create or update a saved analytics report (ADR-0011 §D8, Increment H24a).
 *
 * `definition` is validated only for SHAPE here — that it is an array. Its CONTENT is validated by
 * {@see AnalyticsQuery::fromArray()} inside `SavedReportViewService`, which is the
 * one place that knows what a legal declaration is. Duplicating those rules as a nested validator would be
 * a second implementation of §D7's bounds, and the two could disagree — which is precisely the drift the VO
 * exists to prevent.
 *
 * The name's uniqueness is NOT a rule either: `(tenant_id, user_id, name)` is a real race between two tabs,
 * so the unique index is the authority and the service maps SQLSTATE 23505 to a 422. A `unique` rule here
 * would give a nicer message most of the time and a 500 the rest.
 */
final class SavedReportViewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware (ability + can: + feature) owns authorization
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:150'],
            'definition' => [$required, 'array'],
        ];
    }
}
