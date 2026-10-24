<script setup lang="ts">
/**
 * M172 (`R-52f5ad93`, `D133`) — one published version of a form, read-only, opened from the hub's Versions panel.
 *
 * The testers asked for "a way to check the older version for history": until now an old version was reachable only as
 * the Print blank PDF, the XLSForm download or the API. This page draws the version's frozen snapshot with the builder's
 * own preview, so it looks exactly as the builder's Preview did then. The questions can be tried — conditions and steps
 * respond as they would for a respondent — but nothing is saved, and none of the author's controls is offered: they
 * edit the draft, and this is not the draft.
 *
 * Built in the shared shell (the default `AppLayout`) from the design system's components, like every page.
 */
import { computed, onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { MdsAlert, MdsBreadcrumb, type BreadcrumbItem } from '@meridian/design-system';
import PageHeader from '@/components/shell/PageHeader.vue';
import PreviewRuntime from '@/components/builder/PreviewRuntime.vue';
import { applyPreviewChoices, fetchPreviewChoices, previewChoicesUrl, type PreviewChoices } from '@/components/builder/preview-choices';
import { hasChoiceLists } from '../../../public-runtime/lib/choice-lists';
import { hasLinkedChoices } from '../../../public-runtime/lib/linked-choices';
import { buildRenderModel } from '../../../public-runtime/lib/schema-mapping';
import type { SchemaResponse } from '../../../public-runtime/lib/types';

const props = defineProps<{
    version: { id: string; version_number: number; status: string; published_at: string | null };
    schema: SchemaResponse;
    hub_href: string;
    crumbs: BreadcrumbItem[];
}>();

// M184 (`R-d8c2ce84`): a cascade's list files and another form's answers are served beside the snapshot, as on the live
// form; read once, and the runtime is drawn again with them (its key moves when they land).
const choices = ref<PreviewChoices | null>(null);
onMounted(async () => {
    if (hasLinkedChoices(props.schema) || hasChoiceLists(props.schema)) {
        choices.value = await fetchPreviewChoices(previewChoicesUrl(props.schema.form.id, props.version.id));
    }
});
const schema = computed(() => (choices.value === null ? props.schema : applyPreviewChoices(props.schema, choices.value)));
const model = computed(() => buildRenderModel(schema.value));

const published = computed(() =>
    props.version.published_at === null
        ? ''
        : `, published ${new Date(props.version.published_at).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })}`,
);
</script>

<template>
    <div class="version-view">
        <Head :title="`${schema.form.title} — version ${version.version_number}`" />

        <PageHeader :title="`${schema.form.title} — version ${version.version_number}`" icon="forms">
            <template #breadcrumbs>
                <MdsBreadcrumb :items="crumbs" :link-component="Link" />
            </template>
        </PageHeader>

        <MdsAlert tone="info" :title="`Version ${version.version_number}${published} — read-only`" data-version-view-note>
            This is the form as this version asked it. You can try the questions, but nothing you enter is saved, and
            nothing here changes the form. To edit, open the Builder; to go back, return to the
            <Link :href="hub_href">form's overview</Link>.
        </MdsAlert>

        <section class="version-view__form" aria-label="The form as this version asked it">
            <PreviewRuntime
                :key="choices === null ? 'snapshot' : 'with-choices'"
                :snapshot="schema"
                :model="model"
                :issues-by-key="{}"
                :selected-key="null"
                :initial-step-key="null"
                read-only
            />
        </section>
    </div>
</template>

<style scoped>
.version-view {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-5);
    min-width: 0;
}

.version-view__form {
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-surface);
    min-width: 0;
}
</style>
