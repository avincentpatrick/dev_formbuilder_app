<script setup lang="ts">
/**
 * A form's settings, as one rail of sections (M117, decision `D63`; extracted from `FormSettingsModal` in M129
 * so the form hub's Settings tab mounts the SAME sections the builder's modal does — `D63` answered "both entry
 * points", and one component is what keeps them from drifting).
 *
 * ── WHAT THIS REPLACES, AND WHAT IT DELIBERATELY DOES NOT ─────────────────────────────────────────
 * The report behind this was *"the form itself doesn't have a settings section"*. It was a findability
 * complaint, not an architecture one — and the architectural fix it seems to invite is REFUSED IN WRITING,
 * five times over: `UpdateFormScheduleRequest`, `UpdateSaveResumeRequest`, `UpdateConfirmationMessageRequest`
 * and `UpdatePageModeRequest` each carry a docblock declining to fold their route into `FormMetadataRequest`,
 * and `routes/tenant.php` makes the same argument for scope at route level. So nothing is folded here. Every
 * section keeps its own route and its own FormRequest; the only thing that changes is where an author finds
 * them. M129's Scanning section follows the same rule (`UpdateOcrScanningRequest`).
 *
 * ── WHY IT IS A RADIOGROUP-SHAPED RAIL AND NOT A TABLIST, MEASURED ────────────────────────────────
 * ⛔ `MdsTabs` IS PROHIBITED ON THE BUILDER, AND THE PROHIBITION SURVIVED THE COMPONENT'S ARRIVAL. Thirteen
 * locators across `builder-axe`, `responsive-axe` and `personalization-axe` walk `[role="tab"]` on the
 * builder, FOUR OF THEM LOOPS THAT CLICK EVERY MATCH, and `ConfigPanel.vue` records that the builder holds
 * exactly one tablist, permanently. A second one would join all thirteen and get clicked mid-scan. On the hub
 * the page already carries `MdsTabNav`, a navigation, and a rail that switched panels must not read as one.
 *
 * ── WHY THE RAIL IS LOCAL RATHER THAN A DESIGN-SYSTEM COMPONENT ───────────────────────────────────
 * This is the written rationale `CLAUDE.md` § Design asks for when a page does not use a shared component,
 * and each clause was measured rather than assumed:
 *
 *   - `MdsSegmentedControl` is the repo's radiogroup and it CANNOT be used here. It is `inline-flex` with no
 *     wrap and no overflow handling — stated in `personalization-axe.spec.ts` and `search-nav.spec.ts`, and
 *     `search-nav` measures segment right-edges against their container for exactly this reason. Six to eight
 *     segments do not fit a 520px dialog, let alone the full-screen sheet it becomes below 480px, and the
 *     repo has horizontal-overflow assertions that would catch the spill.
 *     ⚠️ SCOPED TO THIS RAIL. {@link PageModePanel} uses the same control for a TWO-option HORIZONTAL choice
 *     in the panel body, which is the shape it is for — carrying `D28`'s `flex-wrap` host guard, as every
 *     other host of it does. The refusal here stands; it is not a ban on the component.
 *   - It has no vertical orientation and no `aria-orientation` anywhere in the package.
 *   - `MdsModal` was capped at 520px; since M137 (`R-581cb07b`) the builder opens it at `size="lg"` (880px), and
 *     the rail must still fit the full-screen sheet below 480px. On the hub it sits in the page.
 *
 * ✅ SO IT FOLLOWS THE PRECEDENT THE BUILDER ALREADY SHIPS: `.builder__left-tabs` in `Pages/forms/Builder.vue`
 * is a local `role="group"` of `aria-pressed` buttons for the Fields⇄Library switch, for the same reason —
 * a single-choice switch between already-mounted panels, which is not navigation and must not be a tablist.
 *
 * ── THE WORD "SETTINGS" IS ALREADY TAKEN ON THE BUILDER ───────────────────────────────────────────
 * ⚠️ The compact pane switcher labels its third pane `Settings`, and `tests/e2e/support/navigate.ts` maps
 * that word to `.builder__pane--config`. `builder-layout.test.ts` separately forbids the switcher's
 * `ariaLabel` from containing `Add`, `Form` or `Settings`. The modal teleports to `body`, so it cannot land in
 * that scope — but every locator aimed at it is still scoped, and no rail label repeats a pane name. M129's
 * two new labels, `Scanning` and `Scope`, keep that rule.
 *
 * ── MOUNTING: ON FIRST VISIT, AND KEPT AFTERWARDS ─────────────────────────────────────────────────
 * A section mounts when first selected and then stays mounted behind `v-show`. Both halves matter:
 *   - not eager, because `SharePanel` renders the QR as an `<img src>` and mounting it would fetch the SVG
 *     for every author who opens settings to change the title;
 *   - not `v-if`-only, because unmounting on a section switch would silently discard what the author typed
 *     in the section they just left.
 * Each panel re-seeds from props when `open` turns true — the modal opening — not when its section becomes
 * visible, which preserves edits across switches. On the hub page `open` is simply always true.
 *
 * ── A SECTION IS ABSENT WHEN ITS PROP IS NULL **OR** UNDEFINED (`!= null`) ─────────────────────────
 * The hub sends `share` absent rather than null when it is not offered, and a strict `!== null` reads an absent
 * prop as present and mounts `SharePanel` with nothing to show. Every optional section here is tested loosely.
 */
import { computed, ref, watch } from 'vue';
import ConfirmationPanel from '@/components/builder/ConfirmationPanel.vue';
import GeneralPanel from '@/components/builder/GeneralPanel.vue';
import PageModePanel from '@/components/builder/PageModePanel.vue';
import AutomationsPanel from '@/components/forms/AutomationsPanel.vue';
import ChoiceListsPanel from '@/components/forms/ChoiceListsPanel.vue';
import DataSharingPanel from '@/components/forms/DataSharingPanel.vue';
import ReferenceFilesPanel from '@/components/forms/ReferenceFilesPanel.vue';
import SaveResumePanel from '@/components/builder/SaveResumePanel.vue';
import SchedulePanel from '@/components/builder/SchedulePanel.vue';
import ThemePanel from '@/components/builder/ThemePanel.vue';
import ScanningPanel from '@/components/forms/ScanningPanel.vue';
import ScopePanel from '@/components/forms/ScopePanel.vue';
import SharePanel from '@/components/forms/SharePanel.vue';
import type { AutomationsProps, DataSharingProps, FormSettingsForm, OcrScanningProps, ReferenceFileRow, ScopeSectionProps, ShareProps } from '@/components/forms/types';

type SectionKey = 'general' | 'pages' | 'theme' | 'files' | 'choice-lists' | 'share' | 'scanning' | 'schedule' | 'confirmation' | 'save-resume' | 'automations' | 'sharing' | 'scope';

const props = defineProps<{
    /** Whether the settings are open: the modal's state, or always true on the hub page. */
    open: boolean;
    formId: string;
    form: FormSettingsForm;
    timezones: string[];
    share?: ShareProps | null;
    /** Plan gate for the save-and-resume section — the same `feature()` the route enforces server-side. */
    saveResumeAvailable: boolean;
    /** The Scanning section (M129), or null/absent where the workspace cannot scan — decided by the server. */
    ocrScanning?: OcrScanningProps | null;
    /** The Reference files section (M132): the draft's files, or absent where the host sends none. */
    referenceFiles?: ReferenceFileRow[] | null;
    /** The Automations section (M132): the form's automations, or absent where the host sends none. */
    automations?: AutomationsProps | null;
    /** The Data sharing section (M133): absent for a reader who cannot read this form's responses (`D87`). */
    dataSharing?: DataSharingProps | null;
    /** The hub-only Scope section (M129), sent only to a holder of `scopes.manage`. */
    scope?: ScopeSectionProps | null;
}>();

/**
 * ⚠️ THE GATED SECTIONS ARE FILTERED OUT OF THE RAIL, NOT DISABLED IN IT. A rail row that cannot be opened
 * is a dead end that still costs a reading. The order puts Scanning beside Share — the other switch that
 * decides how responses come in — and Scope last, as the one section that is not about the form's content.
 */
const sections = computed<{ key: SectionKey; label: string }[]>(() => {
    const all: { key: SectionKey; label: string; available: boolean }[] = [
        { key: 'general', label: 'Details', available: true },
        { key: 'pages', label: 'Pages', available: true },
        // M131 (`D81`): every plan. "Theme", never "Appearance" — that word names the member's OWN section in
        // /settings, and this one is about what respondents see.
        { key: 'theme', label: 'Theme', available: true },
        // M132 (`R-bf49e4c1`): what respondents can open while they answer. Beside Theme, the other section about
        // what a respondent sees; never just "Files", which reads as a file-upload question.
        { key: 'files', label: 'Reference files', available: props.referenceFiles != null },
        // M141 (`R-f69aab42`, `D95`): the CSV files a cascading question takes its choices from. Offered wherever the
        // reference files are — the same editors of the same draft.
        { key: 'choice-lists', label: 'Choice lists', available: props.referenceFiles != null },
        { key: 'share', label: 'Share', available: props.share != null },
        { key: 'scanning', label: 'Scanning', available: props.ocrScanning != null },
        { key: 'schedule', label: 'Schedule', available: true },
        { key: 'confirmation', label: 'Thank-you message', available: true },
        { key: 'save-resume', label: 'Save and finish later', available: props.saveResumeAvailable },
        // M132 (`R-b7bc5149`): what happens after a response arrives, so it sits after the sections about collecting one.
        { key: 'automations', label: 'Automations', available: props.automations != null },
        // M133 (`R-5da4a30f`): whether other forms may take their choices from this form's answers — about where answers
        // go after they arrive, so it sits beside Automations. "Data sharing", never "Share": that section is the public link.
        { key: 'sharing', label: 'Data sharing', available: props.dataSharing != null },
        { key: 'scope', label: 'Scope', available: props.scope != null },
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
    <div class="form-settings-host">
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
                <template v-if="mounted.has('pages')">
                    <div v-show="active === 'pages'" class="form-settings__section" :data-section="'pages'">
                        <PageModePanel
                            :open="props.open"
                            :form-id="props.formId"
                            :single-page-mode="props.form.single_page_mode"
                        />
                    </div>
                </template>
                <template v-if="mounted.has('theme')">
                    <div v-show="active === 'theme'" class="form-settings__section" :data-section="'theme'">
                        <ThemePanel
                            :open="props.open"
                            :form-id="props.formId"
                            :preset="props.form.theme_preset"
                            :presets="props.form.theme_presets"
                        />
                    </div>
                </template>
                <template v-if="props.referenceFiles != null && mounted.has('files')">
                    <div v-show="active === 'files'" class="form-settings__section" :data-section="'files'">
                        <ReferenceFilesPanel :open="props.open" :form-id="props.formId" :files="props.referenceFiles" />
                    </div>
                </template>
                <template v-if="props.referenceFiles != null && mounted.has('choice-lists')">
                    <div v-show="active === 'choice-lists'" class="form-settings__section" :data-section="'choice-lists'">
                        <ChoiceListsPanel :open="props.open" :form-id="props.formId" />
                    </div>
                </template>
                <template v-if="props.share != null && mounted.has('share')">
                    <div v-show="active === 'share'" class="form-settings__section" :data-section="'share'">
                        <SharePanel
                            :open="props.open"
                            :form-id="props.formId"
                            :form-title="props.form.title"
                            :share="props.share"
                        />
                    </div>
                </template>
                <template v-if="props.ocrScanning != null && mounted.has('scanning')">
                    <div v-show="active === 'scanning'" class="form-settings__section" :data-section="'scanning'">
                        <ScanningPanel :open="props.open" :form-id="props.formId" :scanning="props.ocrScanning" />
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
                <template v-if="props.automations != null && mounted.has('automations')">
                    <div v-show="active === 'automations'" class="form-settings__section" :data-section="'automations'">
                        <AutomationsPanel :open="props.open" :form-id="props.formId" :automations="props.automations" />
                    </div>
                </template>
                <template v-if="props.dataSharing != null && mounted.has('sharing')">
                    <div v-show="active === 'sharing'" class="form-settings__section" :data-section="'sharing'">
                        <DataSharingPanel :open="props.open" :form-id="props.formId" :sharing="props.dataSharing" />
                    </div>
                </template>
                <template v-if="props.scope != null && mounted.has('scope')">
                    <div v-show="active === 'scope'" class="form-settings__section" :data-section="'scope'">
                        <ScopePanel
                            :open="props.open"
                            :form-id="props.formId"
                            :current-node-id="props.scope.current_node_id"
                            :scopes="props.scope.options"
                        />
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* Rail + body. In the modal's 880px shell (M137) the body gets about twice its old width; the narrowest is the full-screen
   sheet below 480px, where SharePanel already renders at about 340px on a phone. On the hub the same grid sits
   in the page. */
.form-settings {
    display: grid;
    grid-template-columns: 140px minmax(0, 1fr);
    gap: var(--mds-space-5);
}

/* ⛔ THE CONTAINER IS THE HOST, NOT THE GRID. An element cannot match its own container query, so while
   `.form-settings` declared `container-type` and the query below restyled `.form-settings`, the query never
   applied and the rail stayed beside the body at every width. Measured in M129: on the hub at 375px the body
   was ~183px and the Schedule section's date inputs overflowed it by 63px; in the modal the same defect was
   latent, because no spec had scanned a section wider than Share at that width. */
.form-settings-host {
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
