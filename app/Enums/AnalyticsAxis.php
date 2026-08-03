<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The axes cross-form analytics may group by (ADR-0011 §D6, Increment H24a).
 *
 * §D6 settles the headline question — **the grouping axis is `forms.scope_node_id` and no tag model is
 * coined** — and names the secondary axes that already exist and may be offered alongside it. This enum is
 * that list, closed: an axis not here is a decision, not an omission.
 *
 * `docs/PRD.md:203` asks for "all forms tagged to one program or campaign", and §D6 records why a tag model
 * is not built: it would be a new tenant table plus a new authorization question ("can a Form Editor see a
 * tag's aggregate?"), invented inside an already-large increment to satisfy one word of an example, when an
 * indexed, grant-integrated, subtree-queryable axis already exists. A tag earns its migration when a tenant
 * needs a grouping ORTHOGONAL to authorization — one form in two campaigns — and not before.
 *
 * Lives in an enum rather than in a controller `match` for a mechanical reason as well as a tidiness one:
 * `scripts/controller-gate.php` counts every `match` arm toward a method's cyclomatic complexity and caps it
 * at 10, and it does not scan `app/Enums`.
 */
enum AnalyticsAxis: string
{
    /** `submissions.form_id`. The PRD's "top forms by submission volume". */
    case Form = 'form';

    /**
     * `submissions.source` — manual / guest / ocr_single / ocr_linelist / offline_sync / api_import. Six
     * values, which is why §D12 notes this breakdown never strains the five-series categorical palette: a
     * single-series chart uses no categorical colour at all.
     */
    case Source = 'source';

    /** `submissions.status`, within the countable set (so never `draft`). */
    case Status = 'status';

    /**
     * `forms.scope_node_id`, resolved through the form. §D6 states three properties of this column that the
     * service must honour rather than assume: it is **single-valued**, so it cannot express the
     * multi-membership "tagged" implies; it is **NULL for every form** until explicitly assigned; and
     * analytics adopts `nodeReach()`'s semantics, so a deactivated node and its branch are excluded.
     */
    case ScopeNode = 'scope_node';

    /** `submissions.locale` — the locale the response was actually filled in. */
    case Locale = 'locale';

    /**
     * True when this axis can produce rows with no value, which must surface as an explicit bucket rather
     * than a silently short total (§D6).
     *
     * `scope_node` is NULL until a form is assigned — which is every form's state after the G10a backfill —
     * and `locale` is nullable on `submissions`. `form_id`, `source` and `status` are all NOT NULL.
     */
    public function hasUnassignedBucket(): bool
    {
        return match ($this) {
            self::ScopeNode, self::Locale => true,
            self::Form, self::Source, self::Status => false,
        };
    }

    /** Whether grouping resolves through `forms` rather than off `submissions` directly. */
    public function groupsThroughForm(): bool
    {
        return match ($this) {
            self::ScopeNode => true,
            self::Form, self::Source, self::Status, self::Locale => false,
        };
    }

    /**
     * The qualified column the aggregate groups by. `scope_node` reads off the joined `forms` row; every
     * other axis is a `submissions` column, which is what keeps the common cases off the join entirely.
     */
    public function column(): string
    {
        return match ($this) {
            self::Form => 'submissions.form_id',
            self::Source => 'submissions.source',
            self::Status => 'submissions.status',
            self::Locale => 'submissions.locale',
            self::ScopeNode => 'forms.scope_node_id',
        };
    }
}
