// Pure shaping for the channel picker (H15b). Kept out of the modal so the rules that decide what a tenant
// reads are unit-testable without mounting a component.

import type { Channel, Option } from './types';

/** The placeholder the select shows before a channel is chosen. */
export const CHANNEL_PLACEHOLDER = 'Choose a channel…';

/**
 * How a rule's destination reads in a table or a detail row.
 *
 * The stored `channel_name` may or may not already carry a leading `#` — the picker saves the bare name while
 * rules created through the API (or seeded) may include it — so normalise rather than assume, otherwise half
 * the rows read `##general`. Falls back to the raw id when there is no name (a hand-entered channel), and to
 * an em dash when there is neither.
 */
export function channelDisplay(name: string | null, id: string | null): string {
    if (name) return `#${name.replace(/^#+/, '')}`;
    return id ?? '—';
}

/**
 * MdsSelect options for the fetched channels.
 *
 * A channel the app has not been invited to is listed and SELECTABLE, not disabled: Slack lets an admin invite
 * the app after the fact, so refusing the choice would block a workflow that works. The suffix marks it and
 * {@link channelWarning} explains it — informing beats forbidding when the tenant can fix it in ten seconds.
 *
 * When a rule already points at a channel that is no longer in the list (deleted, archived, or beyond a
 * truncated page), that id is prepended as its own option so opening the edit modal cannot silently blank a
 * destination the tenant never touched.
 */
export function toChannelOptions(channels: Channel[], selectedId?: string | null): Option[] {
    const options: Option[] = channels.map((channel) => ({
        value: channel.id,
        label: channel.available ? channel.label : `${channel.label} (app not in channel)`,
    }));

    if (selectedId && !channels.some((channel) => channel.id === selectedId)) {
        options.unshift({ value: selectedId, label: `${selectedId} (current)` });
    }

    return options;
}

/**
 * The warning to show under the picker for the current selection, or null when there is nothing to say.
 * Returns null for an unknown id: we cannot claim the app is missing from a channel we know nothing about.
 */
export function channelWarning(channels: Channel[], selectedId: string | null | undefined): string | null {
    if (!selectedId) return null;

    const channel = channels.find((c) => c.id === selectedId);
    if (!channel || channel.available) return null;

    return `The app isn’t in ${channel.label} yet. Invite it in Slack with /invite, or messages to this channel will fail.`;
}
