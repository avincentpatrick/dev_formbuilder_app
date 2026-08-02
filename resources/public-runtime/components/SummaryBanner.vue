<script setup lang="ts">
/**
 * The submit/Next-attempt error summary (UX §4.2 mechanism 2 / §10.2). Lists every currently-failing field as
 * a jump link that scrolls to and focuses that field — the safety net for required fields a respondent never
 * touched inline. The banner itself is the focus target on a failed attempt (`focus()` is exposed to the
 * parent flow).
 */
import { nextTick, ref } from 'vue';

interface BannerItem {
    // The field/instance/section address (`field`, `section[i].field`, or `section`), matching both the
    // jump-anchor id `field-<address>` and the 422-envelope key (Increment G2).
    address: string;
    label: string;
    /** The visible step this error belongs to (Increment H21b — a submit-time list can span steps). */
    stepKey: string;
}

const props = defineProps<{
    items: BannerItem[];
    /**
     * Increment H21b, Doc #27 §5.5 — run before the jump, so a multi-step parent can navigate to the item's
     * step first. Without it `getElementById` cannot resolve a field on an unmounted step and the link is
     * dead. Single-page mode renders everything, so it passes nothing and the behaviour is unchanged.
     */
    beforeJump?: (item: BannerItem) => void;
}>();

const rootEl = ref<HTMLElement | null>(null);

async function jumpTo(item: BannerItem): Promise<void> {
    props.beforeJump?.(item);
    await nextTick();
    const el = document.getElementById(`field-${item.address}`);
    if (el === null) {
        return;
    }
    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    const focusable = el.querySelector<HTMLElement>('input, select, textarea, button, [tabindex]');
    focusable?.focus();
}

defineExpose({ focus: () => rootEl.value?.focus() });
</script>

<template>
    <div ref="rootEl" class="summary-banner" role="alert" tabindex="-1">
        <p class="summary-banner__lead">
            {{ props.items.length }}
            {{ props.items.length === 1 ? 'field needs' : 'fields need' }} your attention before continuing.
        </p>
        <ul class="summary-banner__list">
            <li v-for="item in props.items" :key="item.address">
                <button type="button" class="summary-banner__link" @click="jumpTo(item)">
                    {{ item.label }}
                </button>
            </li>
        </ul>
    </div>
</template>

<style scoped>
.summary-banner {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    padding: var(--mds-space-4);
    border: 1px solid var(--mds-color-action-danger-bg);
    border-left-width: 4px;
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-surface);
}

.summary-banner:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}

.summary-banner__lead {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-danger-text);
}

.summary-banner__list {
    margin: 0;
    padding-left: var(--mds-space-5);
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-1);
}

.summary-banner__link {
    padding: 0;
    border: 0;
    background: transparent;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-md-font-size);
    color: var(--mds-color-action-primary-fg);
    text-decoration: underline;
    cursor: pointer;
}

.summary-banner__link:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}
</style>
