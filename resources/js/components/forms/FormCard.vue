<script setup lang="ts">
/**
 * One form in the forms-list card grid (JR3) — the Vivid direction's list-page shape, built from the
 * data the page was already loading and rendering nowhere.
 *
 * ⚠️ THE CARD IS STATIC, NEVER `interactive`. `MdsCard`'s `interactive` prop renders a real
 * `<button>`/`<a>` root, which is right for a whole-card target and wrong here: this card contains a
 * title link and nine action buttons, and nesting those inside a button is `nested-interactive` —
 * a merge-blocking axe failure, not a style nit. The title link is the card's single primary target,
 * which is also the pattern `Templates.vue` established for this grid.
 *
 * A11y shape, following `Templates.vue`: the grid is a labelled list, each card an `<li>` whose name is
 * an `<h2>`. That level is not free choice — `PageHeader` owns the `h1` and `MdsEmptyState` renders an
 * `h3`, so `h2` is the only level that keeps `heading-order` clean in both the populated and the empty
 * state (the same reasoning that makes `MdsFilterBar`'s own `<h2>Filters</h2>` non-optional).
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { MdsBadge, MdsCard, statusVariant } from '@meridian/design-system';
import type { FormRow } from '@/types/forms';

const props = defineProps<{ row: FormRow }>();

/**
 * Up to two initials from the title. Decorative — the glyph is `aria-hidden` and the full title sits
 * beside it — so a title of emoji or CJK degrading to one character costs nothing.
 */
const initials = computed(() => {
    const words = props.row.title.trim().split(/\s+/).filter(Boolean);
    if (words.length === 0) return '?';

    return (words.length === 1 ? words[0].slice(0, 2) : words[0][0] + words[1][0]).toUpperCase();
});

/** Capacity is only meaningful for a capped form; an uncapped one was never counted at all. */
const capacity = computed(() => {
    const { max_responses: cap, remaining } = props.row.schedule;
    if (cap === null || remaining === null) return null;

    const used = Math.max(0, cap - remaining);

    return { used, cap, percent: cap === 0 ? 100 : Math.min(100, Math.round((used / cap) * 100)) };
});

/**
 * The one-line schedule note shown when there is no capacity meter to show instead. Deliberately reads
 * the shared `acceptance` label rather than re-deriving from the timestamps: the guest runtime, the
 * encode screen and the form hub all render this same vocabulary, and a fifth derivation is how two
 * surfaces end up disagreeing about whether a form is open.
 */
const scheduleNote = computed(() => {
    const { acceptance, closes_at: closesAt, opens_at: opensAt } = props.row.schedule;

    if (acceptance === 'closed') return 'Closed';
    if (acceptance === 'capacity_reached') return 'Capacity reached';
    if (acceptance === 'opens_soon') return opensAt ? `Opens ${relative(opensAt)}` : 'Not open yet';
    if (closesAt) return `Closes ${relative(closesAt)}`;

    return props.row.status === 'published' ? 'Accepting responses' : 'Not published';
});

const lastResponse = computed(() => {
    const at = props.row.stats.last_response_at;

    return at ? relative(at) : '—';
});

const updated = computed(() => (props.row.updated_at ? `Updated ${relative(props.row.updated_at)}` : ''));

/** "in 3 days" / "2 hours ago", via the platform formatter so it follows the viewer's locale. */
function relative(iso: string): string {
    const then = new Date(iso).getTime();
    if (Number.isNaN(then)) return '—';

    const seconds = Math.round((then - Date.now()) / 1000);
    const units: [Intl.RelativeTimeFormatUnit, number][] = [
        ['year', 31536000], ['month', 2592000], ['day', 86400], ['hour', 3600], ['minute', 60],
    ];
    const formatter = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });

    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size) return formatter.format(Math.round(seconds / size), unit);
    }

    return formatter.format(0, 'minute');
}
</script>

<template>
    <MdsCard class="form-card" :style="{ '--form-card-identity': `var(--mds-form-identity-${row.identity})` }">
        <template #header>
            <div class="form-card__head">
                <!-- Decorative twice over: `aria-hidden` because the title follows immediately, and the
                     hue carries no meaning — the status pill beside it is what encodes state. The field
                     is a 12% mix of the identity hue rather than a solid fill, which is what lets the
                     initials be the hue itself and clear 4.5:1 without constraining the palette to
                     colours dark enough to carry white text. -->
                <span class="form-card__glyph" aria-hidden="true">{{ initials }}</span>
                <h2 class="form-card__title">
                    <Link :href="`/forms/${row.id}`" class="forms__title-link">{{ row.title }}</Link>
                </h2>
                <!-- Copied from the table's `#cell-status`, `dot` included: DSR §3.8's rule is that a pill
                     whose descriptor comes from `statusVariant()` and keeps its label carries the disc —
                     23 of 23. A card is still a scannable status surface. -->
                <MdsBadge v-bind="statusVariant(String(row.status))" dot class="form-card__badge" />
            </div>
            <!--
                ⚠️ THE SINGLE LARGEST FREE WIN IN THIS ROW. `description` has been shipped to this page
                since D3 and rendered by nothing, so today the list makes a user open a form to remember
                what it is for. It is clamped to two lines rather than truncated to one: a description is
                a sentence, and one line of a sentence is often worse than none.
            -->
            <p v-if="row.description" class="form-card__desc">{{ row.description }}</p>
        </template>

        <dl class="form-card__stats">
            <div class="form-card__stat">
                <dt>Responses</dt>
                <dd class="form-card__stat-value form-card__stat-value--accent">{{ row.stats.responses }}</dd>
            </div>
            <div class="form-card__stat">
                <dt>In progress</dt>
                <dd class="form-card__stat-value">{{ row.stats.drafts }}</dd>
            </div>
            <div class="form-card__stat">
                <dt>Last</dt>
                <dd class="form-card__stat-value form-card__stat-value--text">{{ lastResponse }}</dd>
            </div>
        </dl>

        <!-- A capped form shows its meter; everything else shows the schedule line in the same slot, so
             cards keep a common height without one of them carrying an empty band. -->
        <div v-if="capacity" class="form-card__capacity">
            <span class="form-card__capacity-label">
                Capacity
                <b>{{ capacity.used }} / {{ capacity.cap }}</b>
            </span>
            <!-- `progressbar` with the real numbers, so the meter is not a decorative bar to a screen
                 reader; the visible label carries the same figures for everyone else. -->
            <span
                class="form-card__meter"
                role="progressbar"
                :aria-valuenow="capacity.used"
                :aria-valuemin="0"
                :aria-valuemax="capacity.cap"
                :aria-label="`Capacity: ${capacity.used} of ${capacity.cap} responses`"
            >
                <i
                    class="form-card__meter-fill"
                    :class="{ 'form-card__meter-fill--warn': capacity.percent >= 75 }"
                    :style="{ width: `${capacity.percent}%` }"
                />
            </span>
        </div>
        <p v-else class="form-card__schedule">{{ scheduleNote }}</p>

        <template #footer>
            <div class="form-card__foot">
                <span class="form-card__updated">{{ updated }}</span>
                <slot name="actions" />
            </div>
        </template>
    </MdsCard>
</template>

<style scoped>
.form-card {
    position: relative;
    overflow: hidden;
    /* `MdsCard` is `display: block` with no stretch prop, so a grid consumer has to say this — the same
       thing `Templates.vue` says with a `:deep()`. Said here instead, so the grid needs no knowledge of
       the card's internals and every consumer gets equal-height cards for free. */
    display: flex;
    flex-direction: column;
    width: 100%;
    height: 100%;
    /* THE CARD IS ITS OWN QUERY CONTAINER — declared here rather than on the grid cell so the component
       carries its own responsive behaviour and cannot be broken by a page that forgets to establish one
       (DSR §6: "components carry their own responsive behavior; pages never add breakpoint logic"). */
    container-type: inline-size;
}

/* Pins the action row to the bottom edge whatever the description and schedule above it come to, so a
   row of cards has one action line rather than a ragged one. */
.form-card :deep(.mds-card__footer) {
    margin-top: auto;
}

/* The identity edge. A non-text indicator under WCAG 1.4.11, so the token it reads is an `-fg`-strength
   value measured at 3:1 against both the surface AND the canvas in both themes — the J2a finding, which
   Vivid's accent edges are exactly where it recurs. */
.form-card::before {
    content: '';
    position: absolute;
    inset: 0 auto 0 0;
    width: 4px;
    background-color: var(--form-card-identity);
}

.form-card__head {
    display: flex;
    align-items: flex-start;
    gap: var(--mds-space-3);
}

.form-card__glyph {
    display: grid;
    place-items: center;
    flex: none;
    width: 38px;
    height: 38px;
    /* `lg`, the compact-surface tier: DSR §2.6 puts tinted icon fields there, alongside the page-header
       and stat-tile glyphs this sits beside on other pages. The mockup draws 12px, which is the CONTROL
       tier — matching the app's own rule beat matching the mockup's pixel. */
    border-radius: var(--mds-radius-lg);
    background-color: color-mix(in srgb, var(--form-card-identity) 12%, transparent);
    color: var(--form-card-identity);
    font-size: var(--mds-type-caption-font-size);
    font-weight: var(--mds-font-weight-bold);
    letter-spacing: var(--mds-tracking-wide);
}

.form-card__title {
    flex: 1;
    /* Without this a long unbroken title blows the flex track rather than ellipsing — the Domains-page
       overflow lesson `NotificationBell.vue:389` records, which a card grid meets on every row. */
    min-width: 0;
    /* ⚠️ AND `min-width: 0` ALONE IS NOT ENOUGH, which the running app is what proved. It lets the BOX
       shrink; it does nothing about a single WORD wider than the box, and a form title is
       tenant-supplied. Measured at 375px with `data-font-size="extra_large"`: the title track is 102px
       once the glyph and the status pill have taken their share, and "Assessment" alone wants 105px —
       so it overflowed and `overflow: hidden` on the card ate the last three pixels of the word. Same
       family as the Domains page's 64-hex verification token, arriving on a surface where the string is
       ordinary English rather than obviously hostile. */
    overflow-wrap: anywhere;
    margin: 0;
    font-size: var(--mds-type-heading-4-font-size);
    line-height: var(--mds-type-heading-4-line-height);
    font-weight: var(--mds-font-weight-semibold);
}

.form-card__badge {
    flex: none;
}

.form-card__desc {
    margin: var(--mds-space-2) 0 0;
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-text-secondary);
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    /* Same reasoning as the title above, and the description is the likelier carrier: a pasted URL is a
       single unbreakable word of arbitrary length, and the line clamp would hide the overflow ROWS
       without doing anything about the overflow WIDTH. */
    overflow-wrap: anywhere;
}

.form-card__stats {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: var(--mds-space-3);
    margin: 0;
    padding: var(--mds-space-3) 0;
    border-top: 1px solid var(--mds-color-border-default);
    border-bottom: 1px solid var(--mds-color-border-default);
}

/* Keyed off the CARD, not the viewport — the repo's first container query, and the reason is arithmetic:
   the sidebar is 240px above 1024px and 64px at or below it, so the content box at 1025px (721px) is
   NARROWER than at 1024px (896px). A `min-width` rule would switch on its widest layout at the viewport
   with least room. The card's own width is the only thing that actually determines whether three
   columns fit — and the grid reflows 1/2/3/4-up, so the same card is 300px at one viewport and 440px at
   a WIDER one. There is no viewport breakpoint that could express this correctly.

   ⚠️ THE THRESHOLD IS IN `em`, NOT `px`, AND THAT IS THE WHOLE POINT — MEASURED, NOT PREFERRED. A `px`
   threshold is only correct at one font scale. `[data-font-size="extra_large"]` scales type ×1.25 while
   the card width does not move, so the labels grow into a column that did not: measured on the running
   app, `IN PROGRESS` needs 103px at that scale and the column at a 368px card gives 101px, so it
   ellipsed to `IN PROGRE…` on the DESKTOP grid. In `em` the threshold scales with the labels, because a
   container query's `em` resolves against the container's own font size. Three columns need
   `3 × label + 2 × gap`, which measures ≈17em at every one of the three scales; 18em keeps a little
   headroom. Verified by driving all three `data-font-size` values across 330/375/744/834/1600px.

   ⚠️ AND `max-width` HERE MEASURES THE CONTENT BOX, NOT THE BORDER BOX. `container-type: inline-size`
   makes the query resolve against the card's content box, so a card 40px of padding wide reports 40px
   less than its `getBoundingClientRect()`. The first draft used 260px believing it would never fire and
   it fired constantly — on every 300px card, which is the grid's own track floor. Reason about this
   threshold in content-box terms or it will be off by exactly the padding. */
@container (max-width: 18em) {
    .form-card__stats {
        grid-template-columns: minmax(0, 1fr);
        gap: var(--mds-space-2);
    }

    .form-card__stat {
        flex-direction: row;
        align-items: baseline;
        justify-content: space-between;
        gap: var(--mds-space-3);
    }
}

.form-card__stat {
    display: flex;
    flex-direction: column-reverse; /* value above label, with the label first in the DOM for `dl` order */
    gap: 2px;
    min-width: 0;
}

.form-card__stat dt {
    font-size: var(--mds-type-caption-font-size);
    font-weight: var(--mds-font-weight-semibold);
    letter-spacing: var(--mds-tracking-wide);
    text-transform: uppercase;
    color: var(--mds-color-text-secondary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.form-card__stat-value {
    margin: 0;
    font-size: var(--mds-type-heading-3-font-size);
    line-height: var(--mds-type-heading-3-line-height);
    font-weight: var(--mds-font-weight-bold);
    /* JR2's StatTile lesson: a column of figures that is not tabular jitters by a fraction of a digit
       width, and a grid of cards is three columns of figures. */
    font-variant-numeric: tabular-nums;
    color: var(--mds-color-text-heading);
}

.form-card__stat-value--accent {
    color: var(--mds-color-action-primary-fg);
}

/* "2 hours ago" is prose, not a figure — at heading-3 it wraps a card in half. */
.form-card__stat-value--text {
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-heading-3-line-height);
    font-weight: var(--mds-font-weight-medium);
}

.form-card__capacity {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-1);
    margin-top: var(--mds-space-3);
}

.form-card__capacity-label {
    display: flex;
    justify-content: space-between;
    gap: var(--mds-space-2);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.form-card__capacity-label b {
    color: var(--mds-color-text-body);
    font-variant-numeric: tabular-nums;
}

.form-card__meter {
    display: block;
    height: 5px;
    border-radius: var(--mds-radius-full);
    background-color: var(--mds-color-bg-sunken);
    overflow: hidden;
}

.form-card__meter-fill {
    display: block;
    height: 100%;
    border-radius: var(--mds-radius-full);
    background-color: var(--mds-color-action-primary-bg);
}

.form-card__meter-fill--warn {
    background-color: var(--mds-color-status-warning-fg);
}

.form-card__schedule {
    margin: var(--mds-space-3) 0 0;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.form-card__foot {
    display: flex;
    align-items: center;
    gap: var(--mds-space-2);
    flex-wrap: wrap;
}

.form-card__updated {
    margin-right: auto;
    font-size: var(--mds-type-caption-font-size);
    color: var(--mds-color-text-secondary);
}
</style>
