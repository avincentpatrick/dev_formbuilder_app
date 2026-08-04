<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use App\Services\Analytics\SavedReportViewService;
use App\Support\Analytics\AnalyticsQuery;

/**
 * A saved-view write from the `/analytics` page (ADR-0011 §D8, Increment H24b2).
 *
 * ── The client never builds a `definition` ──────────────────────────────────────────────────────────────
 * It posts a `name` plus the SAME flat filter params the page already round-trips through the query string,
 * and the controller stores `$request->toQuery()->toArray()`. So {@see AnalyticsQuery}
 * stays the single author of the persisted shape — its key names, its defaults and its `SCHEMA_VERSION` —
 * and the browser cannot write a `v` that `fromArray()` will later refuse to read.
 *
 * That also means every bound in the VO's constructor is enforced on the way in: a saved view cannot be
 * created carrying an over-long range or an empty `forms` selection.
 */
final class SaveReportViewRequest extends AnalyticsFilterRequest
{
    /**
     * The eleven declaration keys. A PATCH that carries none of them is a rename, and must not rewrite the
     * stored declaration — see {@see self::definitionOrNull()}.
     *
     * @var list<string>
     */
    private const array DECLARATION_KEYS = [
        'from', 'to', 'timezone', 'granularity', 'selection',
        'form_ids', 'scope_node_id', 'axis', 'statuses', 'sources', 'locales', 'top_n',
    ];

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            ...parent::rules(),
            'name' => [$required, 'string', 'max:150'],
        ];
    }

    /**
     * The declaration to persist, or null to leave the stored one untouched.
     *
     * **This exists to close a silent-overwrite.** {@see AnalyticsFilterRequest::toQuery()} defaults an
     * absent range to the rolling window — correct for reading a page, catastrophic on a PATCH: renaming
     * "Q1 field team" would quietly move its range to the last thirty days and the user would never see it
     * happen. {@see SavedReportViewService::update()} already takes a nullable
     * definition; this decides when to send one.
     *
     * The check lives here rather than in the controller because `scripts/controller-gate.php` counts every
     * one of these disjunctions toward a method's complexity, and does not scan `app/Http/Requests`.
     *
     * @return array<string, mixed>|null
     */
    public function definitionOrNull(): ?array
    {
        foreach (self::DECLARATION_KEYS as $key) {
            if ($this->has($key)) {
                return $this->toQuery()->toArray();
            }
        }

        return null;
    }
}
