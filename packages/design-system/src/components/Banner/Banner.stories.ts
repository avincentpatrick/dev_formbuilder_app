import type { Meta, StoryObj, Decorator } from '@storybook/vue3';
import Banner from './Banner.vue';

// Force dark so axe scans the dark status tokens (pale-on-deep-tint) too — the Badge precedent, and the
// reason H21d1 exists: `--mds-color-danger-text` resolved to a 4.22:1 pair in dark that light never showed.
const dark: Decorator = (story) => {
    document.documentElement.setAttribute('data-theme-mode', 'dark');
    return story();
};

const meta = {
    title: 'Components/Banner',
    component: Banner,
    tags: ['autodocs'],
    argTypes: {
        tone: { control: 'select', options: ['info', 'warning', 'danger'] },
        message: { control: 'text' },
    },
    args: { tone: 'info', icon: 'info', message: 'This workspace is in maintenance mode.' },
} satisfies Meta<typeof Banner>;

export default meta;

type Story = StoryObj<typeof meta>;

export const Info: Story = {};
export const Warning: Story = {
    args: { tone: 'warning', icon: 'alert', message: 'Your plan quota is nearly used up.' },
};
export const Danger: Story = {
    args: {
        tone: 'danger',
        icon: 'shield',
        message: 'You are signed in as Ada Lovelace. Everything you do is recorded.',
    },
};

export const WithAction: Story = {
    args: {
        tone: 'danger',
        icon: 'shield',
        message: 'You are signed in as Ada Lovelace. Everything you do is recorded.',
    },
    render: (args) => ({
        components: { Banner },
        setup: () => ({ args }),
        template: `<Banner v-bind="args"><template #action><button type="button">Exit</button></template></Banner>`,
    }),
};

export const InfoDark: Story = { decorators: [dark] };
export const WarningDark: Story = { ...Warning, decorators: [dark] };
export const DangerDark: Story = { ...Danger, decorators: [dark] };
