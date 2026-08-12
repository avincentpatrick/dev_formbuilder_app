<script setup lang="ts">
/**
 * The forms list's per-form action cluster (JR3), extracted verbatim from `forms/Index.vue`'s
 * `#row-actions` slot so the card grid and the table render the SAME nine buttons from one source.
 *
 * ⚠️ EXTRACTED, NOT REDESIGNED. Every icon, every `label` — which is both the `aria-label` and the
 * native `title` — and every gate is byte-identical to what the table row shipped, because those labels
 * are load-bearing far outside this file:
 *
 *   - `responsive-axe.spec.ts` navigates by `'Response statistics'` and `'New submission'`;
 *   - `templates-axe.spec.ts` clicks `'Save as template'` unscoped, so that button must be in the
 *     accessibility tree WITHOUT a hover or a menu open;
 *   - `'Rename form'` opens the only call site of `PATCH /forms/{form}` in the entire client, and
 *     `'Set form scope'` is the only mount of `AssignScopeModal`. Neither may be folded away.
 *
 * ⚠️ AND WHY NINE BUTTONS STAY VISIBLE ON A ~260px CARD, WRAPPING TO TWO ROWS. The obvious tidy is an
 * overflow "…" menu, and it is not available: the design system has no `MdsMenu`/`MdsPopover` at all
 * (exceptions-log #9 lists them among the ~15 primitives J4 builds), so it would have to be hand-rolled
 * — a THIRD hand-rolled menu beside `AccountMenu` and `NotificationBell`, which DSR §1.3 names as the
 * signal that a shared component is missing rather than an invitation to write another one. It would
 * also take `Save as template` out of the accessibility tree until opened, which breaks the e2e spec
 * above. Wrapping is the honest cost of keeping every affordance reachable in one tab order.
 *
 * Navigations are issued here; anything that needs page-level state (a modal target, the publish
 * spinner) is emitted, so this component owns no state of its own.
 */
import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { MdsIconButton } from '@meridian/design-system';
import { useEntitlements } from '@/composables/useEntitlements';
import type { FormRow } from '@/types/forms';

const props = defineProps<{
    row: FormRow;
    /** The id currently publishing, so exactly one button shows a spinner. */
    publishingId: string | null;
}>();

defineEmits<{
    history: [row: FormRow];
    template: [row: FormRow];
    rename: [row: FormRow];
    scope: [row: FormRow];
    publish: [row: FormRow];
    archive: [row: FormRow];
}>();

const { feature } = useEntitlements();
const page = usePage();
const canManageScopes = computed(() => page.props.auth.can.manageScopes);
const publishing = computed(() => props.publishingId === props.row.id);
</script>

<template>
    <div class="form-actions">
        <!-- J2d (user decision, 2026-08-11) — the one-click way back into the builder. J2b repointed the
             row's TITLE at the form hub, which was right (the hub is readable by all five roles while the
             builder is not), but it left an editor two clicks from the page they spend their day in.

             ⚠️ THIS IS AN ADDITION BESIDE THE `edit` ICON, NEVER A REPLACEMENT FOR IT. That icon is
             *Rename form*, and its modal is the ONLY call site of `PATCH /forms/{form}` in the entire
             client — "tidying" the two together would delete the only way to rename a form anywhere in
             the product.

             ⚠️ `layout`, NOT `edit`, AND THE MISMATCH WITH THE TAB STRIP IS THE LESSER EVIL. `FormTabSet`
             gives the Builder tab `edit`, so matching it would have put TWO identical glyphs in this row
             with different labels and different destinations. The icon set has no `pencil` to separate
             them with. `layout` also reads as the three-pane canvas it opens. -->
        <MdsIconButton
            v-if="row.can.edit"
            icon="layout"
            label="Open builder"
            size="sm"
            @click="router.visit(`/forms/${row.id}/builder`)"
        />
        <!-- I10c — the per-form statistics page (PRD #4's Form Owner/Editor view). `chart-bar` matches the
             Analytics nav glyph deliberately: the association is the point, and the glyph-uniqueness rule
             applies to the sidebar, not to row actions. -->
        <MdsIconButton
            v-if="row.can.analytics"
            icon="chart-bar"
            label="Response statistics"
            size="sm"
            @click="router.visit(`/forms/${row.id}/analytics`)"
        />
        <MdsIconButton
            v-if="row.can.encode"
            icon="submissions"
            label="New submission"
            size="sm"
            @click="router.visit(`/forms/${row.id}/submissions/create`)"
        />
        <MdsIconButton icon="clock" label="Version history" size="sm" @click="$emit('history', row)" />
        <MdsIconButton
            v-if="row.can.template && feature('form_templates')"
            icon="copy"
            label="Save as template"
            size="sm"
            @click="$emit('template', row)"
        />
        <MdsIconButton
            v-if="row.can.edit"
            icon="edit"
            label="Rename form"
            size="sm"
            @click="$emit('rename', row)"
        />
        <!-- Gated on manageScopes, not row.can.edit: assigning a form to a scope hands everyone holding a
             grant on that branch access to the form AND its submissions, so it is an Owner/Admin act. The
             route enforces the same thing independently by stacking can:viewAny,ScopeNode on top of
             can:update,form. -->
        <MdsIconButton
            v-if="canManageScopes"
            icon="building"
            label="Set form scope"
            size="sm"
            @click="$emit('scope', row)"
        />
        <MdsIconButton
            v-if="row.can.publish"
            icon="check"
            label="Publish form"
            size="sm"
            :loading="publishing"
            @click="$emit('publish', row)"
        />
        <MdsIconButton
            v-if="row.can.delete"
            icon="trash"
            label="Archive form"
            variant="danger"
            size="sm"
            @click="$emit('archive', row)"
        />
    </div>
</template>

<style scoped>
.form-actions {
    display: inline-flex;
    gap: var(--mds-space-1);
    justify-content: flex-end;
    /* The card is the narrow consumer: nine 28px buttons need ~284px and a 300px track leaves ~260px,
       so the cluster wraps to two lines there and stays on one in a table row. `flex-wrap` is the whole
       adaptation — no breakpoint, because the available width depends on the view, not the viewport. */
    flex-wrap: wrap;
    row-gap: var(--mds-space-1);
}
</style>
