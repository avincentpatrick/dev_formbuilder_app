import type { Meta, StoryObj, Decorator } from '@storybook/vue3';
import Modal from './Modal.vue';
import Button from '../Button/Button.vue';

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
