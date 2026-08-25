<script setup lang="ts">
/**
 * "Save and finish later" (Increment H10, UX §5.2) — the explicit durable-save affordance, distinct from the
 * ambient device-local autosave that keeps running underneath. Self-hiding: it injects the draft flow and
 * renders nothing when the form has save-and-resume disabled (or the tenant plan lacks it), so the fill views
 * can drop it in unconditionally.
 *
 * On open it upserts the server draft to mint a resume link, then offers a copyable link and an optional "email
 * me the link" action (which re-saves with `finish_later` so the backend queues the resume email). A version
 * drift during the save routes the whole session into the reschema remount (saveDraft returns null) and this
 * component unmounts — so the null branch just closes.
 *
 * ⚠️ INCREMENT M14 — `null` NOW MEANS *ONLY* "THE SESSION IS REMOUNTING", WHICH IS WHAT MAKES CLOSING SILENTLY
 * CORRECT. It used to mean that OR "a 409 nobody handled", because `RuntimeSession` folded every 409 into the
 * one drift branch — so a "Save and finish later" refused for a stale baseline produced no save, no resume
 * link and no message, and the remount then minted a SECOND server draft with a second emailed link. Every
 * refusal that does not remount is now thrown, and both catches below say what happened.
 */
import { ref } from 'vue';
import { ApiError } from '../lib/api-client';
import { MdsButton, MdsFormField, MdsModal, MdsSpinner, MdsTextInput } from '@meridian/design-system';
import { useDraftFlow } from '../composables/context';

const draft = useDraftFlow();

const open = ref(false);
const phase = ref<'saving' | 'ready' | 'error'>('saving');
const resumeUrl = ref('');
const email = ref('');
const emailed = ref(false);
const emailing = ref(false);
const copied = ref(false);
const errorMessage = ref('');

async function onOpen(): Promise<void> {
    if (draft === null) {
        return;
    }
    open.value = true;
    phase.value = 'saving';
    emailed.value = false;
    copied.value = false;
    errorMessage.value = '';
    try {
        const result = await draft.saveDraft({ finishLater: false });
        if (result === null) {
            // A version drift took over (session is remounting) — nothing to show.
            open.value = false;
            return;
        }
        resumeUrl.value = result.resumeUrl;
        phase.value = 'ready';
    } catch (error) {
        phase.value = 'error';
        // ⚠️ INCREMENT M14 — THE ERROR IS BOUND NOW, AND THAT IS THE POINT. This `catch` took no argument, so
        // every refusal the session did not absorb collapsed to one sentence that names no cause and no
        // remedy. The server's own sentence is the only thing that tells a `draft_already_finalized` apart
        // from a `submission_conflict` — `SubmissionConflictException` records that the message is all that
        // separates two of its causes on the wire — so it is shown verbatim when there is one.
        errorMessage.value =
            error instanceof ApiError ? error.normalized.message : 'We couldn’t save your progress just now. Please try again.';
    }
}

async function onEmail(): Promise<void> {
    if (draft === null || email.value.trim() === '') {
        return;
    }
    emailing.value = true;
    // Increment M14 — clear the previous attempt's sentence, now that one can actually be on screen: a
    // successful retry that left the old refusal showing would be its own small lie.
    errorMessage.value = '';
    try {
        const result = await draft.saveDraft({ email: email.value.trim(), finishLater: true });
        if (result !== null) {
            emailed.value = result.emailed;
        }
    } catch (error) {
        // Increment M14 — the same binding, for the same reason. This arm keeps naming the copy button as the
        // way out, because unlike `onOpen` a link is already on screen here and still works.
        errorMessage.value =
            error instanceof ApiError
                ? `${error.normalized.message} You can still copy the link above.`
                : 'We couldn’t email the link. You can still copy it above.';
    } finally {
        emailing.value = false;
    }
}

async function onCopy(): Promise<void> {
    try {
        await navigator.clipboard?.writeText(resumeUrl.value);
        copied.value = true;
    } catch {
        copied.value = false;
    }
}
</script>

<template>
    <MdsButton v-if="draft !== null" type="button" variant="tertiary" @click="onOpen">
        Save and finish later
    </MdsButton>

    <MdsModal :open="open" title="Save and finish later" @close="open = false">
        <div class="save-later">
            <div v-if="phase === 'saving'" class="save-later__loading">
                <MdsSpinner size="md" label="Saving your progress" />
            </div>

            <template v-else-if="phase === 'ready'">
                <p class="save-later__lead">
                    Your progress is saved. Use this link to pick up where you left off — it works on any device.
                </p>
                <MdsFormField label="Your resume link">
                    <template #default="{ id, describedby }">
                        <MdsTextInput
                            :id="id"
                            :model-value="resumeUrl"
                            :describedby="describedby"
                            readonly
                            @focus="($event.target as HTMLInputElement).select()"
                        />
                    </template>
                </MdsFormField>
                <div class="save-later__row">
                    <MdsButton type="button" variant="secondary" @click="onCopy">
                        {{ copied ? 'Copied' : 'Copy link' }}
                    </MdsButton>
                </div>

                <hr class="save-later__divider" />

                <p v-if="emailed" class="save-later__sent" role="status">
                    We’ve emailed the link to {{ email }}.
                </p>
                <template v-else>
                    <MdsFormField label="Email me the link (optional)" help="We’ll send the resume link to this address.">
                        <template #default="{ id, describedby }">
                            <MdsTextInput
                                :id="id"
                                v-model="email"
                                type="email"
                                autocomplete="email"
                                placeholder="you@example.com"
                                :describedby="describedby"
                            />
                        </template>
                    </MdsFormField>
                    <div class="save-later__row">
                        <MdsButton
                            type="button"
                            variant="secondary"
                            :disabled="email.trim() === ''"
                            :loading="emailing"
                            @click="onEmail"
                        >
                            Email me the link
                        </MdsButton>
                    </div>
                </template>

                <!--
                    ⚠️ Increment M14 — WITHOUT THIS THE EMAIL ARM'S MESSAGE HAD NOWHERE TO RENDER, WHICH IS A
                    SHARPER VERSION OF THE SWALLOW THE BACKLOG ROW NAMES. `onEmail` only runs while `phase`
                    is `ready`, and the error paragraph below is the `v-else` of that same branch — so the
                    sentence it set was unreachable markup, and a failed "Email me the link" left the panel
                    looking exactly as it did before the click. The pre-M14 assignment was dead code.
                -->
                <p v-if="errorMessage !== ''" class="save-later__error" role="alert">{{ errorMessage }}</p>
            </template>

            <p v-else class="save-later__error" role="alert">{{ errorMessage }}</p>
        </div>

        <template #actions>
            <MdsButton type="button" @click="open = false">Done</MdsButton>
        </template>
    </MdsModal>
</template>

<style scoped>
.save-later {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-4);
}

.save-later__loading {
    display: flex;
    justify-content: center;
    padding: var(--mds-space-6);
}

.save-later__lead,
.save-later__sent {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
    color: var(--mds-color-text-body);
}

.save-later__row {
    display: flex;
    justify-content: flex-start;
}

.save-later__divider {
    margin: 0;
    border: 0;
    border-top: 1px solid var(--mds-color-border-default);
}

.save-later__error {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-md-font-size);
    color: var(--mds-color-danger-text);
}
</style>
