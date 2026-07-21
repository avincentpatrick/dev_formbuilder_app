<script setup lang="ts">
/**
 * Form-template gallery (Increment G9a). The onboarding "start from a template" surface (onboarding-content
 * -plan §2): a card grid of the platform templates + the tenant's own saved templates, each instantiated into
 * a brand-new form via a clone of its schema_blueprint (never a live reference). Assembled from shared
 * design-system components; the only page-local CSS is grid layout. A11y: the grid is a labelled list of
 * article cards, each with a single primary action.
 */
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { MdsBadge, MdsButton, MdsCard, MdsEmptyState } from '@meridian/design-system';
import PageHeader from '@/components/shell/PageHeader.vue';

type TemplateCard = {
    id: string;
    name: string;
    description: string | null;
    category: string | null;
    field_count: number;
    usage_count: number;
    is_platform: boolean;
    can: { use: boolean };
};

defineProps<{ templates: TemplateCard[] }>();

const instantiatingId = ref<string | null>(null);

function useTemplate(template: TemplateCard): void {
    if (instantiatingId.value) return;
    instantiatingId.value = template.id;
    router.post(
        `/forms/templates/${template.id}/instantiate`,
        {},
        {
            onFinish: () => {
                instantiatingId.value = null;
            },
        },
    );
}
</script>

<template>
    <div>
        <Head title="Templates" />

        <PageHeader title="Templates" icon="layout">
            <template #actions>
                <MdsButton variant="tertiary" icon-left="chevron-left" @click="router.visit('/forms')">
                    Back to forms
                </MdsButton>
            </template>
        </PageHeader>

        <ul v-if="templates.length" class="templates__grid" aria-label="Form templates">
            <li v-for="template in templates" :key="template.id" class="templates__cell">
                <MdsCard>
                    <template #header>
                        <div class="templates__meta">
                            <MdsBadge v-if="template.category" :label="template.category" variant="neutral" />
                            <MdsBadge v-if="template.is_platform" label="Meridian" variant="info" />
                        </div>
                        <h2 class="templates__name">{{ template.name }}</h2>
                    </template>

                    <p v-if="template.description" class="templates__desc">{{ template.description }}</p>

                    <p class="templates__stats">
                        {{ template.field_count }} {{ template.field_count === 1 ? 'question' : 'questions' }}
                        <span v-if="template.usage_count > 0" class="templates__dot">·</span>
                        <span v-if="template.usage_count > 0">used {{ template.usage_count }}×</span>
                    </p>

                    <template #footer>
                        <MdsButton
                            v-if="template.can.use"
                            variant="primary"
                            icon-left="plus"
                            :loading="instantiatingId === template.id"
                            :disabled="instantiatingId !== null && instantiatingId !== template.id"
                            @click="useTemplate(template)"
                        >
                            Use this template
                        </MdsButton>
                    </template>
                </MdsCard>
            </li>
        </ul>

        <MdsEmptyState
            v-else
            headline="No templates yet"
            description="Templates you save from a form will appear here, alongside Meridian's starter gallery."
        >
            <template #action>
                <Link href="/forms">
                    <MdsButton variant="primary" icon-left="chevron-left">Back to forms</MdsButton>
                </Link>
            </template>
        </MdsEmptyState>
    </div>
</template>

<style scoped>
.templates__grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: var(--mds-space-4);
    margin: 0;
    padding: 0;
    list-style: none;
}

.templates__cell {
    display: flex;
}

.templates__cell :deep(.mds-card) {
    display: flex;
    flex-direction: column;
    width: 100%;
}

.templates__meta {
    display: inline-flex;
    gap: var(--mds-space-2);
    margin-bottom: var(--mds-space-2);
}

.templates__name {
    margin: 0;
    font-size: var(--mds-type-heading-4-font-size);
    line-height: var(--mds-type-heading-4-line-height);
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-heading);
}

.templates__desc {
    margin: 0;
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
    color: var(--mds-color-text-body);
}

.templates__stats {
    margin: var(--mds-space-3) 0 0;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.templates__dot {
    margin: 0 var(--mds-space-1);
}
</style>
