import type { Meta, StoryObj, Decorator } from '@storybook/vue3';
import ToastHost from './ToastHost.vue';
import type { ToastItem } from './toast';

const dark: Decorator = (story) => {
    document.documentElement.setAttribute('data-theme-mode', 'dark');
    return story();
};

const sample: ToastItem[] = [
    { id: 1, type: 'success', message: 'Invitation sent to jordan@acme.test' },
    { id: 2, type: 'info', message: 'Your export is being prepared.' },
    { id: 3, type: 'error', message: "Couldn't remove the member — please try again." },
];

// `teleport: false` renders the stack in place so the axe runner (scoped to #storybook-root) sees it.
const meta = {
    title: 'Components/Toast',
    component: ToastHost,
    tags: ['autodocs'],
    args: { toasts: sample, teleport: false },
    render: (args) => ({
        components: { ToastHost },
        setup: () => ({ args }),
        // Give the fixed host room + a non-fixed context so the stack is visible in the story frame.
        template: '<div style="min-height:220px"><ToastHost v-bind="args" /></div>',
    }),
} satisfies Meta<typeof ToastHost>;

export default meta;

type Story = StoryObj<typeof meta>;

export const Stack: Story = {};
export const StackDark: Story = { decorators: [dark] };
