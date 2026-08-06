<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use App\Enums\AuditEvent;
use App\Http\Controllers\Api\V1\AuditApiController;
use App\Http\Controllers\Tenant\SubmissionInboxController;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters for the audit-log viewer and its export (Increment I2).
 *
 * ── Every rule is `sometimes`, never `required` ───────────────────────────────────────────────────────
 * A bare `GET /audit-log` carries no query string at all, and a 422 on an Inertia GET redirects "back" —
 * which on a cold visit is nowhere. This is {@see AnalyticsFilterRequest}'s reason, restated because it is
 * the single easiest thing to undo by accident.
 *
 * ── `auditable_type` is a loose string, deliberately ──────────────────────────────────────────────────
 * NOT `Rule::in(…)` over the `AuditableTypes` catalog. A bookmarked URL naming an alias it no longer lists
 * must render an EMPTY RESULT SET on the page, not redirect to nowhere — the same call
 * `AnalyticsFilterRequest` records for `scope_node_id`. `max:100` matches the column width, and the value
 * is bound as a `where` parameter downstream, never interpolated.
 *
 * ── This class is NOT shared with the API, and that is a prohibition ──────────────────────────────────
 * {@see AuditApiController} reads a bare `Request`, so there is nothing to reuse — and refactoring it to
 * take this class would newly couple the web page to a Scramble-inferred schema, changing `/api/v1/audits`'
 * query parameters in `openapi.json` and reddening the contract-tests job on a byte diff.
 */
final class AuditLogFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware (can:viewAny,Audit) owns authorization
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'auditable_type' => ['sometimes', 'string', 'max:100'],
            'event' => ['sometimes', Rule::enum(AuditEvent::class)],
            'user_id' => ['sometimes', 'uuid'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'format' => ['sometimes', Rule::in(['csv', 'xlsx'])],
        ];
    }

    /**
     * The resolved filter set, shared by the page and the export so the two cannot diverge.
     *
     * ⚠️ `to` is taken to the END of that day, and this is a genuine behaviour divergence from the API
     * twin: {@see AuditApiController} does a naive `where('created_at', '<=', $request->date('to'))`, which
     * resolves to midnight and therefore EXCLUDES THE WHOLE OF THE `to` DAY — a same-day filter returns
     * nothing. Fixing it there would change a published contract; the web surface simply gets it right.
     * That divergence is itself a reason the two do not share this class.
     *
     * `from_raw`/`to_raw` carry the untouched `Y-m-d` strings back to the page, because the two date
     * inputs repopulate from what the SERVER applied — a control that disagrees with the filter in force
     * is the page reporting one thing and doing another.
     *
     * @return array{auditable_type: ?string, event: ?string, user_id: ?string, from: ?CarbonImmutable, to: ?CarbonImmutable, from_raw: ?string, to_raw: ?string}
     */
    public function filters(): array
    {
        $from = $this->stringOrNull('from');
        $to = $this->stringOrNull('to');

        return [
            'auditable_type' => $this->stringOrNull('auditable_type'),
            'event' => $this->stringOrNull('event'),
            'user_id' => $this->stringOrNull('user_id'),
            'from' => $from === null ? null : CarbonImmutable::parse($from)->startOfDay(),
            'to' => $to === null ? null : CarbonImmutable::parse($to)->endOfDay(),
            'from_raw' => $from,
            'to_raw' => $to,
        ];
    }

    /** @return 'csv'|'xlsx' */
    public function exportFormat(): string
    {
        return $this->string('format', 'csv')->toString() === 'xlsx' ? 'xlsx' : 'csv';
    }

    /**
     * A present, non-empty scalar query value — guards `?event[]=x`, which would otherwise reach a `where`
     * as an array. The {@see SubmissionInboxController} idiom.
     */
    private function stringOrNull(string $key): ?string
    {
        $value = $this->query($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
