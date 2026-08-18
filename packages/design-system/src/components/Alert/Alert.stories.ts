import type { Meta, StoryObj, Decorator } from '@storybook/vue3';
import Alert from './Alert.vue';

// Force dark so axe scans the dark status pairs too — the Badge and Banner precedent, and the reason H21d1
// exists: a status pair that passes in light resolved to 4.22:1 in dark and light never showed it.
const dark: Decorator = (story) => {
    document.documentElement.setAttribute('data-theme-mode', 'dark');
    return story();
};

const meta = {
    title: 'Components/Alert',
    component: Alert,
    tags: ['autodocs'],
    argTypes: {
        tone: { control: 'select', options: ['info', 'success', 'warning', 'danger'] },
        title: { control: 'text' },
        message: { control: 'text' },
    },
    args: { tone: 'info', message: 'Rules run against the form as it was published.' },
} satisfies Meta<typeof Alert>;

export default meta;

type Story = StoryObj<typeof meta>;

export const Info: Story = {};
export const Success: Story = {
    args: { tone: 'success', message: 'The endpoint was verified and is delivering.' },
};
export const Warning: Story = {
    args: { tone: 'warning', message: 'This connection expires in 7 days unless it is reconnected.' },
};

/** The assertive case: something the user just did failed. Never for a standing condition. */
export const DangerAssertive: Story = {
    args: {
        tone: 'danger',
        assertive: true,
        message: 'That workspace is already suspended.',
    },
};

/** The shape `MdsBanner` cannot take: a bold first line over a body of several sentences. */
export const WithTitle: Story = {
    args: {
        tone: 'warning',
        title: 'This form was imported with warnings',
        message: 'Three questions could not be matched and were left out of the draft.',
    },
};

/** The other shape Banner cannot take — arbitrary rich content, here a list. */
export const RichBody: Story = {
    args: { tone: 'warning', title: 'This form was imported with warnings', message: undefined },
    render: (args) => ({
        components: { Alert },
        setup: () => ({ args }),
        template: `<Alert v-bind="args"><ul style="margin:0;padding-left:1.2em"><li>Row 4: unknown field type “rating”</li><li>Row 9: duplicate key “name”</li></ul></Alert>`,
    }),
};

export const Dismissible: Story = {
    args: { tone: 'warning', title: 'This form was imported with warnings', dismissible: true },
};

export const WithActions: Story = {
    args: { tone: 'danger', assertive: true, message: 'The sync could not reach Google Sheets.' },
    render: (args) => ({
        components: { Alert },
        setup: () => ({ args }),
        template: `<Alert v-bind="args"><template #actions><button type="button">Reconnect</button></template></Alert>`,
    }),
};

export const InfoDark: Story = { decorators: [dark] };
export const SuccessDark: Story = { ...Success, decorators: [dark] };
export const WarningDark: Story = { ...Warning, decorators: [dark] };
export const DangerDark: Story = { ...DangerAssertive, decorators: [dark] };
export const DismissibleDark: Story = { ...Dismissible, decorators: [dark] };
