<script setup lang="ts">
/**
 * The right panel's History tab — M188 (`R-3894369d`, `D167`, `D168`): a question's saved changes, newest first, and
 * **Put back**.
 *
 * The testers asked (2026-10-11, comment 5) for "a ledger/list/log of the nitty-gritty changes per field … an option to
 * revert … stat[ing] what the previous state was before applying reversal". Each entry says who, when, and every setting
 * it changed from what to what, in the panel's own words (`settingWords`, shared with the conflict dialog). Put back first
 * shows each setting as it is now and as it will be, then saves it as an ordinary edit (`store.putBack`), so Undo
 * reverses it. A type change and a move are listed and not put back (`D168`); each says what to do instead.
 *
 * Fetched when the tab opens and again after each save of the question, the latest read winning (the tab strip activates
 * on arrow keys, so a read must be allowed to land late — `PreviewPane`'s sequence guard).
 */
import { computed, onMounted, ref, watch } from 'vue';
import { MdsAlert, MdsButton, MdsEmptyState, MdsModal, MdsSpinner } from '@meridian/design-system';
import { settingWords, type DiffWords } from './conflict-diff';
import { absoluteTime, relativeTime } from '../notifications/relative-time';
import type { BuilderStore } from './useBuilderStore';
import type { FieldHistoryChange, FieldHistoryEntry, FieldHistoryRefusal, LocalField } from './types';

const props = defineProps<{ store: BuilderStore; field: LocalField; words: DiffWords }>();

const entries = ref<FieldHistoryEntry[]>([]);
const next = ref<string | null>(null);
const state = ref<'loading' | 'ready' | 'failed'>('loading');
const loadingOlder = ref(false);
let reads = 0;

async function load(): Promise<void> {
    const read = ++reads;
    const page = await props.store.historyFor(props.field.uid);
    if (read !== reads) return; // a later read is on its way: this one is stale
    if (page === null) {
        state.value = 'failed';
        return;
    }
    entries.value = page.data;
    next.value = page.next;
    state.value = 'ready';
}

async function loadOlder(): Promise<void> {
    if (next.value === null) return;
    loadingOlder.value = true;
    const read = reads;
    const page = await props.store.historyFor(props.field.uid, next.value);
    loadingOlder.value = false;
    if (read !== reads || page === null) return;
    entries.value = [...entries.value, ...page.data];
    next.value = page.next;
}

onMounted(load);
// A new save token is a new save: the history gained an entry. Another question selected is another history.
watch(() => [props.field.uid, props.field.version], () => void load());

function who(entry: FieldHistoryEntry): string {
    return entry.by ?? 'A former member';
}

function headline(entry: FieldHistoryEntry): string | null {
    switch (entry.kind) {
        case 'put_back':
            return entry.put_back_of_at ? `Put back the change of ${absoluteTime(entry.put_back_of_at)}` : 'Put back an earlier change';
        case 'type_changed':
            return 'Changed the question type';
        case 'moved':
            return 'Moved';
        default:
            return null;
    }
}

function lines(changes: readonly FieldHistoryChange[]): { setting: string; before: string; after: string }[] {
    return changes.map((change) => {
        if (change.too_large === true) return { setting: settingWords(change.setting, null, null, props.words).setting, before: 'Too large to keep', after: 'Too large to keep' };
        const words = settingWords(change.setting, change.before, change.after, props.words);

        return { setting: words.setting, before: words.a, after: words.b };
    });
}

function typeName(value: string): string {
    return props.words.typeLabel?.(value) ?? value;
}

/** Why an entry is not put back, or null. The question's type is read here too: it may have changed since the read. */
function refusal(entry: FieldHistoryEntry): FieldHistoryRefusal | null {
    if (entry.reason !== null) return entry.reason;

    return entry.field_type !== props.field.field_type ? 'other_type' : null;
}

function refusalWords(entry: FieldHistoryEntry, reason: FieldHistoryRefusal): string {
    switch (reason) {
        case 'type_changed':
            return 'A change of question type is not put back here: choose the type again under Basics → Question type.';
        case 'moved':
            return 'A move is not put back here: drag the question back to its place.';
        case 'other_type':
            return `Made while this was a ${typeName(entry.field_type)} question: change the type back first.`;
        case 'other_specify':
            return 'Turn “Other, please specify” on or off under Options instead.';
        default:
            return 'A setting was too large to keep in the history, so this change cannot be put back.';
    }
}

// ── Put back ─────────────────────────────────────────────────────────────
const confirming = ref<FieldHistoryEntry | null>(null);
const putting = ref(false);
const outcome = ref<'' | 'done' | 'failed'>('');

function current(setting: string): unknown {
    if (setting.startsWith('config.')) return (props.field.config as Record<string, unknown>)[setting.slice('config.'.length)] ?? null;

    return (props.field as unknown as Record<string, unknown>)[setting] ?? null;
}

/** Each setting the confirm dialog shows: as it is now, and as it will be once put back. */
const preview = computed(() =>
    (confirming.value?.changes ?? []).map((change) => {
        const words = settingWords(change.setting, current(change.setting), change.before, props.words);

        return { setting: words.setting, now: words.a, then: words.b, same: words.a === words.b };
    }),
);
const nothingToDo = computed(() => preview.value.length > 0 && preview.value.every((row) => row.same));

function ask(entry: FieldHistoryEntry): void {
    outcome.value = '';
    confirming.value = entry;
}

async function confirm(): Promise<void> {
    const entry = confirming.value;
    if (entry === null) return;
    putting.value = true;
    const held = await props.store.putBack(props.field.uid, entry.changes, entry.id);
    putting.value = false;
    confirming.value = null;
    outcome.value = held ? 'done' : 'failed';
    if (held) void load();
}
</script>

<template>
    <section class="history" aria-labelledby="config-history-title" data-field-history>
        <h3 id="config-history-title" class="history__title">Changes to this question</h3>
        <p class="history__intro">Newest first. Put back returns a change to how it was before it; Undo reverses a Put back.</p>

        <p v-if="outcome === 'done'" class="history__outcome" role="status" data-history-outcome="done">
            Put back. Undo reverses it.
        </p>
        <p v-else-if="outcome === 'failed'" class="history__outcome" role="status" data-history-outcome="failed">
            It was not put back — the message above says why.
        </p>

        <div v-if="state === 'loading'" class="history__loading" role="status">
            <MdsSpinner size="sm" label="Loading this question's history" />
            <span>Loading this question's history…</span>
        </div>

        <MdsAlert v-else-if="state === 'failed'" tone="danger" title="The history could not be loaded" message="Check your connection, then try again.">
            <template #actions>
                <MdsButton size="sm" variant="secondary" @click="load">Try again</MdsButton>
            </template>
        </MdsAlert>

        <MdsEmptyState
            v-else-if="entries.length === 0"
            headline="No changes kept yet"
            description="Changes saved to this question from now on appear here."
        />

        <template v-else>
            <ol class="history__list">
                <li v-for="entry in entries" :key="entry.id" class="history__item" :data-history-kind="entry.kind">
                    <!-- One line, so the spaces between the parts survive the template compiler and reach a screen reader. -->
                    <p class="history__meta"><strong class="history__who">{{ who(entry) }}</strong> <time class="history__when" :datetime="entry.at" :title="absoluteTime(entry.at)">{{ relativeTime(entry.at) }}</time></p>
                    <p v-if="headline(entry)" class="history__headline">{{ headline(entry) }}</p>
                    <ul class="history__changes">
                        <li v-for="line in lines(entry.changes)" :key="line.setting" class="history__change" data-history-change><span class="history__setting">{{ line.setting }}:</span> <span class="history__from">{{ line.before }}</span> <span class="history__arrow" aria-hidden="true">→</span> <span class="history__sr">changed to</span> <span class="history__to">{{ line.after }}</span></li>
                    </ul>
                    <MdsButton v-if="refusal(entry) === null" size="sm" variant="secondary" data-put-back @click="ask(entry)">Put back</MdsButton>
                    <p v-else class="history__refusal" data-history-refusal>{{ refusalWords(entry, refusal(entry)!) }}</p>
                </li>
            </ol>
            <MdsButton v-if="next !== null" size="sm" variant="tertiary" :loading="loadingOlder" @click="loadOlder">Show older changes</MdsButton>
        </template>

        <MdsModal :open="confirming !== null" size="lg" title="Put back this change?" close-label="Cancel" @close="confirming = null">
            <div class="history__confirm">
                <p class="history__lead">
                    These settings go back to how they were before this change. It is saved like any edit, so Undo reverses it.
                </p>
                <table class="history__table">
                    <caption class="history__caption">What changes</caption>
                    <thead>
                        <tr>
                            <th scope="col">Setting</th>
                            <th scope="col">Now</th>
                            <th scope="col">After putting back</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in preview" :key="row.setting" data-put-back-row>
                            <th scope="row">{{ row.setting }}</th>
                            <td>{{ row.now }}</td>
                            <td>{{ row.then }}</td>
                        </tr>
                    </tbody>
                </table>
                <p v-if="nothingToDo" class="history__same" data-put-back-same>This is already how the question is: nothing would change.</p>
            </div>
            <template #actions>
                <MdsButton variant="tertiary" @click="confirming = null">Cancel</MdsButton>
                <MdsButton variant="primary" :disabled="nothingToDo" :loading="putting" data-put-back-confirm @click="confirm">Put back</MdsButton>
            </template>
        </MdsModal>
    </section>
</template>

<style scoped>
.history {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
}

.history__title {
    margin: 0;
    font-size: var(--mds-type-label-font-size);
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-heading);
}

.history__intro,
.history__outcome,
.history__lead,
.history__same {
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.history__loading {
    display: flex;
    align-items: center;
    gap: var(--mds-space-2);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.history__list {
    display: grid;
    gap: var(--mds-space-2);
    margin: 0;
    padding: 0;
    list-style: none;
}

/* A sunken panel needs its own border to read as one in dark, where sunken resolves to the canvas (M174). */
.history__item {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: var(--mds-space-2);
    padding: var(--mds-space-2) var(--mds-space-3);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-sunken);
}

.history__meta {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: var(--mds-space-2);
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.history__who {
    color: var(--mds-color-text-body);
}

.history__headline {
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    font-style: italic;
    color: var(--mds-color-text-secondary);
}

.history__changes {
    display: grid;
    gap: var(--mds-space-1);
    margin: 0;
    padding: 0;
    list-style: none;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-body);
}

.history__change {
    overflow-wrap: anywhere;
}

.history__setting {
    font-weight: var(--mds-font-weight-semibold);
}

.history__arrow {
    color: var(--mds-color-text-secondary);
}

/* Visually hidden, still announced — there is no shared utility for it in this repository. */
.history__sr {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}

.history__refusal {
    margin: 0;
    font-size: var(--mds-type-caption-font-size);
    color: var(--mds-color-text-secondary);
}

.history__confirm {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-4);
}

.history__table {
    width: 100%;
    border-collapse: collapse;
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
}

.history__caption {
    margin-bottom: var(--mds-space-2);
    text-align: start;
    font-size: var(--mds-type-label-font-size);
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-secondary);
}

.history__table th,
.history__table td {
    padding: var(--mds-space-2) var(--mds-space-3);
    border-bottom: 1px solid var(--mds-color-border-default);
    text-align: start;
    vertical-align: top;
    overflow-wrap: anywhere;
}

.history__table thead th {
    font-size: var(--mds-type-label-font-size);
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-secondary);
}

.history__table tbody th {
    font-weight: var(--mds-font-weight-semibold);
}
</style>
