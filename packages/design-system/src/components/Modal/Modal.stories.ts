import type { Meta, StoryObj, Decorator } from '@storybook/vue3';
import Modal from './Modal.vue';
import Button from '../Button/Button.vue';
import TextInput from '../TextInput/TextInput.vue';

const dark: Decorator = (story) => {
    document.documentElement.setAttribute('data-theme-mode', 'dark');
    return story();
};

// `teleport: false` renders the dialog in place so the axe runner (scoped to #storybook-root) sees it.
const meta = {
    title: 'Components/Modal',
    component: Modal,
    tags: ['autodocs'],
    args: { open: true, title: 'Remove member', teleport: false },
    render: (args) => ({
        components: { Modal, Button },
        setup: () => ({ args }),
        template: `
            <Modal v-bind="args">
                <p style="margin:0">
                    This removes <strong>jordan@acme.test</strong> from the workspace. They lose access
                    immediately; their submissions are retained.
                </p>
                <template #actions>
                    <Button variant="tertiary">Cancel</Button>
                    <Button variant="destructive">Remove member</Button>
                </template>
            </Modal>
        `,
    }),
} satisfies Meta<typeof Modal>;

export default meta;

type Story = StoryObj<typeof meta>;

export const Confirm: Story = {};
export const ConfirmDark: Story = { decorators: [dark] };

/**
 * Increment I10a — the modal beside the page it is covering, so the design system documents the composition
 * the `inert` walk actually operates on: background content as a SIBLING of the dialog inside the story root.
 *
 * ⚠️ BE PRECISE ABOUT WHAT THIS DOES AND DOES NOT PROVE. It exercises the inert code path inside the axe job
 * and shows the shape in the docs — but it CANNOT fail on the walk being wrong. The runner scans
 * `checkA11y(page, '#storybook-root')`, so a walk that wrongly inerted the root would hand axe an empty
 * accessibility tree and the story would go **green**, not red. That failure mode is caught in Vitest
 * (`Modal.test.ts`'s "does NOT inert the wrapper the dialog renders inside when teleport is off"), which is
 * the only place it can be caught, and the mutation is confirmed to redden there.
 *
 * Deliberately NO planted contrast violation in the background either. It would "prove" the exclusion by
 * making the job green only because axe skipped something — a fixture a future reader would quite reasonably
 * "fix", and a confusing red if axe ever changes how it treats inert. The exclusion is proved where it was
 * observed instead: `tests/e2e/builder-axe.spec.ts`'s two share-panel scans run whole-page against the
 * builder's real config panel, and assert both halves (background inert, dialog not).
 *
 * `!autodocs` because the docs page renders every story at once and these all render `open: true`, so each
 * additional open modal stacks on the last — one dialog live, the rest and the surrounding prose inert. The
 * two `Confirm` stories already overlap that way; this one does not make it worse.
 */
export const WithBackgroundContent: Story = {
    tags: ['!autodocs'],
    render: (args) => ({
        components: { Modal, Button },
        setup: () => ({ args }),
        template: `
            <div>
                <div style="padding:var(--mds-space-6);display:flex;flex-direction:column;gap:var(--mds-space-3)">
                    <h3 style="margin:0">Workspace members</h3>
                    <p style="margin:0">This column is behind the dialog and is marked inert while it is open.</p>
                    <Button variant="secondary">Invite member</Button>
                </div>
                <Modal v-bind="args">
                    <p style="margin:0">
                        This removes <strong>jordan@acme.test</strong> from the workspace.
                    </p>
                    <template #actions>
                        <Button variant="tertiary">Cancel</Button>
                        <Button variant="destructive">Remove member</Button>
                    </template>
                </Modal>
            </div>
        `,
    }),
};

/**
 * Increment J1a — a dialog whose point is an input, focused on open via `initialFocus`.
 *
 * ⚠️ WHAT THIS PROVES IS THE MARKUP, NOT THE FOCUS. axe cannot assert where focus landed, and the runner
 * visits the story without pressing anything, so a broken `initialFocus` renders identically and the story
 * stays green. The behaviour — including the fallback when the selector matches nothing, and byte-identical
 * behaviour when the prop is absent — is pinned in `Modal.test.ts`, where the mutations redden.
 *
 * What it DOES earn: the search field inside a dialog goes through the merge-blocking axe scan in both
 * themes, which is the composition J1d's command palette ships and the one place a contrast pairing between
 * `--mds-color-input-bg` and the dialog's surface would be caught.
 *
 * `!autodocs` for the reason the file already records: every story here renders `open: true`, so each extra
 * one stacks on the docs page.
 */
export const InitialFocus: Story = {
    tags: ['!autodocs'],
    args: { title: 'Search', initialFocus: '[data-mds-initial-focus]' },
    render: (args) => ({
        components: { Modal, TextInput },
        setup: () => ({ args }),
        template: `
            <Modal v-bind="args">
                <TextInput
                    data-mds-initial-focus
                    type="search"
                    aria-label="Search forms, submissions, members and settings"
                    placeholder="Search…"
                />
            </Modal>
        `,
    }),
};

export const InitialFocusDark: Story = {
    tags: ['!autodocs'],
    args: { title: 'Search', initialFocus: '[data-mds-initial-focus]' },
    decorators: [dark],
    render: (args) => ({
        components: { Modal, TextInput },
        setup: () => ({ args }),
        template: `
            <Modal v-bind="args">
                <TextInput
                    data-mds-initial-focus
                    type="search"
                    aria-label="Search forms, submissions, members and settings"
                    placeholder="Search…"
                />
            </Modal>
        `,
    }),
};
