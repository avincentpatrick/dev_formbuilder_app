import type { Meta, StoryObj, Decorator } from '@storybook/vue3';
import Tooltip from './Tooltip.vue';
import Modal from '../Modal/Modal.vue';

const dark: Decorator = (story) => {
    document.documentElement.setAttribute('data-theme-mode', 'dark');
    return story();
};

/**
 * ⚠️ EVERY STORY SETS BOTH `teleport: false` AND `defaultVisible: true`, AND BOTH ARE REQUIRED FOR THE
 * SCAN TO MEAN ANYTHING.
 *
 * The runner scans the story root, so a teleported bubble lands outside it and is never examined; and a
 * tooltip nobody is hovering renders literally nothing, so the scanner walks an empty subtree and reports
 * success. Either omission produces a green accessibility job that has inspected no tooltip at all — the
 * vacuous-green shape `Modal.stories.ts` already records, and the one this component's own suite caught in
 * its geometry.
 */
const meta = {
    title: 'Components/Tooltip',
    component: Tooltip,
    tags: ['autodocs'],
    argTypes: {
        text: { control: 'text' },
        placement: { control: 'select', options: ['top', 'right', 'bottom', 'left'] },
    },
    args: { text: 'Response statistics', placement: 'top', teleport: false, defaultVisible: true },
} satisfies Meta<typeof Tooltip>;

export default meta;

type Story = StoryObj<typeof meta>;

const withButton = (args: Record<string, unknown>) => ({
    components: { Tooltip },
    setup: () => ({ args }),
    template: `
        <div style="padding: 5rem; display: flex; justify-content: center;">
            <Tooltip v-bind="args">
                <template #default="{ trigger }">
                    <button v-bind="trigger" type="button">Statistics</button>
                </template>
            </Tooltip>
        </div>
    `,
});

export const Default: Story = { render: withButton };

export const Right: Story = {
    args: { placement: 'right' },
    render: withButton,
};

/**
 * The collapsed sidebar rail — the consumer DSR §3.4 and §6 have required since they were written, and the
 * reason this component exists. `block` makes the anchor fill the 64px rail item rather than shrink-wrap it.
 */
export const RailItem: Story = {
    args: { text: 'Audit log', placement: 'right', block: true },
    render: (args) => ({
        components: { Tooltip },
        setup: () => ({ args }),
        template: `
            <div style="width: 64px; padding: 0.5rem;">
                <Tooltip v-bind="args">
                    <template #default="{ trigger }">
                        <a v-bind="trigger" href="#" style="display: flex; justify-content: center; min-height: 40px; align-items: center;">
                            <span aria-hidden="true">▤</span>
                            <span style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);">Audit log</span>
                        </a>
                    </template>
                </Tooltip>
            </div>
        `,
    }),
};

/** Wrapping at `max-width`, which is where `overflow-wrap: anywhere` earns its place on a long label. */
export const LongText: Story = {
    args: { text: 'Response statistics for every published version of this form' },
    render: withButton,
};

/**
 * The composition the inert exemption exists for: a tooltip on a control INSIDE an open dialog. Without
 * `data-mds-inert-exempt` on the teleported root the bubble is marked `inert` by the modal — dropped from
 * the accessibility tree, unhoverable, and invisible to the one person who is inside the dialog.
 *
 * ⚠️ This story runs with `teleport: false`, so it demonstrates the composition rather than the exemption.
 * The exemption itself is asserted in `Tooltip.test.ts`, which mounts a real modal and checks BOTH that the
 * bubble escaped and that a control sibling was marked — a one-sided assertion would also pass if the inert
 * walk were broken and nothing at all got marked.
 */
export const InsideModal: Story = {
    render: (args) => ({
        components: { Tooltip, Modal },
        setup: () => ({ args }),
        template: `
            <Modal :open="true" title="Archive this form?" :teleport="false">
                <p>Responses are kept. The form stops accepting new ones.</p>
                <Tooltip v-bind="args" text="Archiving is reversible for 30 days">
                    <template #default="{ trigger }">
                        <button v-bind="trigger" type="button">What happens next?</button>
                    </template>
                </Tooltip>
            </Modal>
        `,
    }),
};

export const DefaultDark: Story = { render: withButton, decorators: [dark] };
export const RightDark: Story = { args: { placement: 'right' }, render: withButton, decorators: [dark] };
