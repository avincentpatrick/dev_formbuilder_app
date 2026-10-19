<script setup lang="ts">
/**
 * "Send Feedback" — the shell's one global non-nav entry point (Feature #11). The trigger opens the
 * shared Modal; submitting posts to the tenant feedback endpoint (route + browser info + remarks, plus an
 * optional screenshot). On success the modal closes and the controller's flash surfaces a Toast.
 *
 * ── THE SCREENSHOT IS OPTIONAL IN EVERY DIRECTION (I7a / ADR-0015) ───────────────────────────────────────
 * Capture is the native Screen Capture API, so it costs a permission prompt and does not exist at all on
 * some browsers. Three arms, and all three must end with a sendable report:
 *   · supported + accepted → the PNG is attached;
 *   · supported + declined/dismissed → nothing happens, no error, no nag;
 *   · unsupported → the capture button is not rendered.
 * The file input is present in ALL THREE cases, so "attach an image you already have" is never the
 * fallback nobody can find. Feature #11's acceptance criterion is explicit that submitting feedback must
 * never block or interrupt the task the user was in the middle of.
 *
 * ── THE PANEL STEPS ASIDE FOR ITS OWN SCREENSHOT (M175, `R-2ce2f274`, ADR-0015 §D11) ─────────────────────
 * The user (2026-10-14): *"when i click capture screen, the modal for the feedback is always included"*.
 * Capture now acquires the stream inside the click, closes the panel, waits for the modal's `after-leave`
 * (or {@see STEP_ASIDE_MAX_MS}, for a modal that never says), takes the frame, and reopens the panel by
 * setting `open` directly — never through {@see openPanel}, which resets the form and would throw the
 * report away. The report also says what KIND it is and the full address and title of the page.
 */
import { computed, onBeforeUnmount, ref } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { MdsButton, MdsFormField, MdsIcon, MdsModal, MdsRadio } from '@meridian/design-system';
import { APP_VERSION } from '../../../public-runtime/lib/app-version';
import { acquireScreen, isCaptureSupported, type ScreenCapture } from './screenshot';

/** The longest the capture waits for the panel to leave before taking the frame anyway. */
const STEP_ASIDE_MAX_MS = 400;

/** The three kinds a report can be (`App\Enums\FeedbackType`); the server refuses anything else. */
const KINDS = [
    { value: 'bug', label: 'Bug', hint: 'Something is broken or wrong' },
    { value: 'suggestion', label: 'Suggestion', hint: 'An idea or something to improve' },
    { value: 'question', label: 'Question', hint: 'You want to know how something works' },
] as const;

const page = usePage();

/** How many testing-round comments are still open (M179, `D150`) — the shared prop; 0 off-tenant. */
const testingRoundOpen = computed(() => page.props.feedback?.testing_round_open ?? 0);

const open = ref(false);
const capturing = ref(false);
const captureSupported = ref(isCaptureSupported());
const previewUrl = ref<string | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);

const form = useForm({
    type: '',
    route: '',
    page_url: '',
    page_title: '',
    remarks: '',
    browser_info: { userAgent: '', viewport: '', component: '', bundle: '' },
    screenshot: null as File | null,
});

function openPanel(): void {
    form.reset();
    form.clearErrors();
    clearScreenshot();
    form.route = window.location.pathname;
    form.page_url = window.location.href;
    form.page_title = document.title;
    form.browser_info = {
        userAgent: window.navigator.userAgent,
        viewport: `${window.innerWidth}x${window.innerHeight}`,
        component: page.component,
        bundle: APP_VERSION,
    };
    open.value = true;
}

function setScreenshot(file: File): void {
    releasePreview();
    form.screenshot = file;
    previewUrl.value = URL.createObjectURL(file);
}

function clearScreenshot(): void {
    releasePreview();
    form.screenshot = null;
    if (fileInput.value !== null) {
        // Reset the input's own value too, or re-picking the SAME file fires no change event.
        fileInput.value.value = '';
    }
}

/** Object URLs are held by the document until revoked — leaking one per capture is a real leak. */
function releasePreview(): void {
    if (previewUrl.value !== null) {
        URL.revokeObjectURL(previewUrl.value);
        previewUrl.value = null;
    }
}

/** Resolved by the modal's `after-leave`, or by the timer if that never comes. */
let panelLeft: (() => void) | null = null;

function onPanelLeft(): void {
    panelLeft?.();
}

/** Close the panel and resolve once it is off the page — at most {@see STEP_ASIDE_MAX_MS} later. */
function stepAside(): Promise<void> {
    return new Promise<void>((resolve) => {
        const timer = window.setTimeout(done, STEP_ASIDE_MAX_MS);
        function done(): void {
            window.clearTimeout(timer);
            panelLeft = null;
            resolve();
        }
        panelLeft = done;
        open.value = false;
    });
}

async function capture(): Promise<void> {
    capturing.value = true;
    let screen: ScreenCapture | null = null;
    let steppedAside = false;

    try {
        // Inside the click: the browser grants its picker only to a user gesture.
        screen = await acquireScreen();
        if (screen === null) {
            return; // declined — the panel never moved
        }

        steppedAside = true;
        await stepAside();

        // M176 (`R-8496edfa`): the panel is out of the DOM; two animation frames later the page without it has been
        // painted. A frame CAPTURED from then on cannot hold it — and `grab()` draws only such a frame (ADR-0015 §D11).
        await new Promise<void>((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => resolve())));
        const file = await screen.grab(performance.now());
        if (file !== null) {
            setScreenshot(file);
        }
    } catch {
        // A genuine capture failure is not worth an error banner on a feedback form — the report itself
        // is what matters, and the file input is right there. Drop the capture affordance instead, so the
        // reporter is not invited to retry something this browser has already proven it cannot do.
        captureSupported.value = false;
    } finally {
        screen?.stop();
        if (steppedAside) {
            // Directly, never openPanel(): the report the user was writing comes back exactly as it was.
            open.value = true;
        }
        capturing.value = false;
    }
}

function onFilePicked(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (file !== undefined) {
        setScreenshot(file);
    }
}

function submit(): void {
    form.post('/feedback', {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            clearScreenshot();
        },
    });
}

onBeforeUnmount(releasePreview);
</script>

<template>
    <div class="fb">
        <button
            type="button"
            class="fb__trigger"
            aria-haspopup="dialog"
            aria-label="Send feedback"
            @click="openPanel"
        >
            <MdsIcon name="feedback" size="md" aria-hidden="true" />
            <span class="fb__label">Feedback</span>
        </button>

        <MdsModal v-model:open="open" title="Send feedback" @after-leave="onPanelLeft">
            <form class="fb__form" @submit.prevent="submit">
                <fieldset class="fb__kind" :aria-describedby="form.errors.type ? 'fb-kind-error' : undefined">
                    <legend class="fb__kind-legend">
                        What kind of feedback is this?<span class="fb__required"> (required)</span>
                    </legend>
                    <div class="fb__kind-options">
                        <div v-for="kind in KINDS" :key="kind.value" class="fb__kind-option">
                            <MdsRadio
                                v-model="form.type"
                                name="feedback-kind"
                                :value="kind.value"
                                :label="kind.label"
                                :invalid="Boolean(form.errors.type)"
                                :describedby="`fb-kind-hint-${kind.value}`"
                            />
                            <span :id="`fb-kind-hint-${kind.value}`" class="fb__kind-hint">{{ kind.hint }}</span>
                        </div>
                    </div>
                    <p v-if="form.errors.type" id="fb-kind-error" class="fb__shot-error">{{ form.errors.type }}</p>
                </fieldset>

                <MdsFormField
                    label="What's on your mind?"
                    required
                    help="Tell us what's working, what isn't, or what you'd like to see."
                    :error="form.errors.remarks"
                    v-slot="{ id, describedby, invalid }"
                >
                    <textarea
                        :id="id"
                        v-model="form.remarks"
                        class="fb__textarea"
                        :class="{ 'fb__textarea--invalid': invalid }"
                        rows="5"
                        :aria-describedby="describedby"
                        :aria-invalid="invalid || undefined"
                    />
                </MdsFormField>

                <fieldset class="fb__shot">
                    <legend class="fb__shot-legend">Screenshot (optional)</legend>

                    <p v-if="previewUrl === null" class="fb__shot-hint">
                        <template v-if="captureSupported">
                            Your browser will ask which window or tab to capture — nothing is sent until you
                            choose one and press Send.
                        </template>
                        <template v-else>
                            Attach an image if it helps explain the problem.
                        </template>
                    </p>

                    <div v-if="previewUrl !== null" class="fb__shot-preview">
                        <img :src="previewUrl" alt="Preview of the screenshot you attached" />
                        <MdsButton variant="tertiary" size="sm" @click="clearScreenshot">Remove</MdsButton>
                    </div>

                    <div class="fb__shot-actions">
                        <MdsButton
                            v-if="captureSupported"
                            variant="secondary"
                            size="sm"
                            :loading="capturing"
                            icon-left="image"
                            @click="capture"
                        >
                            {{ previewUrl === null ? 'Capture screen' : 'Recapture' }}
                        </MdsButton>

                        <label class="fb__shot-file">
                            <span class="fb__shot-file-label">Attach an image</span>
                            <input
                                ref="fileInput"
                                type="file"
                                accept="image/png,image/jpeg,image/webp"
                                @change="onFilePicked"
                            />
                        </label>
                    </div>

                    <p v-if="form.errors.screenshot" class="fb__shot-error">{{ form.errors.screenshot }}</p>
                </fieldset>

                <!-- M175 (`R-9bfd832e`): where the reporter reads what became of what they sent. M179 (`D150`):
                     and, while some are open, the testing-round comments every member can check. -->
                <p class="fb__mine">
                    <Link href="/feedback/mine" class="fb__mine-link" @click="open = false">See your reports</Link>
                    and the support team's replies<template v-if="false">, and the
                        <Link href="/feedback/mine?view=testing" class="fb__mine-link" @click="open = false"
                            >testing-round comments ({{ testingRoundOpen }} open)</Link
                        ></template
                    >.
                </p>
            </form>
            <template #actions>
                <MdsButton variant="tertiary" @click="open = false">Cancel</MdsButton>
                <MdsButton variant="primary" :loading="form.processing" @click="submit">Send feedback</MdsButton>
            </template>
        </MdsModal>
    </div>
</template>

<style scoped>
.fb {
    display: inline-flex;
}

.fb__trigger {
    display: inline-flex;
    align-items: center;
    gap: var(--mds-space-2);
    min-height: 40px;
    padding: 0 var(--mds-space-3);
    border: none;
    border-radius: var(--mds-radius-md);
    background-color: transparent;
    color: var(--mds-color-text-secondary);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-md-font-size);
    font-weight: var(--mds-font-weight-medium);
    cursor: pointer;
}

.fb__trigger:hover {
    background-color: var(--mds-color-bg-sunken);
}

.fb__trigger:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}

.fb__form {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-4);
}

.fb__mine {
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

/* Always underlined, with the theme-aware primary text token (audit/Index.vue's note on dark contrast). */
.fb__mine-link {
    color: var(--mds-color-action-primary-fg);
    text-decoration: underline;
    text-underline-offset: 2px;
}

.fb__mine-link:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}

.fb__kind {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    margin: 0;
    padding: 0;
    border: none;
}

.fb__kind-legend {
    padding: 0;
    margin-bottom: var(--mds-space-1);
    color: var(--mds-color-text-body);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size);
    font-weight: var(--mds-font-weight-medium);
}

/* The shared field's own "(required)" (MdsFormField), so the panel's two questions say it the same way. */
.fb__required {
    font-weight: var(--mds-font-weight-regular);
    color: var(--mds-color-text-secondary);
}

.fb__kind-options {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 9rem), 1fr));
    gap: var(--mds-space-1) var(--mds-space-3);
}

.fb__kind-option {
    display: flex;
    flex-direction: column;
}

.fb__kind-hint {
    margin-top: calc(-1 * var(--mds-space-2));
    padding-left: var(--mds-space-6);
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
}

.fb__shot {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    margin: 0;
    padding: 0;
    border: none;
}

.fb__shot-legend {
    padding: 0;
    color: var(--mds-color-text-body);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size);
    font-weight: var(--mds-font-weight-medium);
}

.fb__shot-hint {
    margin: 0;
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
}

.fb__shot-preview {
    display: flex;
    align-items: flex-start;
    gap: var(--mds-space-3);
}

.fb__shot-preview img {
    width: 180px;
    max-height: 120px;
    object-fit: cover;
    object-position: top left;
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-sunken);
}

.fb__shot-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--mds-space-3);
}

.fb__shot-file {
    display: inline-flex;
    flex-direction: column;
    gap: var(--mds-space-1);
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
}

.fb__shot-file input[type='file'] {
    max-width: 220px;
    color: var(--mds-color-text-body);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size);
}

.fb__shot-file input[type='file']:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}

/* status-danger-fg, not danger-text: the latter resolves to danger-300 in dark, which is 4.22:1 on
   bg-surface at this size — a real contrast failure only the dark axe sweep sees. */
.fb__shot-error {
    margin: 0;
    color: var(--mds-color-status-danger-fg);
    font-size: var(--mds-type-body-sm-font-size);
}

.fb__textarea {
    width: 100%;
    padding: var(--mds-space-2) var(--mds-space-3);
    border: 1px solid var(--mds-color-input-border);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-input-bg);
    color: var(--mds-color-text-body);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-md-font-size);
    resize: vertical;
}

.fb__textarea:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 1px;
    border-color: var(--mds-color-action-primary-bg);
}

.fb__textarea--invalid {
    border-color: var(--mds-color-action-danger-bg);
}

@media (max-width: 480px) {
    .fb__label {
        position: absolute;
        width: 1px;
        height: 1px;
        padding: 0;
        margin: -1px;
        overflow: hidden;
        clip: rect(0 0 0 0);
        white-space: nowrap;
    }
}
</style>
