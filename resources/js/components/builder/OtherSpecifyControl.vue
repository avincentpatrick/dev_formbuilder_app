<script setup lang="ts">
/**
 * "Add “Other, please specify”" — the Options tab's switch (M177, `R-71509975`). The user (2026-10-16): a choice question
 * gets a setting that adds an Other choice and a Please specify question shown only when it is picked. On, the server
 * makes both in one call and the store places the question right after this one; off removes both, asking first when
 * responses answered the Please specify question (`D133`, the delete dialog).
 *
 * Which types offer it is TRANSMITTED (`enums.other_specify_types`); a question taking its choices from another form
 * has none typed here to add Other to, so it is not offered. The switch reads on while the link names a question that
 * exists — a link left naming nothing reads off, and turning it on makes a new pair.
 *
 * ⚠️ `renderKey` re-mounts the switch after every action: the native box is checked the moment it is clicked, and a
 * cancelled dialog or a refused request would otherwise leave it showing a state the question does not have.
 */
import { computed, ref, useId } from 'vue';
import { MdsSwitch } from '@meridian/design-system';
import DeleteFieldDialog from './DeleteFieldDialog.vue';
import { otherSpecifyChild } from './other-specify';
import { useAnsweredDelete } from './useAnsweredDelete';
import type { BuilderStore } from './useBuilderStore';

const props = defineProps<{ store: BuilderStore }>();

const field = props.store.selectedField;
const helpId = useId();
const renderKey = ref(0);
const confirmation = useAnsweredDelete(props.store);
const pending = confirmation.pending;

const offered = computed(() => {
    const current = field.value;

    return current !== null && (props.store.enums.other_specify_types ?? []).includes(current.field_type) && current.config.options_source == null;
});
const child = computed(() => (field.value ? otherSpecifyChild(field.value, props.store.fields.value) : undefined));

async function toggle(next: boolean): Promise<void> {
    const current = field.value;
    if (!current) return;
    if (next) {
        await props.store.addOtherSpecify(current.uid);
    } else {
        const question = child.value;
        if (question) await confirmation.request(question.uid, question.label || question.key, () => void props.store.removeOtherSpecify(current.uid));
    }
    renderKey.value++;
}

function cancel(): void {
    confirmation.cancel();
    renderKey.value++;
}
</script>

<template>
    <div v-if="offered" class="other-specify" data-other-specify>
        <MdsSwitch :key="renderKey" :model-value="child !== undefined" label="Add “Other, please specify”" :describedby="helpId" @update:model-value="toggle" />
        <p :id="helpId" class="other-specify__help">
            Adds the choice “Other” and a question “Please specify” right after this one. Respondents see it only when they pick
            Other, and must answer it then.
        </p>
        <DeleteFieldDialog
            :open="pending !== null"
            :label="pending?.label ?? ''"
            :count="pending?.count ?? null"
            @confirm="confirmation.confirm"
            @cancel="cancel"
        />
    </div>
</template>

<style scoped>
.other-specify {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-1);
    padding-top: var(--mds-space-3);
    border-top: 1px solid var(--mds-color-border-default);
}

.other-specify__help {
    margin: 0;
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
}
</style>
