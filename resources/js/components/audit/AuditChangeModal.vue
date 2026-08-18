<script setup lang="ts">
/**
 * The before/after detail for one audit row (Increment I2).
 *
 * ── Why a modal rather than an inline cell or an expanding row ─────────────────────────────────────────
 * An expanding row is architecturally unavailable: `MdsDataTable` renders exactly one `<tr>` per row and
 * exposes only `#cell-*`/`#row-actions`, so an accordion would mean editing a shared primitive in six
 * other tables' blast radius, for one page's benefit.
 *
 * An inline diff cell fails at 375px, where the DataTable turns each `<td>` into a right-aligned flex row
 * with a `::before` pseudo-label — a multi-row diff would be crushed into roughly half a card row, and a
 * long unbroken value there is the most plausible source of a horizontal-overflow failure in the axe
 * sweep. `MdsModal` becomes a full-bleed sheet below 480px, so the diff gets the ENTIRE viewport instead.
 * That is a measured property, not a preference.
 *
 * ── Why a <table> and not a <dl>, <ins> or <del> ───────────────────────────────────────────────────────
 * A diff is two-dimensional (N fields × before/after); a description list is one-dimensional. Two `<dd>`s
 * per `<dt>` is legal HTML that announces as "Title, definition Draft Survey, definition 2026 Health
 * Survey" — with NO indication which is which, and the relationship IS the content here.
 *
 * `<ins>`/`<del>` are worse than plain markup: screen readers announce them only at raised verbosity, so
 * they would LOOK like semantics while being silent; they mean intra-string text edits, not whole-field
 * replacements; and on a redacted row `<del>[REDACTED]</del><ins>[REDACTED]</ins>` would assert a change
 * to a value nobody has.
 *
 * Below 480px the header row is hidden and each cell carries its own label via `data-label` +
 * `content: attr(...)` — the DataTable's own mobile technique, hand-written here, so one DOM serves every
 * width and the header association survives for assistive tech throughout.
 *
 * ── No red/green tinting ───────────────────────────────────────────────────────────────────────────────
 * Nothing in an audit diff is "bad": red-on-before / green-on-after imports a code-review metaphor into a
 * record where "before" carries no negative valence. It would also pair `--mds-color-text-body` with a
 * status BACKGROUND token whose only verified pairing is its own `-fg`, which the contrast gate measures
 * in both themes. The column headers are the direction signifier, and that satisfies 1.4.1 for free.
 */
import { MdsBadge, MdsModal } from '@meridian/design-system';
import type { AuditRow } from './types';

const props = defineProps<{ row: AuditRow | null }>();
defineEmits<{ close: [] }>();

function formatDate(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

/** An absent key reads as "(not set)" — distinct from the em-dash the server sends for a stored null. */
function side(value: string | null): string {
    return value === null ? '(not set)' : value;
}
</script>

<template>
    <MdsModal
        :open="props.row !== null"
        :title="props.row ? `${props.row.event_label} · ${props.row.target.type_label}` : 'Change detail'"
        @close="$emit('close')"
    >
        <template v-if="props.row">
            <dl class="audit-detail__meta">
                <div class="audit-detail__meta-row">
                    <dt>When</dt>
                    <dd>{{ formatDate(props.row.created_at) }}</dd>
                </div>
                <div class="audit-detail__meta-row">
                    <dt>Actor</dt>
                    <dd>{{ props.row.actor }}</dd>
                </div>
                <!--
                    I11a. This modal is the surface built for "what exactly happened here", so omitting the
                    impersonation fact here while showing it in the table would hide it on the one screen a
                    compliance reader opens to find it. Its own row rather than a suffix on Actor, because
                    `<dt>Actor</dt>` must keep meaning whose authority the action ran under.
                -->
                <div v-if="props.row.acting_as" class="audit-detail__meta-row">
                    <dt>Acting as</dt>
                    <dd>{{ props.row.acting_as }}</dd>
                </div>
                <div class="audit-detail__meta-row">
                    <dt>Target</dt>
                    <dd>
                        {{ props.row.target.label ?? props.row.target.type_label }}
                        <span class="audit-detail__mono">{{ props.row.target.id }}</span>
                    </dd>
                </div>
                <div class="audit-detail__meta-row">
                    <dt>IP address</dt>
                    <dd class="audit-detail__mono">{{ props.row.ip_address ?? '—' }}</dd>
                </div>
            </dl>

            <p v-if="props.row.redacted_fields.length" class="audit-detail__redaction">
                Some values are not shown. The ledger stores a placeholder for sensitive and erased fields,
                so the original values were never recorded here — <strong>including the “before” value</strong>.
            </p>

            <table v-if="props.row.changes.length" class="audit-detail__diff">
                <caption class="audit-detail__caption">
                    Fields changed by this {{ props.row.event_label.toLowerCase() }}
                </caption>
                <thead>
                    <tr>
                        <th scope="col">Field</th>
                        <th scope="col">Before</th>
                        <th scope="col">After</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="change in props.row.changes" :key="change.key">
                        <td data-label="Field" class="audit-detail__key">{{ change.key }}</td>
                        <template v-if="change.redacted">
                            <!--
                                A `Redacted` badge, never the literal placeholder string: a sentinel printed
                                in a value column reads as if it WERE the value.
                            -->
                            <td data-label="Before"><MdsBadge variant="neutral" label="Redacted" /></td>
                            <td data-label="After"><MdsBadge variant="neutral" label="Redacted" /></td>
                        </template>
                        <template v-else>
                            <td data-label="Before" class="audit-detail__value">{{ side(change.old) }}</td>
                            <td data-label="After" class="audit-detail__value">{{ side(change.new) }}</td>
                        </template>
                    </tr>
                </tbody>
            </table>

            <p v-else class="audit-detail__none">
                This event records an action rather than a field change, so there is nothing to compare.
            </p>
        </template>
    </MdsModal>
</template>

<style scoped>
.audit-detail__meta {
    margin: 0 0 var(--mds-space-4);
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
}

.audit-detail__meta-row {
    display: grid;
    grid-template-columns: 8rem 1fr;
    gap: var(--mds-space-3);
    align-items: baseline;
}

.audit-detail__meta-row dt {
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-secondary);
}

.audit-detail__meta-row dd {
    margin: 0;
    color: var(--mds-color-text-body);
    overflow-wrap: anywhere;
}

.audit-detail__mono {
    font-family: var(--mds-font-family-mono);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.audit-detail__redaction {
    margin: 0 0 var(--mds-space-4);
    padding: var(--mds-space-3);
    border-radius: var(--mds-radius-md);
    background: var(--mds-color-bg-sunken);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-text-secondary);
}

.audit-detail__diff {
    width: 100%;
    border-collapse: collapse;
    text-align: start;
}

.audit-detail__caption {
    /* Visually hidden, still announced — the table's accessible name. */
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip-path: inset(50%);
    white-space: nowrap;
}

.audit-detail__diff th {
    padding: var(--mds-space-2) var(--mds-space-3);
    border-bottom: 1px solid var(--mds-color-border-default);
    text-align: start;
    font-size: var(--mds-type-label-font-size);
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-secondary);
}

.audit-detail__diff td {
    padding: var(--mds-space-2) var(--mds-space-3);
    border-bottom: 1px solid var(--mds-color-border-default);
    vertical-align: top;
    color: var(--mds-color-text-body);
    overflow-wrap: anywhere;
}

.audit-detail__key {
    font-family: var(--mds-font-family-mono);
    font-size: var(--mds-type-body-sm-font-size);
}

.audit-detail__value {
    background: var(--mds-color-bg-sunken);
    font-family: var(--mds-font-family-mono);
    font-size: var(--mds-type-body-sm-font-size);
}

.audit-detail__none {
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

@media (max-width: 480px) {
    .audit-detail__meta-row {
        grid-template-columns: 1fr;
        gap: var(--mds-space-1);
    }

    /*
     * The DataTable's own mobile technique, hand-written: hide the header row and label each cell from its
     * `data-label`. One DOM at every width, so the <th scope="col"> association survives for assistive
     * tech even where the header is not painted.
     */
    .audit-detail__diff thead {
        position: absolute;
        width: 1px;
        height: 1px;
        overflow: hidden;
        clip-path: inset(50%);
    }

    .audit-detail__diff tr {
        display: block;
        margin-bottom: var(--mds-space-3);
        border-bottom: 1px solid var(--mds-color-border-default);
    }

    .audit-detail__diff td {
        display: block;
        border-bottom: 0;
        padding: var(--mds-space-1) 0;
    }

    .audit-detail__diff td::before {
        content: attr(data-label);
        display: block;
        font-family: var(--mds-font-family-body);
        font-size: var(--mds-type-label-font-size);
        color: var(--mds-color-text-secondary);
    }
}
</style>
