<script setup lang="ts">
/**
 * Tenant branding — the `/settings` card (Increment H23a2, ADR-0014).
 *
 * **THE SNAP DISCLOSURE IS THE POINT OF THIS COMPONENT, not a nicety.** ADR-0014 §D2 discards the
 * tenant's lightness and re-derives it per role, so the colour that renders is frequently NOT the colour
 * they typed. The ADR requires that difference to be *disclosed rather than discovered*, which is why
 * this card always shows the submitted swatch beside the derived one, says plainly when they differ, and
 * prints the measured contrast that forced the change. A card that showed only the derived swatch would
 * be technically accurate and quietly dishonest.
 *
 * The live preview runs the TypeScript twin of the PHP engine, so a tenant sees the ramp before saving.
 * `brand-ramp.ts` is held byte-identical to `BrandRampGenerator` by a shared fixture; if that ever drifts
 * the preview would promise one ramp and the server would store another, which is why the parity suite
 * exists and why this component deliberately does not do its own colour maths.
 *
 * Two states this card must distinguish and a naive version would not:
 *  · **stored but INACTIVE** — a tenant branded on Starter and downgraded. The brand still exists, does
 *    not render, and must remain removable (ADR-0012 §D9's precedent; the DELETE routes carry no
 *    `feature:` gate for exactly this reason).
 *  · **logo uploaded but NOT YET SERVABLE** — the virus scan has not finished. Showing a broken image
 *    for those seconds would read as a failed upload.
 */
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import {
    MdsButton,
    MdsCard,
    MdsFormField,
    MdsIcon,
    MdsTextInput,
    generateBrandRamp,
    brandRampSnap,
    brandRampByHeadroom,
    type BrandRampResult,
} from '@meridian/design-system';

const props = defineProps<{
    branding: {
        can_manage: boolean;
        has_brand_color: boolean;
        is_active: boolean;
        required_tier: string | null;
        input_color: string | null;
        tokens: Record<'light' | 'dark', Record<string, string>> | null;
        snap: { input: string; derived: string; adjusted: boolean } | null;
        contrast: {
            measurements: { theme: string; pairing: string; ratio: number; min: number }[];
            count: number;
        } | null;
        logo: {
            id: string;
            filename: string | null;
            width: number | null;
            height: number | null;
            servable: boolean;
        } | null;
    };
}>();

const HEX = /^#[0-9A-Fa-f]{6}$/;

const colorForm = useForm({ primary_color: props.branding.input_color ?? '#0E6FE8' });
const logoInput = ref<HTMLInputElement | null>(null);

/**
 * The ramp the CURRENT input would produce, or null while the field holds an incomplete hex.
 *
 * Wrapped in a try/catch because the engine throws on a malformed input by contract — the field is a
 * free-text box and is malformed for most of the time a user spends typing into it, which is a normal
 * state and not an error worth surfacing.
 */
const preview = computed<BrandRampResult | null>(() => {
    if (!HEX.test(colorForm.primary_color)) return null;
    try {
        return generateBrandRamp(colorForm.primary_color);
    } catch {
        return null;
    }
});

// Both derivations come from the design system rather than being spelled here: each has a PHP twin in
// BrandingPresenter, and a third inline copy would be a third place for the same rule to drift.
const previewAdjusted = computed(() => (preview.value === null ? false : brandRampSnap(preview.value).adjusted));

const previewMeasurements = computed(() =>
    preview.value === null ? [] : brandRampByHeadroom(preview.value.measurements),
);

// Keep the field in step when the server sends back a different stored value (e.g. after a save that
// normalised case, or after a removal).
watch(
    () => props.branding.input_color,
    (value) => {
        colorForm.primary_color = value ?? '#0E6FE8';
    },
);

function saveColor(): void {
    colorForm.patch('/settings/branding', { preserveScroll: true });
}

function removeColor(): void {
    useForm({}).delete('/settings/branding', { preserveScroll: true });
}

function uploadLogo(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file) return;

    useForm({ logo: file }).post('/settings/branding/logo', {
        preserveScroll: true,
        onFinish: () => {
            if (logoInput.value) logoInput.value.value = '';
        },
    });
}

function removeLogo(): void {
    useForm({}).delete('/settings/branding/logo', { preserveScroll: true });
}
</script>

<template>
    <MdsCard v-if="branding.can_manage" class="settings-card">
        <template #header>
            <div class="settings-card__head">
                <MdsIcon name="sliders" size="sm" aria-hidden="true" />
                <h2 class="settings-card__title">Branding</h2>
            </div>
        </template>

        <!-- Stored but not rendering: the downgraded tenant. Stated first, because everything below it
             describes a brand that is currently switched off — and the removal button is the one action
             that is deliberately still available on this plan. -->
        <div v-if="branding.has_brand_color && !branding.is_active" class="branding__notice" role="status">
            <MdsIcon name="alert" size="sm" aria-hidden="true" />
            <p>
                Your brand is saved but not applied — branding needs the
                <strong>{{ branding.required_tier ?? 'a paid' }}</strong> plan or above. Your forms are
                using the default theme. You can still remove it below.
            </p>
        </div>

        <p class="branding__lede">
            Set one brand colour and we build the full palette from it — buttons, hover and pressed
            states, links, focus rings and a light and dark theme — checking every combination for
            readable contrast before it is saved.
        </p>

        <form class="settings-form" @submit.prevent="saveColor">
            <MdsFormField
                label="Brand colour"
                help="A six-digit hex value, for example #1B5E5E."
                :error="colorForm.errors.primary_color"
                v-slot="{ id, describedby, invalid }"
            >
                <div class="branding__input-row">
                    <MdsTextInput
                        :id="id"
                        v-model="colorForm.primary_color"
                        :describedby="describedby"
                        :invalid="invalid"
                        spellcheck="false"
                        autocomplete="off"
                        class="branding__hex"
                    />
                    <!-- A native colour well beside the text field: the text field stays the source of
                         truth (a brand hex is usually pasted, not picked), and this is the affordance for
                         someone who wants to browse. Labelled, because a bare swatch is a colour-only
                         control (WCAG 1.4.1). -->
                    <input
                        v-model="colorForm.primary_color"
                        type="color"
                        class="branding__well"
                        :aria-label="`Pick a brand colour (currently ${colorForm.primary_color})`"
                    />
                </div>
            </MdsFormField>

            <!-- ── THE SNAP DISCLOSURE ────────────────────────────────────────────────────────────
                 Rendered live, before saving, so the adjustment is never a surprise after the fact. -->
            <div v-if="preview" class="branding__preview">
                <h3 class="branding__preview-title">Preview</h3>

                <div class="branding__swatches">
                    <div class="branding__swatch-group">
                        <span class="branding__swatch" :style="{ background: preview.input }" aria-hidden="true" />
                        <span class="branding__swatch-label">You chose<br /><code>{{ preview.input }}</code></span>
                    </div>
                    <MdsIcon name="chevron-right" size="sm" aria-hidden="true" class="branding__arrow" />
                    <div class="branding__swatch-group">
                        <span
                            class="branding__swatch"
                            :style="{ background: preview.tokens.light.bg }"
                            aria-hidden="true"
                        />
                        <span class="branding__swatch-label">
                            We'll use<br /><code>{{ preview.tokens.light.bg }}</code>
                        </span>
                    </div>
                </div>

                <p v-if="previewAdjusted" class="branding__adjusted">
                    <MdsIcon name="info" size="sm" aria-hidden="true" />
                    We darkened your colour so white button text stays readable on it. Your hue is kept —
                    only the lightness changed.
                </p>
                <p v-else class="branding__adjusted branding__adjusted--exact">
                    <MdsIcon name="check" size="sm" aria-hidden="true" />
                    Your colour is used exactly as entered.
                </p>

                <!-- Both themes, because a tenant choosing a brand colour is choosing it for respondents
                     who may be in either, and the dark ramp is a different set of hexes entirely. -->
                <div class="branding__themes">
                    <div
                        v-for="theme in (['light', 'dark'] as const)"
                        :key="theme"
                        class="branding__theme"
                        :class="`branding__theme--${theme}`"
                    >
                        <span class="branding__theme-label">{{ theme === 'light' ? 'Light' : 'Dark' }}</span>
                        <span class="branding__fake-button" :style="{ background: preview.tokens[theme].bg }">
                            Submit
                        </span>
                        <span class="branding__fake-link" :style="{ color: preview.tokens[theme].fg }">A link</span>
                        <span class="branding__fake-tint" :style="{ background: preview.tokens[theme].tint }">
                            Selected row
                        </span>
                    </div>
                </div>

                <details class="branding__contrast">
                    <summary>
                        Contrast report — all {{ preview.measurements.length }} combinations pass
                    </summary>
                    <table class="branding__table">
                        <thead>
                            <tr>
                                <th scope="col">Theme</th>
                                <th scope="col">Combination</th>
                                <th scope="col">Measured</th>
                                <th scope="col">Minimum</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(m, i) in previewMeasurements" :key="`${m.theme}-${m.pairing}-${i}`">
                                <td>{{ m.theme }}</td>
                                <td>{{ m.pairing }}</td>
                                <td>{{ m.ratio.toFixed(2) }}:1</td>
                                <td>{{ m.min.toFixed(1) }}:1</td>
                            </tr>
                        </tbody>
                    </table>
                </details>
            </div>

            <div class="settings-form__foot">
                <MdsButton variant="primary" type="submit" :loading="colorForm.processing">
                    Save brand colour
                </MdsButton>
                <MdsButton v-if="branding.has_brand_color" variant="tertiary" type="button" @click="removeColor">
                    Remove
                </MdsButton>
                <span v-if="colorForm.recentlySuccessful" class="settings-form__saved" role="status">Saved</span>
            </div>
        </form>

        <section class="settings-sub">
            <h3 class="settings-sub__title">Logo</h3>
            <p class="branding__lede">
                Shown on your forms and on the emails we send on your behalf. PNG, JPEG or WebP, up to
                1&nbsp;MB.
            </p>

            <div v-if="branding.logo" class="branding__logo">
                <img
                    v-if="branding.logo.servable"
                    :src="`/attachments/${branding.logo.id}`"
                    :alt="`Current logo: ${branding.logo.filename ?? 'uploaded image'}`"
                    class="branding__logo-img"
                />
                <p v-else class="branding__logo-pending" role="status">
                    <MdsIcon name="clock" size="sm" aria-hidden="true" />
                    Checking this file — it will appear here shortly.
                </p>
                <MdsButton variant="tertiary" type="button" @click="removeLogo">Remove logo</MdsButton>
            </div>

            <label class="branding__upload">
                <span class="branding__upload-label">{{ branding.logo ? 'Replace logo' : 'Upload a logo' }}</span>
                <input
                    ref="logoInput"
                    type="file"
                    accept="image/png,image/jpeg,image/webp"
                    class="branding__upload-input"
                    @change="uploadLogo"
                />
            </label>
        </section>
    </MdsCard>
</template>

<style scoped>
/* Re-declared, not inherited (fixed in I5). Vue applies a parent's scope id to a child component's ROOT
   node only, so `Settings/Index.vue`'s `<style scoped>` reaches `.settings-card` here and nothing inside
   it — which is why this card's header rendered unstyled beside five identically-marked-up siblings from
   H23a2 until now. `NotificationPreferencesCard.vue` carried the same block from the start and named this
   file as the counter-example; every card on the page now declares it. */
.settings-card {
    max-width: 640px;
}

.settings-card__head {
    display: flex;
    align-items: center;
    gap: var(--mds-space-2);
    color: var(--mds-color-text-secondary);
}

.settings-card__title {
    margin: 0;
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-3-font-size);
    line-height: var(--mds-type-heading-3-line-height);
    font-weight: var(--mds-type-heading-3-font-weight);
    color: var(--mds-color-text-heading);
}

.branding__lede {
    margin: 0 0 var(--mds-space-4);
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
}

.branding__notice {
    display: flex;
    gap: var(--mds-space-2);
    align-items: flex-start;
    margin-bottom: var(--mds-space-4);
    padding: var(--mds-space-3);
    border-radius: var(--mds-radius-md);
    background: var(--mds-color-status-warning-bg);
    color: var(--mds-color-status-warning-fg);
}

.branding__notice p {
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
}

.branding__input-row {
    display: flex;
    gap: var(--mds-space-2);
    align-items: center;
    flex-wrap: wrap;
}

.branding__hex {
    max-width: 12rem;
}

.branding__well {
    inline-size: 2.75rem;
    block-size: 2.75rem;
    padding: 0;
    border: 1px solid var(--mds-color-input-border);
    border-radius: var(--mds-radius-md);
    background: none;
    cursor: pointer;
}

.branding__preview {
    margin: var(--mds-space-4) 0;
    padding: var(--mds-space-4);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background: var(--mds-color-bg-sunken);
}

.branding__preview-title,
.settings-sub__title {
    margin: 0 0 var(--mds-space-3);
    font-size: var(--mds-type-heading-4-font-size);
    line-height: var(--mds-type-heading-4-line-height);
    color: var(--mds-color-text-heading);
}

.branding__swatches {
    display: flex;
    gap: var(--mds-space-3);
    align-items: center;
    flex-wrap: wrap;
}

.branding__swatch-group {
    display: flex;
    gap: var(--mds-space-2);
    align-items: center;
}

.branding__swatch {
    inline-size: 2.5rem;
    block-size: 2.5rem;
    border-radius: var(--mds-radius-md);
    border: 1px solid var(--mds-color-border-default);
    flex: none;
}

.branding__swatch-label {
    font-size: var(--mds-type-caption-font-size);
    line-height: var(--mds-type-caption-line-height);
    color: var(--mds-color-text-secondary);
}

.branding__arrow {
    color: var(--mds-color-text-secondary);
}

.branding__adjusted {
    display: flex;
    gap: var(--mds-space-2);
    align-items: flex-start;
    margin: var(--mds-space-3) 0 0;
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-text-body);
}

.branding__adjusted--exact {
    color: var(--mds-color-text-secondary);
}

.branding__themes {
    display: flex;
    gap: var(--mds-space-3);
    flex-wrap: wrap;
    margin-top: var(--mds-space-4);
}

.branding__theme {
    display: flex;
    gap: var(--mds-space-2);
    align-items: center;
    flex-wrap: wrap;
    padding: var(--mds-space-3);
    border-radius: var(--mds-radius-md);
    min-inline-size: 0;
}

/* The preview panels carry FIXED grounds rather than theme tokens, deliberately: they are showing what a
   respondent sees in each theme, which is a different question from what THIS admin's theme is. The
   values are the real --mds-color-bg-surface in each theme (design-system-reference.md §2.2). */
.branding__theme--light {
    background: #ffffff;
    color: #121a2a;
    border: 1px solid var(--mds-color-border-default);
}

.branding__theme--dark {
    background: #1a2130;
    color: #eff4fd;
}

.branding__theme-label {
    font-size: var(--mds-type-caption-font-size);
    opacity: 0.75;
    inline-size: 100%;
}

.branding__fake-button {
    padding: var(--mds-space-2) var(--mds-space-3);
    border-radius: var(--mds-radius-md);
    color: #ffffff;
    font-size: var(--mds-type-body-sm-font-size);
    font-weight: 600;
}

.branding__fake-link {
    font-size: var(--mds-type-body-sm-font-size);
    text-decoration: underline;
}

.branding__fake-tint {
    padding: var(--mds-space-2) var(--mds-space-3);
    border-radius: var(--mds-radius-sm);
    font-size: var(--mds-type-body-sm-font-size);
}

.branding__contrast {
    margin-top: var(--mds-space-4);
    font-size: var(--mds-type-body-sm-font-size);
}

.branding__contrast summary {
    cursor: pointer;
    color: var(--mds-color-text-secondary);
}

/* Wide content scrolls inside its own container so the page body never scrolls sideways. */
.branding__table {
    display: block;
    overflow-x: auto;
    margin-top: var(--mds-space-3);
    border-collapse: collapse;
    inline-size: 100%;
    white-space: nowrap;
}

.branding__table th,
.branding__table td {
    padding: var(--mds-space-1) var(--mds-space-3) var(--mds-space-1) 0;
    text-align: start;
    font-weight: 400;
    color: var(--mds-color-text-body);
}

.branding__table th {
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-caption-font-size);
}

.branding__logo {
    display: flex;
    gap: var(--mds-space-3);
    align-items: center;
    flex-wrap: wrap;
    margin-bottom: var(--mds-space-3);
}

.branding__logo-img {
    max-inline-size: 12rem;
    max-block-size: 4rem;
    object-fit: contain;
}

.branding__logo-pending {
    display: flex;
    gap: var(--mds-space-2);
    align-items: center;
    margin: 0;
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
}

.branding__upload {
    display: inline-flex;
    flex-direction: column;
    gap: var(--mds-space-2);
}

.branding__upload-label {
    font-size: var(--mds-type-label-font-size);
    font-weight: 600;
    color: var(--mds-color-text-heading);
}
</style>
