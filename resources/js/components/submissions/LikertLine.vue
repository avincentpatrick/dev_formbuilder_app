<script setup lang="ts">
/**
 * A Likert scale drawn as KoboToolbox's `likert` appearance draws one (M183, `R-f03cf999`): the points in equal
 * columns on ONE row that never wraps, the radios on a 3px line drawn through their middles — starting and ending at
 * the end radios — and each point's words centred beneath its radio. At every width; long words wrap inside their
 * own column. {@link MatrixGrid} draws a Likert statement the same way when its grid is cards.
 *
 * Native radios sharing the caller's `name` (one per rendered copy — `R-de624bb1`), so the arrow keys move along
 * the scale and nowhere else; each is labelled by its own words, and the caller's fieldset names the group. The
 * line is decoration: every radio is its own control with its own ring.
 */
interface Point {
    value: string;
    label: string;
}

defineProps<{
    name: string;
    options: Point[];
    modelValue: string;
    invalid?: boolean;
    describedby?: string;
}>();
const emit = defineEmits<{ 'update:modelValue': [value: string] }>();
</script>

<template>
    <div class="likert-line" :style="{ '--likert-points': String(Math.max(options.length, 1)) }" data-likert-line>
        <label
            v-for="opt in options"
            :key="opt.value"
            class="likert-line__point"
            :class="{ 'likert-line__point--active': modelValue === opt.value }"
        >
            <input
                type="radio"
                class="likert-line__radio"
                :name="name"
                :value="opt.value"
                :checked="modelValue === opt.value"
                :aria-invalid="invalid || undefined"
                :aria-describedby="describedby"
                @change="emit('update:modelValue', opt.value)"
            />
            <span class="likert-line__label">{{ opt.label }}</span>
        </label>
    </div>
</template>

<style scoped>
.likert-line {
    /* The radio's size, which the line is drawn through the middle of. */
    --likert-radio: 1.25rem;
    display: grid;
    grid-template-columns: repeat(var(--likert-points), minmax(0, 1fr));
    min-width: 0;
}

.likert-line__point {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--mds-space-1);
    min-width: 0;
    padding-inline: var(--mds-space-1);
    text-align: center;
    cursor: pointer;
}

/* The line, a segment per point: full width between two points, from the middle at either end. */
.likert-line__point::before {
    content: '';
    position: absolute;
    top: calc(var(--likert-radio) / 2 - 1.5px);
    inset-inline: 0;
    height: 3px;
    background-color: var(--mds-color-border-strong);
}

.likert-line__point:first-child::before {
    inset-inline-start: 50%;
}

.likert-line__point:last-child::before {
    inset-inline-end: 50%;
}

.likert-line__radio {
    position: relative;
    z-index: 1;
    width: var(--likert-radio);
    height: var(--likert-radio);
    margin: 0;
    accent-color: currentColor;
}

.likert-line__label {
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-caption-font-size);
    line-height: var(--mds-type-caption-line-height);
    color: var(--mds-color-text-secondary);
    overflow-wrap: anywhere;
}

.likert-line__point--active .likert-line__label {
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-body);
}
</style>
