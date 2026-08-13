<script setup lang="ts">
/**
 * The live password-requirement checklist (Increment J3b, PRD Feature #14 / the user's 2026-08-09
 * password-policy decision of record).
 *
 * ── IT RENDERS THE SERVER'S LIST, AND THAT IS THE WHOLE DESIGN ─────────────────────────────────────────
 * `requirements` comes from `App\Support\Auth\PasswordPolicy::requirements()` over the wire — label AND
 * a JavaScript regular-expression SOURCE per rule. Nothing about the policy is written down here, so a
 * rule added on the server appears in this checklist with no TypeScript edit at all, and the checklist
 * cannot disagree with the validator that will judge the password a moment later. The failure that
 * prevents is specific and lands on the person choosing the password: four green ticks over a form that
 * then rejects them.
 *
 * ── ⚠️ A NULL PATTERN IS A RULE THIS BROWSER MUST NOT EVALUATE, NOT A RULE TO HIDE ─────────────────────
 * `uncompromised` ships with `pattern: null` deliberately. It is a k-anonymity range query against
 * api.pwnedpasswords.com, and evaluating it here would leak the SHA-1 prefix of a password being typed,
 * per keystroke, to a third party from the user's own IP. Dropping the row instead would make the
 * checklist lie by omission — a person with every tick green would still be rejected and be told nothing
 * about why. So it renders as a STATED CONDITION: named, never ticked, never counted in the summary.
 * **Never give it a pattern to make the row "work"** — `PasswordPolicyTest` fails on purpose if anyone
 * does, and `password-policy.test.ts` is the browser half of that same claim.
 *
 * A pattern that fails to COMPILE degrades to the same stated-condition rendering. Not a thrown error
 * (this is a live-typing surface, and a render that throws takes the sign-up page with it) and not a
 * silent drop or a false tick — the row stays visible and stops claiming to know the answer.
 *
 * ── WHY THIS LIVES IN THE PACKAGE AND NOT IN `resources/js` ────────────────────────────────────────────
 * Storybook globs `packages/design-system/src/**` only, so an app-tree component gets no story and no
 * `checkA11y` scan at all — the `MdsFilterBar` precedent recorded at `src/index.ts`. A component whose
 * entire job is announcing state changes to somebody who cannot see them is the last one that should
 * sit outside the accessibility gate.
 */
import { computed } from 'vue';
import Icon from '../Icon/Icon.vue';
import { passwordStrengthId } from './describedby';

/** One row of the published policy. Mirrors PasswordPolicy::requirements()'s element shape exactly. */
export interface PasswordRequirement {
    key: string;
    label: string;
    /** A JavaScript regular-expression source, or null for a rule only the server can decide. */
    pattern: string | null;
}

type RowState = 'met' | 'unmet' | 'server';

const props = withDefaults(
    defineProps<{
        password: string;
        requirements: PasswordRequirement[];
        /**
         * The id of the password input this describes. The list is exposed as `${inputId}-strength` so a
         * CALL SITE can concatenate it into the input's `aria-describedby` — `MdsFormField` composes that
         * attribute from help + error only, and widening it for one consumer would put a live checklist
         * into the description of every field in the product.
         */
        inputId?: string;
    }>(),
    { inputId: undefined },
);

// Through the shared helper, not a second template literal: this id and the `aria-describedby` the call
// sites build must be the same string, and a dangling idref is silent in every engine.
const listId = computed(() => (props.inputId ? passwordStrengthId(props.inputId) : undefined));

/**
 * Compile once per policy change rather than per keystroke. `u` matches the flag `PasswordPolicy`
 * documents, and it is load-bearing: the patterns use `\p{Ll}`/`\p{Lu}`/`\p{N}` Unicode property
 * escapes, which are a syntax error without it — so a missing flag would fail closed into the
 * stated-condition branch and every row would quietly stop ticking.
 */
const compiled = computed(() =>
    props.requirements.map((requirement) => {
        if (requirement.pattern === null) {
            return { ...requirement, expression: null };
        }

        try {
            return { ...requirement, expression: new RegExp(requirement.pattern, 'u') };
        } catch {
            return { ...requirement, expression: null };
        }
    }),
);

const rows = computed(() =>
    compiled.value.map((requirement) => ({
        key: requirement.key,
        label: requirement.label,
        state: (requirement.expression === null
            ? 'server'
            : requirement.expression.test(props.password) ? 'met' : 'unmet') as RowState,
    })),
);

/** Only the rows this browser can decide are counted — see the null-pattern note above. */
const tickable = computed(() => rows.value.filter((row) => row.state !== 'server'));
const metCount = computed(() => tickable.value.filter((row) => row.state === 'met').length);

/**
 * ⚠️ NATURALLY DEBOUNCED, WHICH IS WHY THERE IS NO TIMER. The live region's text node only changes when
 * the COUNT changes, so typing four more characters inside one state re-renders nothing and announces
 * nothing. A summary that included the password length, or the row labels, would fire on every keystroke
 * and make the field unusable with a screen reader.
 */
const summary = computed(() => `${metCount.value} of ${tickable.value.length} password requirements met`);

/** The per-row state word. Visually carried by the glyph; this is the same information for a reader. */
function stateLabel(state: RowState): string {
    if (state === 'met') {
        return 'Met:';
    }

    return state === 'server' ? 'Checked when you submit:' : 'Not yet met:';
}
</script>

<template>
    <div class="mds-pw">
        <ul :id="listId" class="mds-pw__list">
            <li
                v-for="row in rows"
                :key="row.key"
                class="mds-pw__row"
                :class="`mds-pw__row--${row.state}`"
            >
                <!-- The GLYPH is the signifier, never the colour alone (WCAG 1.4.1). `check` is the only
                     one of these the icon registry carries, and the unmet/pending mark is drawn in CSS
                     rather than added to the registry: a glyph there must be distinguishable from every
                     other at sidebar-rail size, which a bare ring is not. -->
                <Icon
                    v-if="row.state === 'met'"
                    name="check"
                    size="sm"
                    class="mds-pw__mark"
                    aria-hidden="true"
                />
                <span v-else class="mds-pw__mark mds-pw__mark--pending" aria-hidden="true" />

                <span class="mds-pw__text">
                    <span class="mds-pw__sr">{{ stateLabel(row.state) }}</span>
                    {{ row.label }}
                </span>
            </li>
        </ul>

        <p class="mds-pw__status" role="status">{{ summary }}</p>
    </div>
</template>

<style scoped>
/* ⚠️ `position: relative` IS LOAD-BEARING, NOT DECORATIVE — THIS IS THE THIRD TIME THIS REPOSITORY HAS
   HIT THE SAME DEFECT, AND NOTHING IN IT CAN SEE THE BUG. `.mds-pw__sr` and `.mds-pw__status` below are
   both the `position: absolute` + `clip: rect(0 0 0 0)` visually-hidden pattern. Absolute positioning
   resolves against the nearest POSITIONED ancestor, so without this line their containing block is
   whatever happens to be positioned further up — and no scroll container between this component and the
   document can then clip them. A 1px hidden node parked past a viewport edge extends the DOCUMENT's
   scrollable box.

   G11 found it horizontally on `MdsDataTable`, whose own comment already called it "a latent bug in this
   component"; JR5 found it vertically on `MdsSegmentedControl`, where a hidden `<legend>` sat 73px below
   the workspace and the page gained 73px of real scroll. It was latent in both for the same reason: it
   only becomes visible where an ancestor finally tries to clip.

   ⚠️ NO GATE HERE CAN EXECUTE THE CHECK. happy-dom computes no layout, so a mounted assertion would pass
   whatever the CSS said; the e2e overflow assertion reads `documentElement.scrollWidth`, which
   `.app-shell { overflow-x: clip }` pins flat on every authenticated page; and axe has no rule for it.
   Hence the source-text assertion in PasswordStrength.test.ts. */
.mds-pw {
    position: relative;
    margin-top: var(--mds-space-2);
}

.mds-pw__list {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-1);
    margin: 0;
    padding: 0;
    list-style: none;
}

.mds-pw__row {
    display: flex;
    align-items: flex-start;
    gap: var(--mds-space-2);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-text-secondary);
}

/* `-fg`, never `-bg`: this is ink on the page ground, and the `-bg` half of a status pair is a fill that
   assumes its own foreground (the J2a WCAG 1.4.11 finding, and the standing rule in the JR block). */
.mds-pw__row--met {
    color: var(--mds-color-status-success-fg);
}

.mds-pw__mark {
    flex-shrink: 0;
    /* Aligns the glyph to the first line's cap height rather than its box, so a label that wraps to two
       lines keeps its mark beside the FIRST line. */
    margin-top: 0.15em;
}

/* ⚠️ 16px, NOT `1em`, AND THE DIFFERENCE IS VISIBLE ON EVERY KEYSTROKE. `MdsIcon`'s `sm` is a fixed
   16×16 box, while `1em` here resolves against `body-sm` (13px). A ring that is 3px narrower than the
   check that replaces it makes every label in the list shift sideways as a rule flips — on a control
   whose whole purpose is to change state while somebody is typing and watching it. Matched exactly, and
   fixed rather than relative for the same reason the icon is: both must stay identical at every
   `[data-font-size]` setting. */
.mds-pw__mark--pending {
    width: 16px;
    height: 16px;
    border: 1.5px solid currentColor;
    border-radius: var(--mds-radius-full);
    opacity: 0.55;
}

.mds-pw__text {
    min-width: 0;
    /* A policy label is authored server-side and is ordinary English, but the container is ~360px inside
       a split panel at the extra_large type scale — the Domains-page lesson, applied before it bites. */
    overflow-wrap: anywhere;
}

/* Visually hidden, still announced. There is no shared `.sr-only` in this repository — every component
   hand-rolls this exact block (see SegmentedControl, DataTable, BarChart, Checkbox, Switch, Spinner),
   and `display: none` is not a substitute: it removes the text from the accessibility tree entirely. */
.mds-pw__sr,
.mds-pw__status {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
    border: 0;
}
</style>
