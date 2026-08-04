<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use App\Enums\AnalyticsAxis;
use App\Enums\AnalyticsFormSelection;
use App\Enums\AnalyticsGranularity;
use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Http\Requests\Api\V1\AnalyticsReportRequest;
use App\Support\Analytics\AnalyticsQuery;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The `/analytics` page's declaration, read off the query string (Increment H24b2).
 *
 * ── Why this is a separate class from {@see AnalyticsReportRequest} ───────────
 * Three reasons, in order of force.
 *
 * **(1) The API request cannot serve a cold visit.** Its `from`/`to` are `required`, which is right for an
 * API call and wrong here: a bare `GET /analytics` carries no query string at all, and a 422 on an Inertia
 * GET redirects "back" — which on a bookmarked or freshly-navigated visit is nowhere. This request defaults
 * the window instead (see {@see AnalyticsQuery::DEFAULT_RANGE_DAYS}).
 *
 * **(2) A web surface must not couple to an `Api\V1` class.** The same rule
 * `WebhookEndpointPresenter` records for presenters binds a `Tenant\` controller type-hinting an
 * `Api\V1\` FormRequest: the API's rules are a versioned contract, and a v2 fork of them would silently
 * re-shape this page.
 *
 * **(3) Sharing would drift `openapi.json`.** Scramble infers the four `/api/v1/analytics/*` request schemas
 * from that class; refactoring it to serve two callers changes four committed paths, and the contract-tests
 * job fails on a byte diff. Two objects that look alike is the cheaper truth.
 *
 * ── What is deliberately NOT duplicated ─────────────────────────────────────────────────────────────────
 * The 366-day span bound stays where H24a put it — {@see AnalyticsQuery}'s constructor — so the saved-view
 * and export paths inherit it. That means an over-long range arrives as an exception rather than a field
 * error, which is why `bootstrap/app.php` carries a web arm for it.
 */
class AnalyticsFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware (can: + feature:) owns authorization
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // `sometimes`, not `required` — see the class docblock. An absent pair means "the default window".
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'timezone' => ['sometimes', 'string', Rule::in(DateTimeZone::listIdentifiers())],
            'granularity' => ['sometimes', Rule::enum(AnalyticsGranularity::class)],

            'selection' => ['sometimes', Rule::enum(AnalyticsFormSelection::class)],
            'form_ids' => ['sometimes', 'array', 'max:'.AnalyticsQuery::MAX_FORM_IDS],
            'form_ids.*' => ['uuid', 'exists:forms,id'],
            // NO `exists:scope_nodes,id`, unlike the API twin. A saved view naming a since-deleted node must
            // reach AnalyticsPresenter::selectionRefusal() and render ADR-0011 §D8's stated refusal ON the
            // page; a validation failure would redirect instead, and on a cold visit redirect where?
            'scope_node_id' => ['sometimes', 'uuid'],

            'axis' => ['sometimes', Rule::enum(AnalyticsAxis::class)],
            'statuses' => ['sometimes', 'array'],
            'statuses.*' => [Rule::enum(SubmissionStatus::class)],
            'sources' => ['sometimes', 'array'],
            'sources.*' => [Rule::enum(SubmissionSource::class)],
            'locales' => ['sometimes', 'array'],
            'locales.*' => ['string', 'max:10'],

            'top_n' => ['sometimes', 'integer', 'min:1', 'max:'.AnalyticsQuery::MAX_TOP_N],

            // The answer-value explorer's selection. Deliberately NOT checked against the question catalogue:
            // AnswerValueAggregator::aggregate() already answers an unknown key with the `not_indexed`
            // refusal, which is the honest response and the one §D4 asks for. The key is bound as a `where`
            // parameter downstream, never interpolated.
            'question' => ['sometimes', 'string', 'max:255'],

            'format' => ['sometimes', Rule::in(['csv', 'xlsx'])],
        ];
    }

    /**
     * The resolved declaration.
     *
     * An absent `from`/`to` defaults to the same rolling window the dashboard shows, so arriving at
     * `/analytics` from the Overview tab does not silently change the numbers under the user.
     */
    public function toQuery(): AnalyticsQuery
    {
        $timezone = $this->string('timezone', 'UTC')->toString();

        $to = $this->has('to')
            ? CarbonImmutable::parse($this->string('to')->toString(), $timezone)
            : CarbonImmutable::now($timezone);

        $from = $this->has('from')
            ? CarbonImmutable::parse($this->string('from')->toString(), $timezone)
            : $to->subDays(AnalyticsQuery::DEFAULT_RANGE_DAYS);

        return new AnalyticsQuery(
            from: $from,
            to: $to,
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

    /** The question the explorer has selected, or null for "none". */
    public function questionKey(): ?string
    {
        $key = $this->string('question')->trim()->toString();

        return $key === '' ? null : $key;
    }

    /** @return 'csv'|'xlsx' */
    public function exportFormat(): string
    {
        return $this->string('format', 'csv')->toString() === 'xlsx' ? 'xlsx' : 'csv';
    }
}
