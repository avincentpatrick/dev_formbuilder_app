/**
 * M187 (`R-af2d278b`) — a Signature's Media tab says how people sign, and offers none of a photo's settings: the pad always
 * saves one PNG, and the server takes a signature's file types from its own default whatever the question says.
 */
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import MediaEditor from './MediaEditor.vue';

function editor(fieldType: string) {
    return mount(MediaEditor, {
        props: { fieldType, acceptedTypes: [], maxFileSizeBytes: null, maxCount: null, minCount: null, captureSource: null },
    });
}

describe('MediaEditor (M187)', () => {
    it('tells the author how a Signature is signed, with no file settings', () => {
        const wrapper = editor('signature');

        expect(wrapper.text()).toContain('How it is signed');
        expect(wrapper.text()).toContain('People sign with a finger, a pen or the mouse');
        expect(wrapper.text()).not.toContain('Accepted file types');
        expect(wrapper.text()).not.toContain('How many');
        expect(wrapper.findAll('input')).toHaveLength(0);
    });

    it('keeps every setting for a photo', () => {
        const wrapper = editor('image_capture');

        expect(wrapper.text()).toContain('Accepted file types');
        expect(wrapper.text()).toContain('How many');
        expect(wrapper.text()).toContain('Capture');
        expect(wrapper.text()).not.toContain('How it is signed');
    });
});
