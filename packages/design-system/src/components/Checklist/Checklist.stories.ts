import type { Meta, StoryObj, Decorator } from '@storybook/vue3';
import Checklist from './Checklist.vue';

// Force dark so axe scans the dark pairs too — the Badge, Banner and Progress precedent. It is the
// interesting half here for two reasons: `--mds-color-status-success-fg` is what a DONE row's text and its
// check glyph are both drawn in, and `--mds-color-action-primary-fg` is re-pointed for dark rather than
// riding the primary ramp, so a light pass says nothing at all about dark.
const dark: Decorator = (story) => {
    document.documentElement.setAttribute('data-theme-mode', 'dark');
    return story();
};

const items = [
    {
        key: 'create_form',
        label: 'Create your first form',
        description: 'Start from a ready-made template, or build one from a blank canvas.',
        done: true,
    },
    {
        key: 'publish_form',
        label: 'Publish it',
        description: 'A form collects nothing until it is published — drafts stay private to your workspace.',
        done: true,
    },
    {
        key: 'first_response',
        label: 'Collect your first response',
        description: 'Share the form’s link, or key in a response yourself from the form’s page.',
        done: false,
        href: '/forms',
    },
    {
        key: 'invite_teammate',
        label: 'Invite a teammate',
        description: 'Editors build forms alongside you; reviewers check what comes in.',
        done: false,
        href: '/members',
    },
];

const meta = {
    title: 'Components/Checklist',
    component: Checklist,
    tags: ['autodocs'],
    argTypes: {
        label: { control: 'text' },
        progressLabel: { control: 'text' },
        dismissible: { control: 'boolean' },
    },
    args: { items },
} satisfies Meta<typeof Checklist>;

export default meta;

type Story = StoryObj<typeof meta>;

export const Default: Story = {};

/** With the control the page owns the meaning of. The `v-if` is never this component's. */
export const Dismissible: Story = { args: { dismissible: true } };

/**
 * Nothing started — every row pending, every row a link. This is the state a brand-new workspace is in,
 * and it is the one where the ring mark has to be legible four times in a column rather than once.
 */
export const NothingDone: Story = {
    args: { items: items.map((item) => ({ ...item, done: false, href: item.href ?? '/forms' })) },
};

/**
 * Every row done. The application hides the card in this state — see `GettingStartedChecklist`, where that
 * arm is what lets the dismissal column ship without a backfill — but the COMPONENT must still render it
 * honestly, because a component that only works on its caller's happy path is a page in disguise.
 */
export const AllDone: Story = {
    args: { items: items.map(({ href: _href, ...item }) => ({ ...item, done: true })) },
};

/**
 * A row the reader may not act on keeps its label and loses its link. Dropping it instead would make one
 * workspace show a different NUMBER of steps per role — `MdsBreadcrumb`'s argument, for its reason.
 */
export const RefusedDestination: Story = {
    args: { items: items.map(({ href: _href, ...item }) => item) },
};

/** A step whose label says it all. The description is optional and the row must not collapse without it. */
export const WithoutDescriptions: Story = {
    args: { items: items.map(({ description: _description, ...item }) => item) },
};

export const DefaultDark: Story = { decorators: [dark] };
export const DismissibleDark: Story = { ...Dismissible, decorators: [dark] };
export const NothingDoneDark: Story = { ...NothingDone, decorators: [dark] };
