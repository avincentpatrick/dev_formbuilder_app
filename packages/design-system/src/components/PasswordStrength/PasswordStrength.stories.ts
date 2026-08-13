import type { Meta, StoryObj, Decorator } from '@storybook/vue3';
import PasswordStrength from './PasswordStrength.vue';

const dark: Decorator = (story) => {
    document.documentElement.setAttribute('data-theme-mode', 'dark');
    return story();
};

/**
 * A stand-in for what `PasswordPolicy::requirements()` ships. It is a FIXTURE, not a second source of
 * truth — the component reads whatever the server sends, and `PasswordPolicyTest` (PHP) plus
 * `password-policy.test.ts` (here) are what hold the real list and the real patterns to account. The
 * shape is copied faithfully, including the null `uncompromised` pattern, because the null row is the
 * whole reason this component has three states rather than two.
 */
const POLICY = [
    { key: 'min_length', label: '12 characters or more', pattern: '[\\s\\S]{12,}' },
    {
        key: 'mixed_case',
        label: 'An upper and a lower case letter',
        pattern: '(?=[\\s\\S]*\\p{Ll})(?=[\\s\\S]*\\p{Lu})',
    },
    { key: 'numbers', label: 'A number', pattern: '\\p{N}' },
    { key: 'symbols', label: 'A symbol', pattern: '\\p{Z}|\\p{S}|\\p{P}' },
    { key: 'uncompromised', label: 'Not found in a known data breach', pattern: null },
];

const meta = {
    title: 'Components/PasswordStrength',
    component: PasswordStrength,
    tags: ['autodocs'],
    args: { password: '', requirements: POLICY, inputId: 'password' },
} satisfies Meta<typeof PasswordStrength>;

export default meta;
type Story = StoryObj<typeof meta>;

/** Nothing typed yet — every tickable rule pending, the breach rule stated. */
export const Empty: Story = { args: { password: '' } };

/** Mid-typing: the state the checklist exists for. */
export const Partial: Story = { args: { password: 'Abcdefghijkl' } };

/**
 * Everything this browser can decide is green — and the breach row is STILL not ticked, which is the
 * point. A story for it exists so the axe run sees the success colour on a real ground in both themes.
 */
export const AllTickableMet: Story = { args: { password: 'Abcdefghijk1!' } };

export const EmptyDark: Story = { args: { password: '' }, decorators: [dark] };
export const PartialDark: Story = { args: { password: 'Abcdefghijkl' }, decorators: [dark] };
export const AllTickableMetDark: Story = {
    args: { password: 'Abcdefghijk1!' },
    decorators: [dark],
};
