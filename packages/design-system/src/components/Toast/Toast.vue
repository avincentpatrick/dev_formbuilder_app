<script setup lang="ts">
/**
 * A single toast (DSR §3.7). Ephemeral, non-blocking outcome feedback — never carries a primary CTA.
 * The leading ICON (shape) is the non-colour signifier of the type (§4.1). Error toasts announce
 * assertively (`role="alert"`) and are dismissed manually; success/info announce politely. Consumes
 * semantic tokens only; renders at `--mds-shadow-5` so it reads above an open modal (§2.5).
 */
import { computed } from 'vue';
import Icon from '../Icon/Icon.vue';
import type { IconName } from '../Icon/icons';
import type { ToastType } from './toast';

const props = defineProps<{ type: ToastType; message: string }>();

defineEmits<{ dismiss: [] }>();

const ICONS: Record<ToastType, IconName> = { success: 'check', error: 'alert', info: 'info' };
const iconName = computed<IconName>(() => ICONS[props.type]);
</script>

<template>
    <div
        class="mds-toast"
        :class="`mds-toast--${type}`"
        :role="type === 'error' ? 'alert' : 'status'"
        :aria-live="type === 'error' ? 'assertive' : 'polite'"
    >
        <Icon :name="iconName" size="md" class="mds-toast__icon" />
        <p class="mds-toast__message">{{ message }}</p>
        <button type="button" class="mds-toast__dismiss" aria-label="Dismiss" @click="$emit('dismiss')">
            <Icon name="close" size="sm" />
        </button>
    </div>
</template>

<style scoped>
.mds-toast {
    display: flex;
    align-items: flex-start;
    gap: var(--mds-space-3);
    width: 100%;
    padding: var(--mds-space-3) var(--mds-space-4);
    background-color: var(--mds-color-bg-surface-raised);
    border: 1px solid var(--mds-color-border-default);
    border-left-width: 3px;
    border-radius: var(--mds-radius-md);
    box-shadow: var(--mds-shadow-5);
    color: var(--mds-color-text-body);
}

.mds-toast--success {
    border-left-color: var(--mds-color-status-success-fg);
}
.mds-toast--error {
    border-left-color: var(--mds-color-status-danger-fg);
}
.mds-toast--info {
    border-left-color: var(--mds-color-status-info-fg);
}

.mds-toast__icon {
    flex-shrink: 0;
    margin-top: 1px;
}
.mds-toast--success .mds-toast__icon {
    color: var(--mds-color-status-success-fg);
}
.mds-toast--error .mds-toast__icon {
    color: var(--mds-color-status-danger-fg);
}
.mds-toast--info .mds-toast__icon {
    color: var(--mds-color-status-info-fg);
}

.mds-toast__message {
    flex: 1;
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
}

.mds-toast__dismiss {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    width: 24px;
    height: 24px;
    padding: 0;
    border: 0;
    border-radius: var(--mds-radius-sm);
    background: transparent;
    color: var(--mds-color-text-secondary);
    cursor: pointer;
}
.mds-toast__dismiss::before {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    min-width: 44px;
    min-height: 44px;
    width: 100%;
    height: 100%;
    transform: translate(-50%, -50%);
}
.mds-toast__dismiss:hover {
    color: var(--mds-color-text-body);
}
.mds-toast__dismiss:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}
</style>
