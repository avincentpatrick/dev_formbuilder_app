import { describe, expect, it } from 'vitest';
import { channelDisplay, channelWarning, toChannelOptions } from './channel-options';
import type { Channel } from './types';

const channel = (id: string, label: string, available = true): Channel => ({
    id,
    label,
    available,
    unavailable_reason: available ? null : 'not_a_member',
});

describe('toChannelOptions', () => {
    it('maps channels to select options in the order given', () => {
        const options = toChannelOptions([channel('C1', '#alpha'), channel('C2', '#beta')]);

        expect(options).toEqual([
            { value: 'C1', label: '#alpha' },
            { value: 'C2', label: '#beta' },
        ]);
    });

    it('marks a channel the app has not joined but still offers it', () => {
        // Slack lets an admin invite the app afterwards, so this must stay selectable — informing beats
        // forbidding when the tenant can fix it in ten seconds.
        const options = toChannelOptions([channel('C1', '#random', false)]);

        expect(options).toEqual([{ value: 'C1', label: '#random (app not in channel)' }]);
    });

    it('keeps a current selection that is missing from the list', () => {
        // The channel was archived, deleted, or fell past a truncated page — opening the edit modal must not
        // silently blank a destination the tenant never touched.
        const options = toChannelOptions([channel('C1', '#alpha')], 'C-GONE');

        expect(options[0]).toEqual({ value: 'C-GONE', label: 'C-GONE (current)' });
        expect(options).toHaveLength(2);
    });

    it('does not duplicate a selection that is present', () => {
        const options = toChannelOptions([channel('C1', '#alpha')], 'C1');

        expect(options).toHaveLength(1);
    });

    it('handles an empty list and an empty selection', () => {
        expect(toChannelOptions([])).toEqual([]);
        expect(toChannelOptions([], '')).toEqual([]);
        expect(toChannelOptions([], null)).toEqual([]);
    });
});

describe('channelWarning', () => {
    it('warns, actionably, when the app is not in the selected channel', () => {
        const warning = channelWarning([channel('C1', '#random', false)], 'C1');

        expect(warning).toContain('#random');
        expect(warning).toContain('/invite');
    });

    it('says nothing when the selected channel is joined', () => {
        expect(channelWarning([channel('C1', '#alpha')], 'C1')).toBeNull();
    });

    it('says nothing when nothing is selected', () => {
        expect(channelWarning([channel('C1', '#alpha', false)], null)).toBeNull();
        expect(channelWarning([channel('C1', '#alpha', false)], '')).toBeNull();
    });

    it('says nothing about a channel it knows nothing about', () => {
        // We cannot claim the app is missing from a channel that is not in the list we fetched.
        expect(channelWarning([channel('C1', '#alpha')], 'C-UNKNOWN')).toBeNull();
    });
});

describe('channelDisplay', () => {
    it('prefixes a bare name with a single hash', () => {
        expect(channelDisplay('general', 'C1')).toBe('#general');
    });

    it('does not double a hash that is already stored', () => {
        // Rules created through the API (or seeded) may store "#general"; the picker stores "general".
        expect(channelDisplay('#general', 'C1')).toBe('#general');
        expect(channelDisplay('##general', 'C1')).toBe('#general');
    });

    it('falls back to the raw id when there is no name', () => {
        expect(channelDisplay(null, 'C0123456789')).toBe('C0123456789');
    });

    it('falls back to an em dash when there is neither', () => {
        expect(channelDisplay(null, null)).toBe('—');
    });
});
