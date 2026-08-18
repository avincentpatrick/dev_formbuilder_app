<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\AnalyticsAxis;
use App\Enums\AnalyticsFormSelection;
use App\Enums\AnalyticsGranularity;
use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Support\Analytics\AnalyticsQuery;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The bounded analytics declaration, as a request (ADR-0011 §D7, Increment H24a).
 *
 * ── Why all the parsing is HERE and not in the controller ───────────────────────────────────────────────
 * `scripts/controller-gate.php` caps a controller method at cyclomatic complexity 10 and counts every
 * `match` arm, `&&`, `||` and `??` toward it — and it scans only `app/Http/Controllers`, so a FormRequest is
 * the natural home for this. That is a happy alignment rather than a workaround: keeping the parse here
 * leaves the controller a three-line delegation, which is what the thin-controller convention asks for
 * anyway.
 *
 * ── Validation is the 422, the VO is the floor ──────────────────────────────────────────────────────────
 * The rules below duplicate the bounds {@see AnalyticsQuery}'s constructor enforces, deliberately: a user
 * gets a 422 with per-field errors rather than an exception page, while the VO keeps the guarantee true for
 * the saved-view and export paths that never pass through a request at all.
 */
final class AnalyticsReportRequest extends FormRequest
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
        return [
            // Mandatory: §D7(a). There is no "all time" — an unbounded range is the shape this whole
            // decision exists to prevent.
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            // Bound into date_trunc's zone argument, so it is checked against the real IANA list rather than
            // by pattern. The framework `timezone` rule, NOT `Rule::in(DateTimeZone::listIdentifiers())` —
            // they validate against the identical list, but Scramble materializes Rule::in as a ~419-entry
            // enum PER ENDPOINT in openapi.json (~20% of the whole spec), and that list is owned by PHP's
            // bundled tzdata: local PHP and CI's floating 8.4 are different builds, so any timelib bump
            // would redden contract-tests on a diff nobody authored (feature-backlog "openapi ↔ tz" row).
            'timezone' => ['sometimes', 'string', 'timezone'],
            'granularity' => ['sometimes', Rule::enum(AnalyticsGranularity::class)],

            'selection' => ['sometimes', Rule::enum(AnalyticsFormSelection::class)],
            'form_ids' => ['sometimes', 'array', 'max:'.AnalyticsQuery::MAX_FORM_IDS],
            // `exists` runs on the RLS-scoped connection, so a form id from another tenant simply is not
            // there — cross-tenant safety without a hand-written predicate.
            'form_ids.*' => ['uuid', 'exists:forms,id'],
            'scope_node_id' => ['sometimes', 'uuid', 'exists:scope_nodes,id'],

            'axis' => ['sometimes', Rule::enum(AnalyticsAxis::class)],
            'statuses' => ['sometimes', 'array'],
            'statuses.*' => [Rule::enum(SubmissionStatus::class)],
            'sources' => ['sometimes', 'array'],
            'sources.*' => [Rule::enum(SubmissionSource::class)],
            'locales' => ['sometimes', 'array'],
            'locales.*' => ['string', 'max:10'],

            'top_n' => ['sometimes', 'integer', 'min:1', 'max:'.AnalyticsQuery::MAX_TOP_N],

            // Export only; ignored by the JSON report. Same two formats as the submission export.
            'format' => ['sometimes', Rule::in(['csv', 'xlsx'])],
        ];
    }

    /**
     * The validated declaration.
     *
     * The span check is left to the VO rather than duplicated as a rule: expressing "at most 366 days
     * between two fields" in the validator would be a second implementation of a bound that already has an
     * owner, and the two could disagree.
     */
    public function toQuery(): AnalyticsQuery
    {
        $timezone = $this->string('timezone', 'UTC')->toString();

        return new AnalyticsQuery(
            from: CarbonImmutable::parse($this->string('from')->toString(), $timezone),
            to: CarbonImmutable::parse($this->string('to')->toString(), $timezone),
            timezone: $timezone,
            granularity: $this->enum('granularity', AnalyticsGranularity::class) ?? AnalyticsGranularity::Day,
            selection: $this->enum('selection', AnalyticsFormSelection::class) ?? AnalyticsFormSelection::All,
            formIds: array_values(array_filter($this->array('form_ids'), 'is_string')),
            scopeNodeId: $this->has('scope_node_id') ? $this->string('scope_node_id')->toString() : null,
            axis: $this->enum('axis', AnalyticsAxis::class) ?? AnalyticsAxis::Form,
            statuses: array_values(array_filter($this->array('statuses'), 'is_string')),
            sources: array_values(array_filter($this->array('sources'), 'is_string')),
            locales: array_values(array_filter($this->array('locales'), 'is_string')),
            topN: $this->integer('top_n', AnalyticsQuery::DEFAULT_TOP_N),
        );
    }
}
