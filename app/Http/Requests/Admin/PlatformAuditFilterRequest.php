<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Services\Admin\SuperAdminService;
use App\Services\Audit\PlatformAuditPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Filters for the PLATFORM audit viewer (Increment I7b).
 *
 * ── Every rule is `sometimes`, never `required` ─────────────────────────────────────────────────────────
 * A bare `GET /admin/audit-log` carries no query string at all, and a 422 on an Inertia GET redirects
 * "back" — which on a cold visit is nowhere. Restated here rather than inherited because it is the single
 * easiest thing to undo by accident.
 *
 * ── This is a NEW class and deliberately not `Tenant\AuditLogFilterRequest` ─────────────────────────────
 * That class carries the export `format` rule and the `event`/`auditable_type` rules this surface does not
 * offer, its own docblock already forbids sharing it with the API, and coupling a tenant-facing filter
 * contract to a platform page's evolution is the same mistake in the other direction. Three rules is not
 * enough shared surface to justify a base class.
 *
 * ── No `event` / `auditable_type` rule at all ───────────────────────────────────────────────────────────
 * The page offers neither filter (see {@see PlatformAuditPresenter}). Their ABSENCE
 * here is what makes a bookmarked URL carrying them render an ordinary unfiltered page instead of a 422:
 * unlisted query keys are ignored, and no `prohibited` rule may be added to "tidy" that up.
 *
 * ── `page` is validated but NOT clamped here ────────────────────────────────────────────────────────────
 * `?page=999` on a three-row ledger is not an error — the ledger simply has fewer pages — and rejecting it
 * would 422 an Inertia GET. {@see SuperAdminService::listPlatformAudits()} clamps to
 * the last page instead, which is also what keeps `empty_reason` honest.
 */
final class PlatformAuditFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware (`superadmin` + `superadmin.mfa`) owns authorization
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['sometimes', 'uuid'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /**
     * `to` is taken to the END of that day, matching the tenant viewer: a same-day filter that resolved to
     * midnight would exclude the whole of the day it names. `from_raw`/`to_raw` carry the untouched `Y-m-d`
     * strings back so the two date inputs repopulate with what the server actually applied.
     *
     * @return array{user_id: ?string, from: ?CarbonImmutable, to: ?CarbonImmutable, from_raw: ?string, to_raw: ?string}
     */
    public function filters(): array
    {
        $from = $this->stringOrNull('from');
        $to = $this->stringOrNull('to');

        return [
            'user_id' => $this->stringOrNull('user_id'),
            'from' => $from === null ? null : CarbonImmutable::parse($from)->startOfDay(),
            'to' => $to === null ? null : CarbonImmutable::parse($to)->endOfDay(),
            'from_raw' => $from,
            'to_raw' => $to,
        ];
    }

    /** `ctype_digit` rather than a cast, so `?page[]=1` cannot reach the service as an array. */
    public function page(): int
    {
        $page = $this->query('page');

        return is_string($page) && ctype_digit($page) ? max(1, (int) $page) : 1;
    }

    /** A present, non-empty scalar query value — guards `?user_id[]=x` reaching a `where` as an array. */
    private function stringOrNull(string $key): ?string
    {
        $value = $this->query($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
