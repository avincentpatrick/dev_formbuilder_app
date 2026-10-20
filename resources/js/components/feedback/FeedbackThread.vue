<script setup lang="ts">
/**
 * A feedback report's thread, and the box to add to it (M179, `R-129c44c3`, `D151`).
 *
 * Anyone who may see a report may comment on it, at any time — `canComment` comes from the server's policy, so
 * this component never decides it. Each message names who wrote it: a member, the **Support team**, or a
 * **Former member** (an account since removed — never "nobody"). The message a tester's move wrote says so
 * ("said it works", "said it is still a problem").
 *
 * A picture attached to a comment is NAMED, never shown: ADR-0015 §D9 serves a feedback screenshot under
 * `feedback.view` on every route or on none, and most members do not hold it. The support team sees it.
 */
import { useForm } from '@inertiajs/vue3';
import { MdsButton, MdsFormField, MdsIcon, MdsTextarea } from '@meridian/design-system';
import type { FeedbackComment } from '@/components/feedback/types';

const props = defineProps<{
    reportId: string;
    comments: FeedbackComment[];
    canComment: boolean;
}>();

const form = useForm<{ body: string; screenshot: File | null }>({ body: '', screenshot: null });

function when(iso: string): string {
    return new Date(iso).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function said(comment: FeedbackComment): string | null {
    if (comment.action === 'confirmed') {
        return 'said it works';
    }

    return comment.action === 'reopened' ? 'said it is still a problem' : null;
}

function onPicked(event: Event): void {
    const input = event.target as HTMLInputElement;
    form.screenshot = input.files?.[0] ?? null;
}

function send(): void {
    if (form.body.trim() === '') {
        form.setError('body', 'Write a comment first.');

        return;
    }

    form.post(`/feedback/${props.reportId}/comments`, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <div class="fbt" data-feedback-thread>
        <ol v-if="comments.length" class="fbt__list">
            <li v-for="comment in comments" :key="comment.id" class="fbt__item" :data-author-kind="comment.author_kind">
                <p class="fbt__meta">
                    <strong class="fbt__author">{{ comment.author }}</strong>
                    <span v-if="said(comment)" class="fbt__said">{{ said(comment) }}</span>
                    <span class="fbt__when">{{ when(comment.created_at) }}</span>
                </p>
                <p v-if="comment.body" class="fbt__body">{{ comment.body }}</p>
                <p v-if="comment.has_screenshot" class="fbt__shot">
                    <MdsIcon name="image" size="sm" aria-hidden="true" />
                    Screenshot attached
                </p>
            </li>
        </ol>

        <form v-if="canComment" class="fbt__form" novalidate @submit.prevent="send">
            <MdsFormField label="Add a comment" :input-id="`fbt-body-${reportId}`" :error="form.errors.body ?? undefined">
                <MdsTextarea :id="`fbt-body-${reportId}`" v-model="form.body" :rows="2" maxlength="2000" autosize />
            </MdsFormField>

            <div class="fbt__row">
                <label class="fbt__file">
                    <span class="fbt__file-label">Attach an image (optional)</span>
                    <input type="file" accept="image/png,image/jpeg,image/webp" @change="onPicked" />
                </label>
                <MdsButton type="submit" variant="secondary" size="sm" :loading="form.processing">Send comment</MdsButton>
            </div>
        </form>
    </div>
</template>

<style scoped>
.fbt {
    margin-top: var(--mds-space-3);
}

.fbt__list {
    display: grid;
    gap: var(--mds-space-2);
    margin: 0 0 var(--mds-space-3);
    padding: 0;
    list-style: none;
}

/* A sunken panel needs its own border to read as one in dark, where sunken resolves to the canvas (M174). */
.fbt__item {
    padding: var(--mds-space-2) var(--mds-space-3);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-sunken);
}

.fbt__meta {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: var(--mds-space-2);
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.fbt__author {
    color: var(--mds-color-text-body);
}

.fbt__said {
    font-style: italic;
}

.fbt__body {
    margin: var(--mds-space-1) 0 0;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    color: var(--mds-color-text-body);
}

.fbt__shot {
    display: inline-flex;
    align-items: center;
    gap: var(--mds-space-1);
    margin: var(--mds-space-1) 0 0;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.fbt__form {
    display: grid;
    gap: var(--mds-space-2);
}

.fbt__row {
    display: flex;
    flex-wrap: wrap;
    align-items: end;
    justify-content: space-between;
    gap: var(--mds-space-2);
}

.fbt__file {
    display: grid;
    gap: var(--mds-space-1);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.fbt__file-label {
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-body);
}
</style>
