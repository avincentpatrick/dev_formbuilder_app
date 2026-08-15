<script setup lang="ts">
/**
 * The initials chip (DSR §3.12, Increment J4a).
 *
 * ── IT IS ALWAYS DECORATIVE, AND THERE IS NO PROP THAT CHANGES THAT ─────────────────────────────────────
 * `aria-hidden` is unconditional. The rule the system takes on with this component: *an avatar is always
 * accompanied by the person's visible name; an avatar that must carry a name is not an avatar, it is a link
 * or a button whose accessible name is the person.* Every consumer shipped in J4a satisfies it — the roster
 * cell, the audit actor and the inbox respondent all render the name in the same cell.
 *
 * The reconsideration trigger is a CONSUMER, not an argument: a stacked "+3 others" group, or an avatar with
 * no adjacent name. Neither exists today. That is the same discipline that kept `rowHref` off
 * `MdsDataTable` — build the affordance when something needs it, so the API is designed from a real case
 * rather than from one imagined example.
 *
 * ── NO `src`, AND THAT IS NOT AN OVERSIGHT ──────────────────────────────────────────────────────────────
 * Nothing in this product stores a profile photo: there is no `avatar_url` anywhere in the schema or the
 * client, and the `Avatar` attachment kind has no user-facing producer. When one arrives the CONTRACT
 * changes shape rather than gaining a prop — an image needs `alt`, a broken image needs an initials
 * fallback, and that fallback needs an error handler — so it is a different version of this component, not
 * a spare argument on this one.
 *
 * ── MONOCHROME, AND THE ALTERNATIVE WAS MEASURED RATHER THAN DISLIKED ───────────────────────────────────
 * Reusing the six-hue form-identity scale is the obvious idea and it is wrong three times over. As a solid
 * fill under white text the dark step of identity-4 measures **2.91:1** — it is built to carry its own hue
 * as TEXT on a 12% tint, which is a different visual object from the filled disc `AccountMenu` established.
 * The scale's own suite proves every hue sits at least 30° from every other and from every chromatic status
 * hue, so drawing people from the same six would put a PERSON and a FORM at 0° — on the audit log a
 * person's chip would sit two columns from a form's chip in the same colour, and the mnemonic that scale
 * exists for stops being unique. And it would need a per-user identity integer that nothing computes today.
 * Under "never colour alone" the hue could not carry meaning anyway. The backlog row names the four
 * preconditions for revisiting.
 */
import { computed } from 'vue';
import { avatarInitials } from './initials';

export type AvatarSize = 'sm' | 'md' | 'lg';
export type AvatarTone = 'brand' | 'neutral';

const props = withDefaults(
    defineProps<{
        /** The person's name, exactly as it is rendered beside this avatar. `null` renders a placeholder. */
        name: string | null;
        /** 24px for a dense table row · 28px for the account menu · 40px for a page header. */
        size?: AvatarSize;
        /** `neutral` for someone who is not a full participant yet — a pending invite, an anonymous guest. */
        tone?: AvatarTone;
    }>(),
    { size: 'sm', tone: 'brand' },
);

const initials = computed(() => avatarInitials(props.name));
</script>

<template>
    <span class="mds-avatar" :class="[`mds-avatar--${size}`, `mds-avatar--${tone}`]" aria-hidden="true">{{
        initials
    }}</span>
</template>

<style scoped>
.mds-avatar {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    border-radius: var(--mds-radius-full);
    font-family: var(--mds-font-family-body);
    font-weight: var(--mds-font-weight-semibold);
    line-height: 1;
    /* Two graphemes of CJK are wider than two of Latin; a chip that grows is fine, one that spills is not. */
    overflow: hidden;
}

/*
 * ⚠️ `min-inline-size` + `aspect-ratio`, NEVER A FIXED `width`/`height`, AND THE REASON IS RECORDED IN THE
 * COMPONENT THIS ONE SITS BESIDE. `NotificationBell.vue` pins its own count bubble and documents what that
 * cost: the caption type role is 12px by default and 15px under the extra-large personalization axis, which
 * put 17px of text inside an 18px line box, clipping descenders and failing WCAG 1.4.12. An avatar that
 * grows with the type scale is a slightly larger avatar; one that cannot is unreadable for the people the
 * accommodation exists for.
 */
.mds-avatar--sm {
    min-inline-size: 24px;
    min-block-size: 24px;
    aspect-ratio: 1;
    padding: 0 var(--mds-space-0-5);
    font-size: var(--mds-type-caption-font-size);
}

.mds-avatar--md {
    min-inline-size: 28px;
    min-block-size: 28px;
    aspect-ratio: 1;
    padding: 0 var(--mds-space-1);
    font-size: var(--mds-type-caption-font-size);
}

.mds-avatar--lg {
    min-inline-size: 40px;
    min-block-size: 40px;
    aspect-ratio: 1;
    padding: 0 var(--mds-space-1);
    font-size: var(--mds-type-body-sm-font-size);
}

/* 4.71:1 in both themes — the pair `AccountMenu` already wore and `MdsCheckbox` paints with. */
.mds-avatar--brand {
    background-color: var(--mds-color-action-primary-bg);
    color: var(--mds-color-text-on-primary);
}

/* 9.94:1 light / 10.10:1 dark. For somebody who is not a full participant yet. */
.mds-avatar--neutral {
    background-color: var(--mds-color-status-neutral-bg);
    color: var(--mds-color-status-neutral-fg);
}
</style>
