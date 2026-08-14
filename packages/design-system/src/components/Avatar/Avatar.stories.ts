import type { Meta, StoryObj, Decorator } from '@storybook/vue3';
import Avatar from './Avatar.vue';

const dark: Decorator = (story) => {
    document.documentElement.setAttribute('data-theme-mode', 'dark');
    return story();
};

const meta = {
    title: 'Components/Avatar',
    component: Avatar,
    tags: ['autodocs'],
    argTypes: {
        size: { control: 'select', options: ['sm', 'md', 'lg'] },
        tone: { control: 'select', options: ['brand', 'neutral'] },
        name: { control: 'text' },
    },
    args: { name: 'Demo Owner', size: 'sm', tone: 'brand' },
} satisfies Meta<typeof Avatar>;

export default meta;

type Story = StoryObj<typeof meta>;

export const Small: Story = {};
export const Medium: Story = { args: { size: 'md' } };
export const Large: Story = { args: { size: 'lg' } };

/** Somebody who is not a full participant yet — a pending invite, an anonymous respondent. */
export const Neutral: Story = { args: { tone: 'neutral', name: 'Guest' } };

/**
 * The shape the component is always used in. Never alone: the name beside it is what an assistive
 * technology reads, which is why the chip itself is `aria-hidden`.
 */
export const BesideAName: Story = {
    render: (args) => ({
        components: { Avatar },
        setup: () => ({ args }),
        template: `<span style="display:inline-flex;align-items:center;gap:8px"><Avatar v-bind="args" /><span>Demo Owner</span></span>`,
    }),
};

/** The derivation rules, so a regression is visible in the axe run's own screenshots. */
export const OneWordName: Story = { args: { name: 'Prince' } };
export const ManyWordName: Story = { args: { name: 'Maria de los Santos' } };
export const NonLatin: Story = { args: { name: '山田太郎' } };
export const NoName: Story = { args: { name: null } };

export const SmallDark: Story = { decorators: [dark] };
export const NeutralDark: Story = { ...Neutral, decorators: [dark] };
export const LargeDark: Story = { ...Large, decorators: [dark] };
