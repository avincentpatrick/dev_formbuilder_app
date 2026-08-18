<script setup lang="ts">
/**
 * The achievements surface (Increment K1e, gamification-design.md §10, ADR-0020 §D7).
 *
 * ⚠️ FOR A FREE TENANT THIS PAGE IS THE ENTIRE FEATURE. ADR-0020 §D11(e) measured it: the whole `/api/v1`
 * group sits behind `feature:api_access`, which Free does not carry, while §D6 grants `gamification` on
 * every tier — so K1d's two endpoints are unreachable for exactly the audience §D6 refused to put a tier
 * ladder in front of. That raises the bar on the empty state rather than lowering it, which is why the
 * "nothing yet" case names the acts that earn points instead of apologising.
 *
 * ── ⚠️ THE THREE NUMBERS THAT DELIBERATELY DO NOT RECONCILE, AND WHY THEY ARE IN COPY ──────────────────
 * ADR-0020 §D11(c) tabulates them: `team.points` and `team.contributors` count members who have since LEFT
 * (the award ledger is append-only, so their history outlives their membership), and `team.responses`
 * counts GUEST submissions, which credit nobody — crediting a form's owner would make the ladder a contest
 * between public links rather than between people (§D8). This page puts the workspace totals directly
 * beside the ladder they do not add up to, so it is the one surface where a reader can SEE the gap. Every
 * note below is therefore rendered text, never a `title` or a tooltip: the reader who needs it is the one
 * who has just noticed the numbers disagree, and they will not hover to find out why.
 *
 * ── WHAT THIS FILE DOES NOT DECIDE ────────────────────────────────────────────────────────────────────
 * Nothing here re-derives a number, and nothing here decides who may see what. `scoreboard` arrives NULL
 * for a reader without `dashboard.org.view` and the page reads that null — the `kpis.members` contract on
 * the dashboard. Badge wording comes from `BadgeKey::label()`/`description()` on the server, because that
 * enum's docblock asks for exactly one source: the shelf, the notification row and its email all name the
 * same badge, and a second consumer inventing its own copy is how two screens come to disagree about what
 * somebody earned.
 */
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import { MdsCard, MdsEmptyState, MdsIcon, MdsProgress, MdsStatTile } from '@meridian/design-system';
import PageHeader from '@/components/shell/PageHeader.vue';

interface BadgeRow {
    key: string;
    label: string;
    description: string;
    earned_on: string | null;
    /** Unclamped: 40 responses against Collector's 25 is a fact about the member, not about the badge. */
    progress: number;
    threshold: number;
}

interface LadderRow {
    rank: number;
    user_id: string;
    name: string;
    points: number;
    badges: number;
}

const props = defineProps<{
    progress: {
        points: number;
        badges: number;
        /** `rank` is null when this reader holds no active membership here — never 0, which reads as a place. */
        standing: { rank: number | null; of: number };
        /** `current` decays after a full missed day; `longest` only ever rises. Different measurements. */
        streak: { current: number; longest: number; last_active_on: string | null };
    };
    shelf: { earned: BadgeRow[]; in_progress: BadgeRow[] };
    /** Null for a reader without `dashboard.org.view`. The page reads the null; it never re-derives it. */
    scoreboard: {
        entries: LadderRow[];
        member_count: number;
        team: {
            points: number;
            responses: number;
            published_forms: number;
            active_members: number;
            badges: number;
            contributors: number;
        };
    } | null;
}>();

const number = (value: number): string => value.toLocaleString();

/**
 * "4th of 12", or null when there is no position to state.
 *
 * ⚠️ ORDINALISED IN FULL RATHER THAN WITH `value + 'th'`, because 1st/2nd/3rd and the 11–13 exception are
 * exactly the cases a naive suffix gets wrong — and on a ladder, ranks 1–3 are the ones most likely to be
 * read. `of` is the ACTIVE MEMBER COUNT, never the number of people who have scored: a member who has
 * earned nothing is twelfth of twelve rather than absent, and a denominator that grew as colleagues
 * started earning would make somebody's own position appear to move on a day they did nothing.
 */
const standingLabel = computed(() => {
    const { rank, of } = props.progress.standing;

    if (rank === null) return null;

    return `${ordinal(rank)} of ${number(of)}`;
});

function ordinal(value: number): string {
    const lastTwo = value % 100;

    if (lastTwo >= 11 && lastTwo <= 13) return `${value}th`;

    return `${value}${['th', 'st', 'nd', 'rd'][value % 10] ?? 'th'}`;
}

/**
 * A day count, pluralised.
 *
 * "1 days" is the kind of thing a reader forgives and a reviewer does not, and both numbers here are
 * routinely 1 — a streak's first day, and a `longest` of 1 for anybody who has never returned twice.
 */
function days(value: number): string {
    return `${number(value)} ${value === 1 ? 'day' : 'days'}`;
}

/** `2026-08-18T…` → `18 Aug 2026`. The badge shelf's only date, and it is a date rather than a moment. */
function earnedDate(iso: string): string {
    return new Date(iso).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
}

</script>

<template>
    <div class="ach">
        <Head title="Achievements" />

        <PageHeader title="Achievements" icon="award" />

        <!-- ── Your progress — ungated, every member, no permission at all (§D7) ───────────────────── -->
        <section class="ach__section" aria-labelledby="ach-you-heading">
            <h2 id="ach-you-heading" class="ach__section-title">Your progress</h2>

            <div class="ach__stats">
                <MdsStatTile label="Points" icon="award" :value="number(progress.points)" />
                <MdsStatTile label="Badges earned" icon="check" :value="number(progress.badges)" />
                <!--
                    ⚠️ TWO TILES, NOT ONE. MemberStreak's docblock is explicit that `current` and `longest`
                    answer different questions: current is a live fact that decays to zero the day after a
                    gap, longest is a high-water mark that can only rise. A surface showing one and
                    labelling it the other tells a member they have lost an achievement they still hold.
                -->
                <MdsStatTile
                    label="Current streak"
                    icon="activity"
                    :value="days(progress.streak.current)"
                    caption="Counts the days you earned something. It survives today until midnight, and resets after a whole day with nothing in it."
                />
                <MdsStatTile
                    label="Longest streak"
                    icon="trend-up"
                    :value="days(progress.streak.longest)"
                    caption="Your best run so far. This one never goes down."
                />
                <MdsStatTile
                    v-if="standingLabel"
                    label="Your place"
                    icon="users"
                    :value="standingLabel"
                    caption="Counts everyone on the team, including teammates who have not earned anything yet."
                />
            </div>
        </section>

        <!-- ── Your badges ─────────────────────────────────────────────────────────────────────────── -->
        <section class="ach__section" aria-labelledby="ach-badges-heading">
            <h2 id="ach-badges-heading" class="ach__section-title">Your badges</h2>

            <!--
                ⚠️⚠️ THE EMPTY STATE BELONGS TO THE *EARNED* HALF, NOT TO THE SECTION, AND THE FIRST DRAFT OF
                THIS FILE GOT IT WRONG IN A WAY NO TEST WOULD HAVE CAUGHT. It guarded on "both halves are
                empty" — which `BadgeShelf::assemble()` makes UNREACHABLE here: it walks the whole
                `BadgeKey` catalog, so every one of the ten badges lands in `earned` or `in_progress` and
                the two lists always sum to ten. The only shelf with both empty is `BadgeShelf::none()`,
                returned off-tenant, and this route sits behind tenant-context middleware. So the empty
                state was dead code carrying copy for a state it could not reach, while the state that
                actually matters — a member who holds nothing yet — fell through to a bare "In progress"
                heading with no explanation above it.

                What a member with nothing sees is now this card, and note it is genuinely reachable rather
                than merely defensive: `BadgeKey::Welcome` lands on every member the day they join, so an
                empty `earned` list means this member predates the engine and K1c's backfill has not run
                for this tenant. The copy therefore does not scold them for having earned nothing.
            -->
            <template v-if="shelf.earned.length > 0">
                <h3 class="ach__subheading">Earned</h3>
                <ul class="ach__badges" role="list">
                    <li v-for="badge in shelf.earned" :key="badge.key" class="ach__badge">
                        <span class="ach__badge-mark ach__badge-mark--earned" aria-hidden="true">
                            <MdsIcon name="award" size="md" />
                        </span>
                        <div class="ach__badge-body">
                            <p class="ach__badge-label">{{ badge.label }}</p>
                            <p class="ach__badge-desc">{{ badge.description }}</p>
                            <!--
                                A <time> rather than a bare string: the date is the ONE fact a badge row
                                holds that nothing else in the schema can reproduce (ADR-0020 §D9 — a
                                derived checklist can say a step IS done, never WHEN it became done).
                            -->
                            <p v-if="badge.earned_on" class="ach__badge-meta">
                                Earned <time :datetime="badge.earned_on">{{ earnedDate(badge.earned_on) }}</time>
                            </p>
                        </div>
                    </li>
                </ul>
            </template>

            <MdsCard v-else class="ach__empty">
                <MdsEmptyState
                    headline="No badges yet"
                    description="Badges arrive on their own as you work — creating a form, publishing it, collecting a response, reviewing one, or inviting a teammate. There is nothing to switch on and nothing to claim."
                />
            </MdsCard>

            <template v-if="shelf.in_progress.length > 0">
                <h3 class="ach__subheading">In progress</h3>
                <ul class="ach__badges" role="list">
                    <li v-for="badge in shelf.in_progress" :key="badge.key" class="ach__badge">
                        <span class="ach__badge-mark" aria-hidden="true">
                            <MdsIcon name="award" size="md" />
                        </span>
                        <div class="ach__badge-body">
                            <p class="ach__badge-label">{{ badge.label }}</p>
                            <p class="ach__badge-desc">{{ badge.description }}</p>
                            <!--
                                ⚠️ "N of M", NEVER A PERCENTAGE — the rule J5 established for MdsProgress
                                and which TeamProgress's own docblock names K1e as the consumer of. A
                                percentage would round 24 of 25 to 96% and 25 of 25 to 100%, which reads
                                as "nearly there" for a member who is one response away and cannot say
                                how many. `value` is clamped by the component, so an over-threshold
                                progress (a raised threshold, §D9) fills the bar rather than overflowing.
                            -->
                            <MdsProgress
                                class="ach__badge-meter"
                                :label="badge.label"
                                :value="badge.progress"
                                :max="badge.threshold"
                                :value-text="`${number(badge.progress)} of ${number(badge.threshold)}`"
                            />
                        </div>
                    </li>
                </ul>
            </template>
        </section>

        <!-- ── The workspace — `dashboard.org.view` only. Null prop ⇒ this whole section is absent ──── -->
        <section v-if="scoreboard" class="ach__section" aria-labelledby="ach-team-heading">
            <h2 id="ach-team-heading" class="ach__section-title">This workspace</h2>

            <div class="ach__stats">
                <MdsStatTile label="Team points" icon="award" :value="number(scoreboard.team.points)" />
                <MdsStatTile label="Responses collected" icon="submissions" :value="number(scoreboard.team.responses)" />
                <MdsStatTile label="Live forms" icon="forms" :value="number(scoreboard.team.published_forms)" />
                <MdsStatTile label="Members" icon="users" :value="number(scoreboard.team.active_members)" />
                <!-- NOT "Badges earned": that label is already spent by this member's own tile further up,
                     and two tiles with one name on one page is an ambiguous accessible name (WCAG 2.4.6) as
                     well as a puzzle for anybody comparing the two numbers. -->
                <MdsStatTile label="Badges across the team" icon="check" :value="number(scoreboard.team.badges)" />
                <MdsStatTile label="People scoring" icon="user-plus" :value="number(scoreboard.team.contributors)" />
            </div>

            <!--
                ⚠️⚠️ THE ONE PARAGRAPH THIS PAGE CANNOT DO WITHOUT, AND IT IS RENDERED TEXT ON PURPOSE.
                ADR-0020 §D11(c): these totals are LARGER than the ladder below adds up to, in three
                separate ways, and all three are decisions rather than defects. A reader who spots the gap
                and finds no explanation concludes the feature is broken — so the explanation goes where
                they are already looking, not behind a hover nobody performs. Stated as what each number
                COUNTS rather than as an apology, because neither figure is wrong.
            -->
            <p class="ach__note">
                These count the whole workspace, so they are larger than the leaderboard adds up to.
                <strong>Responses</strong> includes answers collected through public links, which credit
                nobody — otherwise the leaderboard would be a contest between links rather than between
                people. <strong>Team points</strong> and <strong>people scoring</strong> also include
                teammates who have since left: what they earned stays on the record, while the leaderboard
                names people who are here now.
            </p>

            <template v-if="scoreboard.entries.length > 0">
                <h3 class="ach__subheading">Leaderboard</h3>
                <!--
                    Not `MdsDataTable`: two figures per row, no sorting, no pagination, no row actions.

                    ⚠️⚠️ AND DELIBERATELY A `<ul>` RATHER THAN AN `<ol>`, WHICH IS THE OPPOSITE OF THE
                    OBVIOUS CHOICE FOR A LEADERBOARD. An ordered list's semantics are POSITIONAL — assistive
                    technology announces "item 3 of 12" from DOM order — and this ladder is COMPETITION
                    ranked, so a tie for 2nd is followed by 4th (ADR-0020 §D11(b)). On an `<ol>` the third
                    row would be announced as third while reading "4th", and the two claims would contradict
                    each other in exactly the case the ranking rule exists for. The `value` attribute does
                    not rescue it: with `list-style: none` no marker is painted, and AT still counts
                    position rather than reading `value`. So the rank travels as TEXT, once, where the
                    rendered and announced answers cannot diverge.

                    `role="list"` because `list-style: none` strips list semantics in Safari/VoiceOver —
                    the note MdsBreadcrumb, MdsTabNav and NotificationBell all carry.
                -->
                <ul class="ach__ladder" role="list">
                    <li v-for="entry in scoreboard.entries" :key="entry.user_id" class="ach__ladder-row">
                        <span class="ach__ladder-rank">{{ ordinal(entry.rank) }}</span>
                        <span class="ach__ladder-name">{{ entry.name }}</span>
                        <!-- Both figures name their own unit. An award glyph beside a bare "3" is an
                             unlabelled number to anybody not looking at it, and there is no shared
                             `.sr-only` in this repository to caption it with — every component that needs
                             one rolls the clip-rect pattern AND the `position: relative` it requires, which
                             is a containment defect this repo has paid for four times. A word is cheaper
                             and cannot be got wrong. -->
                        <span class="ach__ladder-points">{{ number(entry.points) }} pts</span>
                        <span class="ach__ladder-badges">{{ number(entry.badges) }} badges</span>
                    </li>
                </ul>
                <p class="ach__note ach__note--tight">
                    Everyone on the team appears, including teammates who have not earned anything yet.
                    Tied places share a number, so the next place skips one.
                </p>
            </template>
        </section>
    </div>
</template>

<style scoped>
.ach {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-6);
}

.ach__section {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
}

.ach__section-title {
    margin: 0;
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-3-font-size);
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-heading);
}

.ach__subheading {
    margin: var(--mds-space-2) 0 0;
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-4-font-size);
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-heading);
}

/* `auto-fit` + `minmax`, so the tiles reflow rather than the page scrolling sideways — the responsive
   contract in DSR §6 is a shared-system guarantee, not a per-page media query. */
.ach__stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: var(--mds-space-3);
}

.ach__note {
    max-width: 68ch;
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-text-secondary);
}

.ach__note--tight {
    margin-top: calc(var(--mds-space-1) * -1);
}

.ach__badges {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: var(--mds-space-3);
    padding: 0;
    margin: 0;
    list-style: none;
}

.ach__badge {
    display: flex;
    gap: var(--mds-space-3);
    padding: var(--mds-space-4);
    background-color: var(--mds-color-bg-surface);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-lg);
}

.ach__badge-mark {
    display: inline-flex;
    flex-shrink: 0;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    border-radius: var(--mds-radius-full);
    background-color: var(--mds-neutral-100);
    /* Muted for an unearned badge — but the state is NEVER colour alone (DSR §4.1): the earned row is the
       only one carrying a date, and the unearned row is the only one carrying a meter. */
    color: var(--mds-color-text-secondary);
}

.ach__badge-mark--earned {
    background-color: var(--mds-color-action-primary-bg);
    color: var(--mds-color-text-on-primary);
}

.ach__badge-body {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-1);
    min-width: 0;
}

.ach__badge-label {
    margin: 0;
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-heading);
}

.ach__badge-desc,
.ach__badge-meta {
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.ach__badge-meter {
    margin-top: var(--mds-space-1);
}

.ach__ladder {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-1);
    padding: 0;
    margin: 0;
    list-style: none;
}

.ach__ladder-row {
    display: flex;
    align-items: center;
    gap: var(--mds-space-3);
    padding: var(--mds-space-3) var(--mds-space-4);
    background-color: var(--mds-color-bg-surface);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
}

.ach__ladder-rank {
    min-width: 3.5ch;
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-secondary);
    /* The ranks are read as a column; proportional digits fringe the edge they are aligned on. */
    font-variant-numeric: tabular-nums;
}

.ach__ladder-name {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: var(--mds-color-text-body);
}

.ach__ladder-points,
.ach__ladder-badges {
    font-variant-numeric: tabular-nums;
    color: var(--mds-color-text-secondary);
}

.ach__ladder-badges {
    white-space: nowrap;
}
</style>
