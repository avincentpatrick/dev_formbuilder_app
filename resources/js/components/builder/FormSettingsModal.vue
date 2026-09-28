<script setup lang="ts">
/**
 * The builder's one "Form settings" surface (Increment M117, decision `D63`).
 *
 * ── WHAT THIS REPLACES, AND WHAT IT DELIBERATELY DOES NOT ─────────────────────────────────────────
 * The report behind this was *"the form itself doesn't have a settings section"*. It was a findability
 * complaint, not an architecture one — and the architectural fix it seems to invite is REFUSED IN WRITING,
 * four times over: `UpdateFormScheduleRequest`, `UpdateSaveResumeRequest` and `UpdateConfirmationMessageRequest`
 * each carry a docblock declining to fold their route into `FormMetadataRequest`, and `routes/tenant.php`
 * makes the same argument for scope at route level. So nothing is folded here. Every section below keeps its
 * own route and its own FormRequest; the only thing that changes is where an author finds them.
 *
 * ── WHY IT IS A RADIOGROUP-SHAPED RAIL AND NOT A TABLIST, MEASURED ────────────────────────────────
 * ⛔ `MdsTabs` IS PROHIBITED ON THIS PAGE, AND THE PROHIBITION SURVIVED THE COMPONENT'S ARRIVAL. Thirteen
 * locators across `builder-axe`, `responsive-axe` and `personalization-axe` walk `[role="tab"]` on the
 * builder, FOUR OF THEM LOOPS THAT CLICK EVERY MATCH, and `ConfigPanel.vue` records that this page holds
 * exactly one tablist, permanently. A second one would join all thirteen and get clicked mid-scan.
 *
 * ── WHY THE RAIL IS LOCAL RATHER THAN A DESIGN-SYSTEM COMPONENT ───────────────────────────────────
 * This is the written rationale `CLAUDE.md` § Design asks for when a page does not use a shared component,
 * and each clause was measured rather than assumed:
 *
 *   - `MdsSegmentedControl` is the repo's radiogroup and it CANNOT be used here. It is `inline-flex` with no
 *     wrap and no overflow handling — stated in `personalization-axe.spec.ts` and `search-nav.spec.ts`, and
 *     `search-nav` measures segment right-edges against their container for exactly this reason. Five
 *     segments do not fit a 520px dialog, let alone the full-screen sheet it becomes below 480px, and the
 *     repo has horizontal-overflow assertions that would catch the spill.
 *   - It has no vertical orientation and no `aria-orientation` anywhere in the package.
 *   - `MdsModal` is hard-capped at `max-width: 520px` with no size prop, and NO consumer overrides that
 *     geometry — 41 call sites, zero `:deep` into the panel. So the rail must fit inside 520px.
 *   - Adding an `orientation` prop and a `size` prop would be the tidy answer, and both would oblige a
 *     record in `docs/ux/design-system-reference.md` — a hub file. This increment already spends its one
 *     hub on `Builder.vue`, and the batching rule permits one.
 *
 * ✅ SO IT FOLLOWS THE PRECEDENT THIS PAGE ALREADY SHIPS: `.builder__left-tabs` in `Pages/forms/Builder.vue`
 * is a local `role="group"` of `aria-pressed` buttons for the Fields⇄Library switch, for the same reason —
 * a single-choice switch between already-mounted panels, which is not navigation and must not be a tablist.
 *
 * ── THE WORD "SETTINGS" IS ALREADY TAKEN ON THIS PAGE ─────────────────────────────────────────────
 * ⚠️ The compact pane switcher labels its third pane `Settings`, and `tests/e2e/support/navigate.ts` maps
 * that word to `.builder__pane--config`. `builder-layout.test.ts` separately forbids the switcher's
 * `ariaLabel` from containing `Add`, `Form` or `Settings`, because it renders as a hidden legend inside the
 * element `showBuilderPane()` scopes its `getByText` to. This modal teleports to `body`, so it cannot land
 * in that scope — but every locator aimed at it is still scoped, and no rail label repeats a pane name.
 *
 * ── MOUNTING: ON FIRST VISIT, AND KEPT AFTERWARDS ─────────────────────────────────────────────────
 * A section mounts when first selected and then stays mounted behind `v-show`. Both halves matter:
 *   - not eager, because `SharePanel` renders the QR as an `<img src>` and mounting it would fetch the SVG
 *     for every author who opens settings to change the title;
 *   - not `v-if`-only, because unmounting on a section switch would silently discard what the author typed
 *     in the section they just left.
 * Each panel re-seeds from props when the MODAL opens, not when its section becomes visible, which is what
 * preserves edits across switches while still showing the latest saved values on each open.
 */
import { computed, ref, watch } from 'vue';
import { MdsButton, MdsModal } from '@meridian/design-system';
import ConfirmationPanel from '@/components/builder/ConfirmationPanel.vue';
import GeneralPanel from '@/components/builder/GeneralPanel.vue';
import SaveResumePanel from '@/components/builder/SaveResumePanel.vue';
import SchedulePanel from '@/components/builder/SchedulePanel.vue';
import SharePanel from '@/components/forms/SharePanel.vue';
import type { ShareProps } from '@/components/forms/types';

type SectionKey = 'general' | 'share' | 'schedule' | 'confirmation' | 'save-resume';

const props = defineProps<{
    open: boolean;
    formId: string;
    form: {
        title: string;
        description: string | null;
        confirmation_message: string | null;
        confirmation_message_translations: Record<string, string>;
        default_locale: string;
        supported_locales: string[];
        opens_at: string | null;
        closes_at: string | null;
        timezone: string;
        max_responses: number | null;
        save_and_resume: boolean;
    };
    timezones: string[];
    share: ShareProps | null;
    /** Plan gate for the save-and-resume section — the same `feature()` the route enforces server-side. */
    saveResumeAvailable: boolean;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();

/**
 * ⚠️ THE GATED SECTIONS ARE FILTERED OUT OF THE RAIL, NOT DISABLED IN IT. A rail row that cannot be opened
 * is a dead end that still costs a reading. `share` is absent (not empty) when the server does not offer it
 * — the absent-not-empty convention `Show.vue` uses for the same prop — and save-and-resume follows its
 * plan feature, which the route enforces regardless of what this renders.
 */
const sections = computed<{ key: SectionKey; label: string }[]>(() => {
    const all: { key: SectionKey; label: string; available: boolean }[] = [
        { key: 'general', label: 'Details', available: true },
        { key: 'share', label: 'Share', available: props.share !== null },
        { key: 'schedule', label: 'Schedule', available: true },
        { key: 'confirmation', label: 'Thank-you message', available: true },
        { key: 'save-resume', label: 'Save and finish later', available: props.saveResumeAvailable },
    ];

    return all.filter((s) => s.available).map(({ key, label }) => ({ key, label }));
});

const active = ref<SectionKey>('general');

/** Sections that have been selected at least once, and are therefore mounted. See the header. */
const mounted = ref<Set<SectionKey>>(new Set<SectionKey>(['general']));

function select(key: SectionKey): void {
    active.value = key;
    mounted.value = new Set(mounted.value).add(key);
}

// Reopening lands on the first section rather than wherever the last visit ended. Remembering the section
// would be a per-viewer UI preference, and this app has no mechanism for one on the admin side — which is
// its own backlog row, and not something to invent here.
watch(
    () => props.open,
    (open) => {
        if (!open) return;
        const first = sections.value[0]?.key ?? 'general';
        active.value = first;
        mounted.value = new Set<SectionKey>([first]);
    },
);
</script>

<template>
    <MdsModal
        :open="props.open"
        title="Form settings"
        initial-focus=".form-settings__rail-button"
        @close="emit('update:open', false)"
    >
        <div class="form-settings">
            <!-- The rail. ⛔ Its two forbidden roles, and why, are in this file's header — NAMED THERE AND
                 DELIBERATELY NOT HERE, because Vue renders template comments into the DOM and the gate in
                 `FormSettingsModal.test.ts` is a substring check over `wrapper.html()`. A comment that
                 spelled either role would fail the very assertion it was explaining. -->
            <div class="form-settings__rail" role="group" aria-label="Settings section">
                <button
                    v-for="section in sections"
                    :key="section.key"
                    type="button"
                    class="form-settings__rail-button"
                    :class="{ 'form-settings__rail-button--active': active === section.key }"
                    :aria-pressed="active === section.key"
                    @click="select(section.key)"
                >
                    {{ section.label }}
                </button>
            </div>

            <div class="form-settings__body">
                <!-- ⛔ THE TWO DIRECTIVES SIT ON DIFFERENT ELEMENTS, AND THAT IS NOT STYLE. On one element
                     `v-if` wins and the `v-show` never hides anything — measured: the hidden section
                     reported itself VISIBLE and a switch would have shown two panels at once. `template
                     v-if` does the mounting, the wrapper does the showing. -->
                <template v-if="mounted.has('general')">
                    <div v-show="active === 'general'" class="form-settings__section" :data-section="'general'">
                        <GeneralPanel :open="props.open" :form-id="props.formId" :form="props.form" />
                    </div>
                </template>
                <template v-if="props.share !== null && mounted.has('share')">
                    <div v-show="active === 'share'" class="form-settings__section" :data-section="'share'">
                        <SharePanel
                            :open="props.open"
                            :form-id="props.formId"
                            :form-title="props.form.title"
                            :share="props.share"
                        />
                    </div>
                </template>
                <template v-if="mounted.has('schedule')">
                    <div v-show="active === 'schedule'" class="form-settings__section" :data-section="'schedule'">
                        <SchedulePanel
                            :open="props.open"
                            :form-id="props.formId"
                            :form="props.form"
                            :timezones="props.timezones"
                        />
                    </div>
                </template>
                <template v-if="mounted.has('confirmation')">
                    <div v-show="active === 'confirmation'" class="form-settings__section" :data-section="'confirmation'">
                        <ConfirmationPanel :open="props.open" :form-id="props.formId" :form="props.form" />
                    </div>
                </template>
                <template v-if="props.saveResumeAvailable && mounted.has('save-resume')">
                    <div v-show="active === 'save-resume'" class="form-settings__section" :data-section="'save-resume'">
                        <SaveResumePanel
                            :open="props.open"
                            :form-id="props.formId"
                            :enabled="props.form.save_and_resume"
                        />
                    </div>
                </template>
            </div>
        </div>

        <template #actions>
            <MdsButton variant="tertiary" @click="emit('update:open', false)">Close</MdsButton>
        </template>
    </MdsModal>
</template>

<style scoped>
/* Rail + body inside the shared modal's 520px shell. ✅ The ~340px the body gets is a width the heaviest
   section is already proved at: below 480px `MdsModal` becomes a full-screen sheet, so SharePanel renders
   at roughly that width on a phone today. */
.form-settings {
    display: grid;
    grid-template-columns: 140px minmax(0, 1fr);
    gap: var(--mds-space-5);
    container-type: inline-size;
}

.form-settings__rail {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-1);
    border-right: 1px solid var(--mds-color-border-default);
    padding-right: var(--mds-space-3);
}

.form-settings__rail-button {
    padding: var(--mds-space-2) var(--mds-space-3);
    border: none;
    border-radius: var(--mds-radius-sm);
    background: none;
    color: var(--mds-color-text-secondary);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-label-font-size);
    font-weight: var(--mds-font-weight-medium);
    text-align: left;
    cursor: pointer;
}

.form-settings__rail-button:hover {
    background-color: var(--mds-color-bg-sunken);
    color: var(--mds-color-text-body);
}

.form-settings__rail-button:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 1px;
}

/* Selected = a solid filled chip, matching MdsSegmentedControl's verified action-primary + on-primary
   pairing so contrast holds in both themes; the fill and the weight are the non-colour signifiers. */
.form-settings__rail-button--active {
    background-color: var(--mds-color-action-primary-bg);
    color: var(--mds-color-text-on-primary);
    font-weight: var(--mds-font-weight-semibold);
}

.form-settings__body {
    min-width: 0;
}

/* The wrapper exists only to carry `v-show` (see the template note); it must add no geometry of its own. */
.form-settings__section {
    min-width: 0;
}

/* Below the sheet threshold the rail stops being a rail: 140px of it beside a ~215px body is neither. It
   becomes a wrapping row above the content, which is the one place a horizontal set of these is safe —
   `flex-wrap` is precisely what MdsSegmentedControl does not have. */
@container (max-width: 420px) {
    .form-settings {
        grid-template-columns: minmax(0, 1fr);
        gap: var(--mds-space-4);
    }

    .form-settings__rail {
        flex-direction: row;
        flex-wrap: wrap;
        border-right: none;
        border-bottom: 1px solid var(--mds-color-border-default);
        padding-right: 0;
        padding-bottom: var(--mds-space-3);
    }
}
</style>
