<script setup lang="ts">
/**
 * Account menu — avatar-initials trigger opening an ARIA menu (name/email header, Settings link,
 * Log out). Log out goes through Inertia (router.post) rather than a raw form. Escape/click-outside
 * close and restore focus (useDismissable); arrow keys move between menu items.
 */
import { computed, nextTick, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { MdsAvatar, MdsIcon } from '@meridian/design-system';
import { useDismissable } from '@/composables/useDismissable';

const page = usePage();
const user = computed(() => page.props.auth.user);

const open = ref(false);
const rootEl = ref<HTMLElement | null>(null);
const triggerEl = ref<HTMLButtonElement | null>(null);
const menuEl = ref<HTMLElement | null>(null);
const { close } = useDismissable({ isOpen: open, rootEl, triggerEl });

function items(): HTMLElement[] {
    return menuEl.value ? Array.from(menuEl.value.querySelectorAll<HTMLElement>('[role="menuitem"]')) : [];
}

async function toggle(): Promise<void> {
    open.value = !open.value;
    if (open.value) {
        await nextTick();
        items()[0]?.focus();
    }
}

function onMenuKeydown(event: KeyboardEvent): void {
    const list = items();
    const i = list.indexOf(document.activeElement as HTMLElement);
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        list[(i + 1) % list.length]?.focus();
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        list[(i - 1 + list.length) % list.length]?.focus();
    } else if (event.key === 'Home') {
        event.preventDefault();
        list[0]?.focus();
    } else if (event.key === 'End') {
        event.preventDefault();
        list[list.length - 1]?.focus();
    } else if (event.key === 'Tab') {
        close(false);
    }
}

function logout(): void {
    open.value = false;
    router.post('/logout');
}
</script>

<template>
    <div ref="rootEl" class="account">
        <button
            ref="triggerEl"
            type="button"
            class="account__trigger"
            :aria-expanded="open"
            aria-haspopup="menu"
            aria-label="Account menu"
            @click="toggle"
        >
            <MdsAvatar :name="user?.name ?? null" size="md" />
            <MdsIcon name="chevron-down" size="sm" aria-hidden="true" />
        </button>

        <div
            v-if="open"
            ref="menuEl"
            class="account__menu"
            role="menu"
            aria-label="Account"
            @keydown="onMenuKeydown"
        >
            <div class="account__header">
                <p class="account__name">{{ user?.name }}</p>
                <p class="account__email">{{ user?.email }}</p>
            </div>
            <Link href="/settings" role="menuitem" tabindex="-1" class="account__item" @click="open = false">
                <MdsIcon name="settings" size="sm" />
                <span>Settings</span>
            </Link>
            <button type="button" role="menuitem" tabindex="-1" class="account__item" @click="logout">
                <MdsIcon name="logout" size="sm" />
                <span>Log out</span>
            </button>
        </div>
    </div>
</template>

<style scoped>
.account {
    position: relative;
}

.account__trigger {
    display: inline-flex;
    align-items: center;
    gap: var(--mds-space-1);
    min-height: 40px;
    padding: 0 var(--mds-space-2);
    border: none;
    border-radius: var(--mds-radius-md);
    background-color: transparent;
    color: var(--mds-color-text-secondary);
    cursor: pointer;
}

.account__trigger:hover {
    background-color: var(--mds-color-bg-sunken);
}

.account__trigger:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}


.account__menu {
    position: absolute;
    top: calc(100% + var(--mds-space-1));
    right: 0;
    z-index: 30;
    min-width: 220px;
    padding: var(--mds-space-1);
    background-color: var(--mds-color-bg-surface-raised);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    box-shadow: var(--mds-shadow-3);
}

.account__header {
    padding: var(--mds-space-2) var(--mds-space-3);
    border-bottom: 1px solid var(--mds-color-border-default);
    margin-bottom: var(--mds-space-1);
}

.account__name {
    margin: 0;
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-heading);
}

.account__email {
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.account__item {
    display: flex;
    align-items: center;
    gap: var(--mds-space-2);
    width: 100%;
    min-height: 40px;
    padding: 0 var(--mds-space-3);
    border: none;
    border-radius: var(--mds-radius-sm);
    background-color: transparent;
    color: var(--mds-color-text-body);
    font: inherit;
    font-size: var(--mds-type-body-md-font-size);
    text-align: left;
    text-decoration: none;
    cursor: pointer;
}

.account__item:hover,
.account__item:focus-visible {
    background-color: var(--mds-color-bg-sunken);
}

.account__item:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: -2px;
}
</style>
