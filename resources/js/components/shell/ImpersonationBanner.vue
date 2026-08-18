<script setup lang="ts">
/**
 * "You are signed in as somebody else" — Increment I11b, rbac §9's resolved decision 1.
 *
 * ── WHY THIS IS IN THE SHELL AND NOT ON A PAGE ──────────────────────────────────────────────────────────
 * An operator inside a customer's workspace has the full authority of the member they are borrowing: they
 * can publish a form, approve a submission, change a role. The one failure this surface exists to prevent
 * is FORGETTING — believing you are on your own account and acting accordingly. A banner that appeared on
 * some pages and not others would fail exactly when the operator had navigated somewhere absorbing enough
 * to forget. It reads `auth.impersonating`, a shared prop rendered on every request, so a page cannot omit
 * it by not knowing about it.
 *
 * ── ⚠️ IT NAMES THE MEMBER, NEVER THE OPERATOR ──────────────────────────────────────────────────────────
 * The prop carries no operator identity to render, deliberately — see `HandleInertiaRequests::share()`.
 * I11a's review found the tenant-facing audit page resolving and displaying an operator's real name
 * whenever that operator happened to hold a membership of the tenant; `actingAsLabel()` now returns a fixed
 * string unconditionally as a POLICY. This banner is on the same side of that policy. It says whose account
 * this is (which the operator needs, and which the tenant already knows) and nothing about who is driving.
 *
 * ── THE EXIT IS AN ORDINARY INERTIA VISIT, AND THE CROSS-ORIGIN HOP IS THE SERVER'S PROBLEM ─────────────
 * `router.post()`, following this surface's standing rule that every mutation is an Inertia visit. The
 * response is a 409 `Inertia::location()` to the CENTRAL console — a different origin — which the Inertia
 * client turns into a real navigation. A plain 302 there would NOT work, which is why the controller must
 * never be "simplified" into one.
 *
 * (A native <form> POST was considered on the grounds that it needs no JavaScript. It is a false
 * robustness: this banner is a Vue component, so anything that stopped the SPA rendering would have taken
 * the exit button with it.)
 */
import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { MdsBanner, MdsButton } from '@meridian/design-system';

const page = usePage();

const impersonating = computed(() => page.props.auth?.impersonating ?? null);
const memberName = computed(() => page.props.auth?.user?.name ?? 'this member');

function exit(): void {
    const url = impersonating.value?.exit_url;

    if (url) {
        router.post(url);
    }
}
</script>

<template>
    <MdsBanner
        v-if="impersonating"
        tone="danger"
        icon="shield"
        :message="`Platform support session — you are signed in as ${memberName}. Everything you do is recorded in this workspace's audit log.`"
        data-testid="impersonation-banner"
    >
        <template #action>
            <MdsButton variant="secondary" size="sm" @click="exit">Exit support session</MdsButton>
        </template>
    </MdsBanner>
</template>
