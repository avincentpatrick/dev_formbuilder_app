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
