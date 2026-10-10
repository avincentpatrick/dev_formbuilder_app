/**
 * M155 (`R-77a29731`) — the upload control's screen-reader text stays off the screen.
 *
 * Three nodes are for a screen reader only: the "(required)" beside the question, the name inside each ✕ ("Remove
 * {name}"), and the polite announcement after each upload. All three carried `mds-visually-hidden`, a class no
 * stylesheet defines, so every one of them was drawn as ordinary text on the encode page and in the guest runtime.
 *
 * ⚠️ happy-dom computes no layout, so a mounted node is "visible" here whatever the CSS says — which is how the
 * defect passed every test. What CAN be held here is the thing that was actually wrong: the class on each hidden node
 * must be one this component's own stylesheet clips. The rendered box itself is measured by
 * `tests/e2e/media-input-hidden-text.spec.ts`.
 */
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { mount, type DOMWrapper } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import type { EncodeField } from './FieldInput.vue';
import MediaInput from './MediaInput.vue';

const SOURCE = readFileSync(join(process.cwd(), 'resources', 'js', 'components', 'submissions', 'MediaInput.vue'), 'utf-8');
const STYLE = SOURCE.slice(SOURCE.indexOf('<style'));

function photoField(): EncodeField {
    return {
        key: 'site_photo',
        field_type: 'image_capture',
        label: 'Photo of the site',
        hint: null,
        placeholder: null,
        required: true,
        options: [],
        supported: true,
        media: { acceptedTypes: ['image/png'], maxFileSizeBytes: null, maxCount: 3, minCount: null, captureSource: null },
    };
}

/** The declarations of `.cls { … }` in this component's own stylesheet, or null when it defines no such rule. */
function ruleFor(cls: string): string | null {
    const match = new RegExp(`\\.${cls}\\s*\\{([^}]*)\\}`).exec(STYLE);

    return match === null ? null : match[1];
}

function expectClippedHere(name: string, node: DOMWrapper<Element>): void {
    const classes = node.classes();
    expect(classes.length, `${name} carries a class`).toBeGreaterThan(0);

    const clipped = classes.filter((cls) => {
        const rule = ruleFor(cls);

        return rule !== null && /position:\s*absolute/.test(rule) && /clip:\s*rect\(0 0 0 0\)/.test(rule);
    });
    expect(clipped, `${name}'s classes (${classes.join(' ')}) — one must be clipped by this component's own stylesheet`).not.toEqual([]);
}

describe('MediaInput — the text only a screen reader is meant to hear (M155)', () => {
    it('clips the "(required)" beside the question and the upload announcement with its own rule', () => {
        const wrapper = mount(MediaInput, { props: { field: photoField(), modelValue: null } });

        const required = wrapper.findAll('legend span').find((span) => span.text() === '(required)');
        expect(required, 'the "(required)" text is in the page').toBeDefined();
        expectClippedHere('the "(required)" text', required!);
        expectClippedHere('the announcement', wrapper.find('[aria-live="polite"]'));
    });

    it('clips the name inside each remove button with the same rule', () => {
        const wrapper = mount(MediaInput, {
            props: { field: photoField(), modelValue: [{ id: 'att-1', name: 'site-photo.png', mime: 'image/png' }] },
        });

        const label = wrapper.find('button.media__remove').findAll('span').find((span) => span.text() === 'Remove site-photo.png');
        expect(label, 'the remove button names the file for a screen reader').toBeDefined();
        expectClippedHere('the remove button\'s name', label!);
    });
});
