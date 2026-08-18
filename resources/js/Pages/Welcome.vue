<script setup lang="ts">
import { MdsButton, MdsIcon } from '@meridian/design-system';
import { Head } from '@inertiajs/vue3';

/**
 * The platform's front door, on the central host only (Increment I6).
 *
 * `PlatformLandingController` renders this when the request carries no workspace subdomain; a request to
 * `acme.<central>/` is redirected to the dashboard or the sign-in page instead, and a custom domain 404s
 * before it reaches any of this.
 *
 * ── SELF-LAID-OUT, AND THAT IS A RECORDED EXCEPTION RATHER THAN AN OVERSIGHT ────────────────────────────
 * `resources/js/app.ts` excludes `Welcome` from `AppLayout`, and the design system defines exactly two
 * top-level shells — neither of which fits a guest-facing hero (`AuthLayout` is a ~400px centred card).
 * DSR §1.3 blesses "a marketing page with different layout needs" as a legitimate exception; it is logged
 * as `docs/ux/exceptions-log.md` #8. Every value below is a design token, so the page follows the theme in
 * both light and dark and cannot drift from the app it fronts.
 */
defineProps<{
    appName: string;
    /**
     * Whether platform signup is open. When false the "Create a workspace" call to action is not rendered
     * at all — `GateRegistration` 404s `/register` in that state, and a front door whose main button leads
     * to a 404 is worse than a front door with one button.
     */
    registrationOpen: boolean;
    /** The central host, so the footer can name the shape of a workspace URL rather than guess at it. */
    centralHost: string;
}>();

const capabilities = [
    {
        icon: 'forms',
        title: 'Build forms that think',
        body: 'Thirty field types, conditional logic, calculations, repeat groups and multi-step flows — with a live logic view that reads your rules back to you in plain language.',
    },
    {
        icon: 'globe',
        title: 'Collect anywhere',
        body: 'Share a public link or a QR code. Respondents can fill offline on a phone, save and resume later, and everything syncs when they reconnect.',
    },
    {
        icon: 'submissions',
        title: 'Review before you trust',
        body: 'An inbox with approve, return and archive states, per-form reviewer assignment, and a full audit trail of who changed what.',
    },
    {
        icon: 'chart-bar',
        title: 'See what arrived',
        body: 'Dashboards and cross-form analytics over submission metadata and indexed answers, with saved views and CSV or XLSX export.',
    },
] as const;
</script>

<template>
    <Head :title="`${appName} — form building and field data collection`" />

    <main class="landing">
        <div class="landing__inner">
            <header class="landing__hero">
                <p class="landing__eyebrow">{{ appName }}</p>
                <h1 class="landing__title">Forms, field data, and a review trail you can defend.</h1>
                <p class="landing__lede">
                    A multi-tenant form builder for teams that collect real data in the field — offline-capable,
                    permission-aware, and auditable end to end.
                </p>

                <div class="landing__actions">
                    <MdsButton as="a" href="/login" variant="primary" size="lg">Sign in</MdsButton>
                    <MdsButton
                        v-if="registrationOpen"
                        as="a"
                        href="/register"
                        variant="secondary"
                        size="lg"
                    >
                        Create a workspace
                    </MdsButton>
                </div>

                <p v-if="!registrationOpen" class="landing__note">
                    New workspaces are by invitation at the moment. If you already have one, sign in above.
                </p>
            </header>

            <section class="landing__grid" aria-label="What this platform does">
                <article v-for="item in capabilities" :key="item.title" class="landing__card">
                    <MdsIcon :name="item.icon" size="lg" class="landing__card-icon" aria-hidden="true" />
                    <h2 class="landing__card-title">{{ item.title }}</h2>
                    <p class="landing__card-body">{{ item.body }}</p>
                </article>
            </section>

            <footer class="landing__footer">
                <p class="landing__footer-text">
                    Already a member of a workspace? Go straight to it at
                    <code class="landing__code">your-workspace.{{ centralHost }}</code>
                </p>
            </footer>
        </div>
    </main>
</template>

<style scoped>
/* Tokens only — no literal colours, spacing or type sizes. `--mds-type-display`, `--mds-space-16` and
   `--mds-space-24` are the three the DSR reserves for exactly this surface ("marketing/onboarding only,
   never inside the authenticated app shell"), which is why they appear here and nowhere else. */
.landing {
    min-height: 100vh;
    min-height: 100dvh;
    padding: var(--mds-space-10) var(--mds-space-5);
    background-color: var(--mds-color-bg-canvas);
    color: var(--mds-color-text-body);
    font-family: var(--mds-font-family-body);
}

.landing__inner {
    max-width: 64rem;
    margin: 0 auto;
}

.landing__hero {
    max-width: 44rem;
    margin: 0 auto var(--mds-space-16);
    text-align: center;
}

.landing__eyebrow {
    margin: 0 0 var(--mds-space-3);
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-label-font-size);
    line-height: var(--mds-type-label-line-height);
    font-weight: var(--mds-type-label-font-weight);
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.landing__title {
    margin: 0 0 var(--mds-space-4);
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-display-font-size);
    line-height: var(--mds-type-display-line-height);
    font-weight: var(--mds-type-display-font-weight);
    letter-spacing: var(--mds-type-display-letter-spacing); /* JR1: was -0.015em */
}

.landing__lede {
    margin: 0 auto var(--mds-space-6);
    max-width: 36rem;
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-lg-font-size);
    line-height: var(--mds-type-body-lg-line-height);
}

.landing__actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--mds-space-3);
    justify-content: center;
}

.landing__note {
    margin: var(--mds-space-4) 0 0;
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
}

.landing__grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr));
    gap: var(--mds-space-4);
}

.landing__card {
    padding: var(--mds-space-5);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-lg);
    background-color: var(--mds-color-bg-surface);
}

.landing__card-icon {
    display: block;
    margin-bottom: var(--mds-space-3);
    color: var(--mds-color-status-info-fg);
}

.landing__card-title {
    margin: 0 0 var(--mds-space-2);
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-4-font-size);
    line-height: var(--mds-type-heading-4-line-height);
    font-weight: var(--mds-type-heading-4-font-weight);
}

.landing__card-body {
    margin: 0;
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
}

.landing__footer {
    margin-top: var(--mds-space-10);
    text-align: center;
}

.landing__footer-text {
    margin: 0;
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
}

.landing__code {
    font-family: var(--mds-font-family-mono);
    font-size: inherit;
}

@media (min-width: 48rem) {
    .landing {
        padding: var(--mds-space-16) var(--mds-space-6);
    }
}
</style>
