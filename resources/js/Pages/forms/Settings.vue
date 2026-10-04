<script setup lang="ts">
/**
 * The form hub's Settings tab (M129 — the half of `D63` that `M117` did not build, row `R-1132a6f3`).
 *
 * `D63` answered "both entry points": a Settings tab on the hub for administering a form, and the builder's
 * "Form settings" modal for authoring it, mounting the SAME sections against the SAME routes. This page is
 * the second entry point, and it holds no section of its own — every one is {@link FormSettingsSections}, the
 * component the modal wraps — plus Scope, which only the hub offers because it confers capacity rather than
 * describing the form.
 *
 * ⚠️ EXACTLY ONE `navigation` IS NAMED WITH THE FORM'S TITLE: the tab strip. Playwright's
 * `getByRole('navigation', { name })` matches by substring, and every hub spec waits on that strip by the
 * form's title, so a second landmark carrying the title would make each of those waits resolve two elements.
 * The breadcrumb's landmark is named "Breadcrumb".
 *
 * `open` is held true: the sections re-seed when it turns true, which on a page means once, at mount.
 */
import { Head, Link } from '@inertiajs/vue3';
import { MdsBreadcrumb, MdsCard, MdsTabNav, type BreadcrumbItem, type TabNavItem } from '@meridian/design-system';
import PageHeader from '@/components/shell/PageHeader.vue';
import FormSettingsSections from '@/components/forms/FormSettingsSections.vue';
import { useEntitlements } from '@/composables/useEntitlements';
import type { FormSettingsForm, OcrScanningProps, ReferenceFileRow, ScopeSectionProps, ShareProps } from '@/components/forms/types';

defineProps<{
    form: FormSettingsForm & { id: string };
    share: ShareProps;
    timezones: string[];
    /** Null where the workspace cannot scan — decided by the server, from the Scanning route's own gates. */
    ocr_scanning: OcrScanningProps | null;
    /** The Reference files section (M132): the draft's files. */
    reference_files: ReferenceFileRow[];
    /** Null unless the reader holds `scopes.manage`. */
    scope: ScopeSectionProps | null;
    /** The form's tab strip, resolved server-side by `FormTabSet`. */
    tabs: TabNavItem[];
    /** The trail, resolved server-side by `CrumbTrail`. */
    crumbs: BreadcrumbItem[];
}>();

const { feature } = useEntitlements();
</script>

<template>
    <div class="settings-page">
        <Head :title="`Settings — ${form.title}`" />

        <PageHeader title="Settings" icon="sliders">
            <template #breadcrumbs>
                <MdsBreadcrumb :items="crumbs" :link-component="Link" />
            </template>
        </PageHeader>

        <!-- `:ariaLabel` in camelCase deliberately — see the same call on `forms/Show.vue`; the kebab spelling
             type-checks as an HTML attribute and leaves the required prop missing. -->
        <MdsTabNav :items="tabs" current="settings" :ariaLabel="form.title" :link-component="Link" />

        <p class="settings-page__intro">
            Everything about how <strong>{{ form.title }}</strong> is shared, scheduled and collected. These are the
            same settings the builder's Form settings opens; a change made in either place is made in both.
        </p>

        <MdsCard>
            <FormSettingsSections
                :open="true"
                :form-id="form.id"
                :form="form"
                :timezones="timezones"
                :share="share"
                :save-resume-available="feature('save_and_resume')"
                :ocr-scanning="ocr_scanning"
                :reference-files="reference_files"
                :scope="scope"
            />
        </MdsCard>
    </div>
</template>

<style scoped>
.settings-page {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-4);
}

.settings-page__intro {
    margin: 0;
    color: var(--mds-color-text-body);
}
</style>
