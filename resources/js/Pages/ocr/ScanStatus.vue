<script setup lang="ts">
/**
 * A scan that is not ready to review (M129 — single-form OCR groundwork 2): still being read, or unreadable.
 *
 * WHILE IT IS READ, this page asks the scan's JSON route how reading is going, every few seconds, and reloads
 * itself once reading has ended — the server then renders the review screen, or the failure below. It never
 * decides what the scan shows: a terminal state is the server's to render, so a reload is all it does.
 *
 * ⚠️ THE POLL STOPS WHEN THE PAGE GOES. A timer that outlives its page keeps calling the server from a screen
 * nobody is on, and every navigation would add another — `useMemberStreak` records exactly that leak, measured
 * at forty requests per navigation after forty visits.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { MdsAlert, MdsBreadcrumb, MdsCard, MdsSpinner, type BreadcrumbItem } from '@meridian/design-system';
import PageHeader from '@/components/shell/PageHeader.vue';

const props = defineProps<{
    form: { id: string; title: string };
    scan: {
        id: string;
        /** `queued`, `reading` or `failed` — a read scan renders the review screen instead of this page. */
        status: string;
        status_label: string;
        pages: number;
        error_message: string | null;
        /** The scan's JSON status route. */
        poll_url: string;
    };
    /** Manual entry for this form: the way forward when a scan cannot be read. */
    encode_url: string;
    scans_url: string;
    crumbs: BreadcrumbItem[];
}>();

/** How often the page asks. Reading one page is one provider call, so seconds, not milliseconds. */
const POLL_MS = 2500;

/** After this many failed checks in a row, say so rather than spin for ever. */
const MAX_FAILED_CHECKS = 3;

const failed = computed(() => props.scan.status === 'failed');
const checkFailed = ref(false);

let timer: ReturnType<typeof setTimeout> | null = null;
let failures = 0;
let stopped = false;

async function check(): Promise<void> {
    timer = null;
    if (stopped) {
        return;
    }

    try {
        const response = await fetch(props.scan.poll_url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        const body = (await response.json()) as { data?: { status?: string } };
        if (!response.ok) {
            throw new Error(`status ${response.status}`);
        }
        failures = 0;
        checkFailed.value = false;

        const status = body.data?.status;
        if (status === 'read' || status === 'failed') {
            stopped = true;
            router.reload();
            return;
        }
    } catch {
        failures += 1;
        if (failures >= MAX_FAILED_CHECKS) {
            checkFailed.value = true;
        }
    }

    if (!stopped) {
        timer = setTimeout(check, POLL_MS);
    }
}

onMounted(() => {
    if (!failed.value) {
        timer = setTimeout(check, POLL_MS);
    }
});

onBeforeUnmount(() => {
    stopped = true;
    if (timer !== null) {
        clearTimeout(timer);
        timer = null;
    }
});
</script>

<template>
    <div class="scan-status">
        <Head :title="`Reading a scan — ${form.title}`" />

        <PageHeader :title="failed ? 'This scan could not be read' : 'Reading the scan'" icon="image">
            <template #breadcrumbs>
                <MdsBreadcrumb :items="crumbs" :link-component="Link" />
            </template>
        </PageHeader>

        <MdsAlert v-if="failed" tone="danger" title="Nothing was saved from this scan" :message="scan.error_message ?? undefined">
            <template #actions>
                <Link :href="encode_url" class="scan-status__link">Enter the response by hand</Link>
                <Link :href="scans_url" class="scan-status__link">Back to scanned forms</Link>
            </template>
        </MdsAlert>

        <MdsCard v-else>
            <div class="scan-status__reading">
                <MdsSpinner label="Reading the scan" />
                <!-- Present from first paint and changed in place, so a screen reader is already observing it. -->
                <p class="scan-status__text" role="status" aria-live="polite">
                    {{ scan.status_label }} — {{ scan.pages }} {{ scan.pages === 1 ? 'page' : 'pages' }}. This page
                    opens the review by itself when the reading is done.
                </p>
            </div>
            <p v-if="checkFailed" class="scan-status__check" role="alert">
                The progress of this scan could not be checked. Reload the page to try again.
            </p>
        </MdsCard>
    </div>
</template>

<style scoped>
.scan-status {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-4);
    max-width: 720px;
}

.scan-status__reading {
    display: flex;
    align-items: center;
    gap: var(--mds-space-3);
}

.scan-status__text {
    margin: 0;
}

.scan-status__check {
    margin: var(--mds-space-3) 0 0;
    color: var(--mds-color-danger-text);
    font-size: var(--mds-type-body-sm-font-size);
}

.scan-status__link {
    color: var(--mds-color-action-primary-fg);
    font-weight: var(--mds-type-label-font-weight);
}
</style>
