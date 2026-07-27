import type { Meta, StoryObj, Decorator } from '@storybook/vue3';
import Badge from './Badge.vue';

// Force dark so axe scans the dark status tokens (pale-on-deep-tint) too.
const dark: Decorator = (story) => {
    document.documentElement.setAttribute('data-theme-mode', 'dark');
    return story();
};

const meta = {
    title: 'Components/Badge',
    component: Badge,
    tags: ['autodocs'],
    argTypes: {
        variant: {
            control: 'select',
            options: ['success', 'warning', 'danger', 'info', 'neutral'],
        },
        label: { control: 'text' },
    },
    args: { variant: 'neutral', label: 'Neutral' },
} satisfies Meta<typeof Badge>;

export default meta;

type Story = StoryObj<typeof meta>;

export const Success: Story = { args: { variant: 'success', label: 'Active' } };
export const Warning: Story = { args: { variant: 'warning', label: 'Suspended' } };
export const Danger: Story = { args: { variant: 'danger', label: 'Error' } };
export const Info: Story = { args: { variant: 'info', label: 'Pending' } };
export const Neutral: Story = { args: { variant: 'neutral', label: 'Removed' } };
export const WithIcon: Story = { args: { variant: 'success', label: 'Active', icon: 'check' } };

export const SuccessDark: Story = { args: { variant: 'success', label: 'Active' }, decorators: [dark] };
export const WarningDark: Story = { args: { variant: 'warning', label: 'Suspended' }, decorators: [dark] };
export const DangerDark: Story = { args: { variant: 'danger', label: 'Error' }, decorators: [dark] };
export const InfoDark: Story = { args: { variant: 'info', label: 'Pending' }, decorators: [dark] };
export const NeutralDark: Story = { args: { variant: 'neutral', label: 'Removed' }, decorators: [dark] };

// Webhook status tokens (Increment H14) — endpoint (paused/disabled) + delivery
// (delivering/succeeded/dead-lettered) descriptors, scanned in light + dark so axe measures the
// pale-on-deep-tint contrast of every webhook pill the mgmt UI renders.
export const WebhookPaused: Story = { args: { variant: 'warning', label: 'Paused' } };
export const WebhookDisabled: Story = { args: { variant: 'neutral', label: 'Disabled' } };
export const DeliveryDelivering: Story = { args: { variant: 'info', label: 'Delivering' } };
export const DeliverySucceeded: Story = { args: { variant: 'success', label: 'Succeeded' } };
export const DeliveryDeadLettered: Story = { args: { variant: 'danger', label: 'Dead-lettered' } };

export const WebhookPausedDark: Story = { args: { variant: 'warning', label: 'Paused' }, decorators: [dark] };
export const WebhookDisabledDark: Story = { args: { variant: 'neutral', label: 'Disabled' }, decorators: [dark] };
export const DeliverySucceededDark: Story = { args: { variant: 'success', label: 'Succeeded' }, decorators: [dark] };
export const DeliveryDeadLetteredDark: Story = { args: { variant: 'danger', label: 'Dead-lettered' }, decorators: [dark] };

// Native-connector grant tokens (Increment H15b) — ConnectionStatus's two non-shared states. Both labels are
// longer than the webhook pills above, which is the point of scanning them: a wider pill changes where the
// text sits on the tint, and dark mode is where that contrast is tightest.
export const ConnectionRefreshFailed: Story = { args: { variant: 'danger', label: 'Reconnect needed' } };
export const ConnectionRevoked: Story = { args: { variant: 'neutral', label: 'Disconnected' } };

export const ConnectionRefreshFailedDark: Story = { args: { variant: 'danger', label: 'Reconnect needed' }, decorators: [dark] };
export const ConnectionRevokedDark: Story = { args: { variant: 'neutral', label: 'Disconnected' }, decorators: [dark] };
