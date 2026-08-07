<script setup lang="ts">
/**
 * The Share panel (Increment I1) — PRD Feature #3's missing half, and the PRD:386 fast-follow ("copy-link +
 * QR + embed snippet + social") in one surface.
 *
 * Until this shipped, `forms.public_slug` and `forms.allow_guest_submissions` had no UI writer at all: a
 * tenant could build and publish a form and still had no way to hand it to a respondent. Everything here
 * exists to answer one question honestly — "what is the link, and does it work right now?"
 *
 * ── THE URL IS A PROP, NEVER DERIVED ──────────────────────────────────────────────────────────────
 * `share.public_url` / `public_host` come from TenantUrl's PUBLIC arm on the server. Composing them here
 * from `window.location` would emit the ADMIN host, and a Business tenant on a custom domain would copy a
 * link, print it on a flyer, and find it 404s — custom domains serve the guest runtime only (ADR-0012 §D1).
 * The slug preview below concatenates a server-supplied host with the value being typed; it never invents
 * the host half.
 *
 * ── SAYING "THIS DOES NOT WORK YET" IS THE FEATURE ────────────────────────────────────────────────
 * GuestFormController answers 404 to all three of "no such slug", "guest access off" and "not published",
 * deliberately, so a slug-prober cannot tell them apart. The author is owed the opposite. When the form is
 * unpublished or guest access is off, this panel refuses to show a live link and says which gate is shut —
 * a copyable URL that 404s is worse than no URL, because it looks like it worked.
 *
 * The clipboard write follows DnsRecordBlock.vue: optional-chained (`navigator.clipboard` is undefined on
 * insecure origins, which is how local dev is served), the flag flips only on success, and a polite live
 * region announces it — a screen-reader user who has moved focus on never hears the button's label change.
 */
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import {
    MdsButton,
    MdsCheckbox,
    MdsFormField,
    MdsIcon,
    MdsIconButton,
    MdsModal,
    MdsNumberInput,
    MdsTextInput,
} from '@meridian/design-system';
import type { ShareProps } from '@/components/builder/types';

const props = defineProps<{
    open: boolean;
    formId: string;
    formTitle: string;
    share: ShareProps;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const form = useForm<{
    public_slug: string;
    allow_guest_submissions: boolean;
    // I8b — held as a BOOLEAN and mapped to the enum in transform(), because the control is one checkbox
    // and binding a checkbox straight to a two-member string union is how a third member later arrives as
    // a silent `true`. ⚠️ The KEY still has to be `bot_challenge`: Inertia types `form.errors` off the form
    // data, and the server keys its validation errors by the wire name — so a local alias would leave the
    // field's error unreachable and the control would silently never show one.
    bot_challenge: boolean;
    // null is "no per-form ceiling". MdsNumberInput already emits `number | null` and does the ''→null
    // conversion, so nothing here has to parse a string.
    guest_rate_limit_per_minute: number | null;
}>({
    public_slug: props.share.public_slug ?? '',
    allow_guest_submissions: props.share.allow_guest_submissions,
    bot_challenge: props.share.bot_challenge === 'proof_of_work',
    guest_rate_limit_per_minute: props.share.guest_rate_limit_per_minute,
});

/** Which block was copied last, so exactly one confirmation shows. */
const copied = ref<string | null>(null);

/**
 * Re-seed from props whenever the modal opens. The controller's back() redirect refreshes the builder props
 * after every save, so this always reflects what is actually stored — the ScheduleModal contract.
 */
watch(
    () => props.open,
    (open) => {
        if (!open) return;
        form.public_slug = props.share.public_slug ?? '';
        form.allow_guest_submissions = props.share.allow_guest_submissions;
        form.bot_challenge = props.share.bot_challenge === 'proof_of_work';
        form.guest_rate_limit_per_minute = props.share.guest_rate_limit_per_minute;
        form.clearErrors();
        copied.value = null;
    },
    { immediate: true },
);

/** The saved link, and only when every gate that the runtime checks is actually open. */
const liveUrl = computed<string | null>(() =>
    props.share.is_published && props.share.allow_guest_submissions ? props.share.public_url : null,
);

/**
 * The link as it would be after saving what is currently typed — so the author sees the URL they are about
 * to create, not the one they had. Only the PATH half is local; the host is always the server's.
 *
 * Suppressed once it would merely restate the live link below it. A saved, working form otherwise shows the
 * same URL twice under two different headings, which reads as two different addresses at a glance — the
 * class of on-screen contradiction that no gate catches and only looking finds.
 */
const previewUrl = computed<string | null>(() => {
    if (form.public_slug === '') return null;
    if (liveUrl.value !== null && form.public_slug === props.share.public_slug) return null;

    return `https://${props.share.public_host}/f/${form.public_slug}`;
});

/** Why there is no live link, or null when there is one. Ordered by what the author must fix first. */
const blockedReason = computed<string | null>(() => {
    if (props.share.public_slug === null) return 'This form has no link yet. Choose a link name below and save.';
    if (!props.share.is_published) {
        return 'This form has no published version yet, so the link will not open. Publish it to make the link live.';
    }
    if (!props.share.allow_guest_submissions) {
        return 'Guest responses are turned off, so the link will not open. Turn them on below to make it live.';
    }
    return null;
});

/** A rename breaks links already handed out — worth saying BEFORE the save, not in the toast after it. */
const renameWarning = computed<boolean>(
    () => props.share.public_slug !== null && form.public_slug !== props.share.public_slug,
);

/**
 * Escape for an HTML *attribute value* — the context this snippet is pasted into.
 *
 * Not paranoia about our own data: the form title is tenant-authored text, and the snippet is copied into
 * SOMEONE ELSE'S page. A title containing a quote would break the tag; a title chosen deliberately could
 * close the attribute and add its own, which in a multi-member workspace makes one member's title an
 * injection into another member's website. Doc #26's rule is exactly this — escape at render time, in the
 * context being rendered, never once at store.
 */
function attr(value: string): string {
    return value
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

const embedSnippet = computed<string>(() =>
    liveUrl.value === null
        ? ''
        : `<iframe src="${attr(liveUrl.value)}" title="${attr(props.formTitle)}" width="100%" height="700" style="border:0" loading="lazy"></iframe>`,
);

const mailtoHref = computed<string>(() => {
    const subject = encodeURIComponent(props.formTitle);
    const body = encodeURIComponent(`${props.formTitle}\n\n${liveUrl.value ?? ''}`);
    return `mailto:?subject=${subject}&body=${body}`;
});

/**
 * `navigator.share` is the honest mobile path — it reaches WhatsApp, SMS and everything else the device
 * already has, which no set of hard-coded network buttons can. Read once at setup: it does not appear
 * mid-session, and re-reading it per render would make the button flicker under SSR hydration.
 */
const canNativeShare = typeof navigator !== 'undefined' && typeof navigator.share === 'function';

async function copy(field: string, text: string): Promise<void> {
    try {
        await navigator.clipboard?.writeText(text);
        copied.value = field;
    } catch {
        copied.value = null;
    }
}

async function nativeShare(): Promise<void> {
    if (liveUrl.value === null) return;
    try {
        await navigator.share({ title: props.formTitle, url: liveUrl.value });
    } catch {
        // A dismissed share sheet rejects. That is a decision, not a failure — say nothing.
    }
}

function useSuggested(): void {
    form.public_slug = props.share.suggested_slug;
}

function close(): void {
    emit('update:open', false);
}

/**
 * An empty slug field means "no public link" — the request takes null there, and `present` + `nullable` is
 * what lets it through (a `required` rule would refuse the very act of un-publishing a link).
 */
function submit(): void {
    form
        .transform((data) => ({
            public_slug: data.public_slug === '' ? null : data.public_slug,
            allow_guest_submissions: data.allow_guest_submissions,
            // Boolean → enum. The wire value is the enum the column stores; `present` + `nullable` on the
            // limit is what lets a cleared field through, the same "meaningful absence" the slug expresses.
            bot_challenge: data.bot_challenge ? 'proof_of_work' : 'off',
            guest_rate_limit_per_minute: data.guest_rate_limit_per_minute,
        }))
        .patch(`/forms/${props.formId}/share`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => close(),
        });
}
</script>

<template>
    <MdsModal :open="open" title="Share form" @close="close">
        <p class="share__prose">
            Publish this form to a public link that anyone can open — no account needed. Share the link
            directly, print the QR code, or embed the form on your own site.
        </p>

        <!-- ── Guest access ──────────────────────────────────────────────────────────────────────── -->
        <!-- MdsCheckbox, not a switch. ⚠️ THE ORIGINAL REASON IS NOW STALE and is corrected here rather
             than left to mislead: MdsSwitch was "specified in DSR §3.2 but deliberately unbuilt" when this
             was written, and I5 built it. The choice still stands on the second half of that argument —
             DSR §3.2's rule that on/off carry visible text makes a switch a checkbox with extra steps here
             — and on consistency: this modal saves behind a button, unlike the autosave switches in
             Settings, and a switch that does not take effect until you press Save is a lie about itself. -->
        <div class="share__block">
            <MdsCheckbox
                v-model="form.allow_guest_submissions"
                label="Accept responses from the public link"
                :invalid="Boolean(form.errors.allow_guest_submissions)"
            />
            <p class="share__help">
                Off by default. While this is off the link returns “not found”, so an old link stops working
                the moment you turn it off.
            </p>
        </div>

        <!-- ── The link ──────────────────────────────────────────────────────────────────────────── -->
        <div class="share__block">
            <MdsFormField
                v-slot="{ id, describedby, invalid }"
                label="Link name"
                help="Lowercase letters, numbers and hyphens. This is the part of the address after /f/."
                :error="form.errors.public_slug"
            >
                <div class="share__slug">
                    <span class="share__prefix" aria-hidden="true">{{ share.public_host }}/f/</span>
                    <MdsTextInput
                        :id="id"
                        v-model="form.public_slug"
                        :placeholder="share.suggested_slug"
                        :describedby="describedby"
                        :invalid="invalid"
                    />
                </div>
            </MdsFormField>

            <div class="share__slug-actions">
                <MdsButton
                    v-if="form.public_slug !== share.suggested_slug"
                    variant="tertiary"
                    size="sm"
                    @click="useSuggested"
                >
                    Use “{{ share.suggested_slug }}”
                </MdsButton>
            </div>

            <p v-if="renameWarning" class="share__warn" role="status">
                <MdsIcon name="alert" size="sm" />
                <span>
                    Renaming the link breaks any copy of the old address you have already shared. Responses
                    already in progress are unaffected.
                </span>
            </p>

            <p v-if="previewUrl" class="share__preview">
                <span class="share__preview-label">Address:</span>
                <code class="share__code">{{ previewUrl }}</code>
            </p>
        </div>

        <!-- ── Spam protection (I8b, PRD Feature #3) ─────────────────────────────────────────────── -->
        <!-- Both controls are OFF by default and stay that way unless an author opts in. That is a
             commitment from docs/security-threat-model.md §4, not a convenience: many M&E and
             field-collection deployments run on trusted enumerator devices, where a spam check is
             actively harmful UX. The help text says so plainly rather than selling the feature. -->
        <div class="share__block">
            <h3 class="share__legend">Spam protection</h3>

            <MdsCheckbox
                v-model="form.bot_challenge"
                label="Require an automatic spam check before a response is sent"
                :invalid="Boolean(form.errors.bot_challenge)"
            />
            <p class="share__help">
                Adds a short check the visitor's browser completes on its own — no typing, no puzzle, no
                images. It costs a moment on older phones, so leave it off for forms your own staff fill in.
            </p>

            <MdsFormField
                v-slot="{ id, describedby, invalid }"
                label="Limit responses per visitor"
                help="Responses per minute from one visitor. Leave blank for no limit."
                :error="form.errors.guest_rate_limit_per_minute"
            >
                <MdsNumberInput
                    :id="id"
                    v-model="form.guest_rate_limit_per_minute"
                    :min="1"
                    :max="600"
                    placeholder="No limit"
                    :describedby="describedby"
                    :invalid="invalid"
                />
            </MdsFormField>
        </div>

        <!-- ── Live state: either the working link and everything built on it, or why not ─────────── -->
        <div v-if="blockedReason" class="share__notice" role="status">
            <MdsIcon name="info" size="sm" />
            <span>{{ blockedReason }}</span>
        </div>

        <template v-else-if="liveUrl">
            <div class="share__block">
                <span class="share__legend">Copy the link</span>
                <div class="share__row">
                    <code class="share__code">{{ liveUrl }}</code>
                    <MdsIconButton
                        icon="link"
                        :label="copied === 'link' ? 'Link copied' : 'Copy link'"
                        size="sm"
                        @click="copy('link', liveUrl)"
                    />
                </div>
                <div class="share__row share__row--actions">
                    <MdsButton
                        v-if="canNativeShare"
                        variant="secondary"
                        size="sm"
                        icon-left="share"
                        @click="nativeShare"
                    >
                        Share…
                    </MdsButton>
                    <MdsButton variant="secondary" size="sm" icon-left="mail" as="a" :href="mailtoHref">
                        Email the link
                    </MdsButton>
                </div>
            </div>

            <div class="share__block">
                <span class="share__legend">QR code</span>
                <div class="share__qr-row">
                    <!-- Server-rendered from bacon/bacon-qr-code (already a Fortify dependency), delivered
                         to an <img> rather than inlined through v-html. Held on true white in BOTH themes:
                         a scanner needs dark modules on a light ground, so a dark-mode inversion here would
                         produce a beautiful code that no phone can read. -->
                    <img
                        class="share__qr"
                        :src="`/forms/${formId}/share/qr.svg`"
                        :alt="`QR code linking to ${formTitle}`"
                        width="160"
                        height="160"
                    />
                    <div class="share__qr-side">
                        <p class="share__help">
                            Print this on a flyer or a poster. Scanning it opens the form directly.
                        </p>
                        <MdsButton
                            variant="secondary"
                            size="sm"
                            icon-left="download"
                            as="a"
                            :href="`/forms/${formId}/share/qr.svg`"
                            download
                        >
                            Download QR code
                        </MdsButton>
                    </div>
                </div>
            </div>

            <div class="share__block">
                <span class="share__legend">Embed on your site</span>
                <div class="share__row">
                    <code class="share__code share__code--snippet">{{ embedSnippet }}</code>
                    <MdsIconButton
                        icon="copy"
                        :label="copied === 'embed' ? 'Embed code copied' : 'Copy embed code'"
                        size="sm"
                        @click="copy('embed', embedSnippet)"
                    />
                </div>
                <p class="share__help">
                    Paste this into any page on your own site. The form runs inside the frame and submits
                    straight to this workspace.
                </p>
            </div>
        </template>

        <p class="share__status" role="status">
            <span v-if="copied === 'link'">Link copied to the clipboard.</span>
            <span v-else-if="copied === 'embed'">Embed code copied to the clipboard.</span>
        </p>

        <template #actions>
            <MdsButton variant="tertiary" @click="close">Cancel</MdsButton>
            <MdsButton variant="primary" icon-left="check" :loading="form.processing" @click="submit">
                Save sharing settings
            </MdsButton>
        </template>
    </MdsModal>
</template>

<style scoped>
.share__prose {
    margin: 0 0 var(--mds-space-5);
    color: var(--mds-color-text-body);
}

.share__block {
    margin-bottom: var(--mds-space-5);
}

.share__legend {
    display: block;
    margin-bottom: var(--mds-space-2);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-label-font-size);
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-body);
}

.share__help {
    margin: var(--mds-space-2) 0 0;
    font-size: var(--mds-type-caption-font-size);
    line-height: var(--mds-type-caption-line-height);
    color: var(--mds-color-text-secondary);
}

/* `auto` prefix + `minmax(0, 1fr)` input: without the explicit zero minimum the host string sets the grid's
   minimum width and the modal scrolls sideways at 375px — the DnsRecordBlock lesson. */
.share__slug {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    align-items: center;
    gap: var(--mds-space-2);
}

.share__prefix {
    font-family: var(--mds-font-family-mono);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
    overflow-wrap: anywhere;
}

.share__slug-actions {
    display: flex;
    justify-content: flex-end;
    min-height: 1lh;
}

.share__preview {
    margin: var(--mds-space-2) 0 0;
    display: flex;
    flex-wrap: wrap;
    gap: var(--mds-space-2);
    align-items: baseline;
}

.share__preview-label {
    font-size: var(--mds-type-caption-font-size);
    color: var(--mds-color-text-secondary);
}

.share__row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: start;
    gap: var(--mds-space-2);
    padding: var(--mds-space-3);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-sunken);
}

.share__row--actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-start;
    margin-top: var(--mds-space-2);
    border: 0;
    padding: 0;
    background: none;
}

.share__code {
    min-width: 0;
    font-family: var(--mds-font-family-mono);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-text-body);
    /* A URL and an iframe tag have no break opportunities of their own. */
    overflow-wrap: anywhere;
}

.share__code--snippet {
    white-space: pre-wrap;
}

.share__qr-row {
    display: flex;
    flex-wrap: wrap;
    gap: var(--mds-space-4);
    align-items: flex-start;
}

/* True white in both themes, with its own border so the tile still has an edge on a light surface. See the
   template comment: contrast here is a scanner requirement, not a taste one. */
.share__qr {
    width: 160px;
    height: 160px;
    padding: var(--mds-space-2);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background-color: #ffffff;
}

.share__qr-side {
    flex: 1 1 12rem;
    min-width: 0;
}

.share__notice,
.share__warn {
    display: flex;
    gap: var(--mds-space-2);
    align-items: flex-start;
    margin: var(--mds-space-3) 0 var(--mds-space-5);
    padding: var(--mds-space-3);
    border-radius: var(--mds-radius-md);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
}

.share__notice {
    background-color: var(--mds-color-bg-sunken);
    color: var(--mds-color-text-body);
}

/* `status-warning-fg` rather than `--mds-color-warning-text`: the status-*-fg family is the on-surface pair
   that clears AA in BOTH palettes (the plain family resolves to a 300-step in dark and fails at caption size). */
.share__warn {
    margin-bottom: 0;
    background-color: var(--mds-color-status-warning-bg);
    color: var(--mds-color-status-warning-fg);
}

.share__status {
    margin: 0;
    min-height: 1lh;
    font-size: var(--mds-type-caption-font-size);
    color: var(--mds-color-status-success-fg);
}
</style>
