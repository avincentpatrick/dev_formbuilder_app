/**
 * M187 (`R-af2d278b`) — the Signature pad: what it saves, and when.
 *
 * happy-dom draws nothing, so the picture itself is `signature-canvas.ts`'s and is stubbed here; what is held is the
 * pad's behaviour around it — a signature of several strokes is saved ONCE, after the pen rests; a tap is never saved;
 * the newest drawing's reference is the only one ever emitted; Clear, a restored answer and the Preview each say what
 * they should. The real pointer path and the uploaded PNG are measured by `tests/e2e/signature.spec.ts`.
 */
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import type { EncodeField, MediaAttachmentRef } from './FieldInput.vue';
import SignaturePad from './SignaturePad.vue';
import { uploadMediaFile } from './media-upload';
import { SETTLE_MS } from './signature-canvas';

vi.mock('./media-upload', () => ({ uploadMediaFile: vi.fn() }));
vi.mock('./signature-canvas', async (importOriginal) => {
    const actual = await importOriginal<typeof import('./signature-canvas')>();

    return { ...actual, exportPng: vi.fn(async () => new Blob(['png'], { type: 'image/png' })) };
});

const upload = vi.mocked(uploadMediaFile);

function signatureField(overrides: Partial<EncodeField> = {}): EncodeField {
    return {
        key: 'signed',
        field_type: 'signature',
        label: 'Signature of the head of household',
        hint: null,
        placeholder: null,
        required: true,
        options: [],
        supported: true,
        media: { acceptedTypes: [], maxFileSizeBytes: null, maxCount: 1, minCount: null, captureSource: null },
        upload: { url: '/forms/f1/attachments' },
        ...overrides,
    };
}

function mountPad(props: { field?: EncodeField; modelValue?: MediaAttachmentRef[] | null } = {}): VueWrapper {
    const wrapper = mount(SignaturePad, { props: { field: props.field ?? signatureField(), modelValue: props.modelValue ?? null } });
    stubRect(wrapper);

    return wrapper;
}

function stubRect(wrapper: VueWrapper): void {
    const canvas = wrapper.find('canvas');
    if (canvas.exists()) {
        (canvas.element as HTMLCanvasElement).getBoundingClientRect = () =>
            ({ left: 0, top: 0, width: 300, height: 100, right: 300, bottom: 100, x: 0, y: 0, toJSON: () => ({}) }) as DOMRect;
    }
}

/** One stroke from (x1, y) to (x2, y), in pad pixels. */
async function stroke(wrapper: VueWrapper, x1: number, x2: number, y = 50): Promise<void> {
    const canvas = wrapper.find('canvas');
    await canvas.trigger('pointerdown', { pointerId: 1, pointerType: 'mouse', button: 0, clientX: x1, clientY: y });
    await canvas.trigger('pointermove', { pointerId: 1, pointerType: 'mouse', clientX: (x1 + x2) / 2, clientY: y + 10 });
    await canvas.trigger('pointermove', { pointerId: 1, pointerType: 'mouse', clientX: x2, clientY: y });
    await canvas.trigger('pointerup', { pointerId: 1, pointerType: 'mouse', clientX: x2, clientY: y });
}

async function rest(): Promise<void> {
    vi.advanceTimersByTime(SETTLE_MS);
    await flushPromises();
}

function emitted(wrapper: VueWrapper): unknown[] {
    return (wrapper.emitted('update:modelValue') ?? []).map((args) => args[0]);
}

beforeEach(() => {
    vi.useFakeTimers();
    upload.mockReset();
});

afterEach(() => {
    vi.useRealTimers();
});

describe('SignaturePad (M187)', () => {
    it('saves a signature of several strokes once, after the pen rests, and answers with its reference', async () => {
        upload.mockResolvedValue({ id: 'att-1', mime: 'image/png', name: 'signature.png' });
        const wrapper = mountPad();

        await stroke(wrapper, 20, 120);
        vi.advanceTimersByTime(SETTLE_MS / 2);
        await stroke(wrapper, 140, 260); // the second stroke of the same signature, before the pen rested
        expect(upload).not.toHaveBeenCalled();

        await rest();

        expect(upload).toHaveBeenCalledTimes(1);
        const [config, key, file] = upload.mock.calls[0];
        expect(config).toEqual({ url: '/forms/f1/attachments' });
        expect(key).toBe('signed');
        expect(file.name).toBe('signature.png');
        expect(file.type).toBe('image/png');
        expect(emitted(wrapper)).toEqual([[{ id: 'att-1', mime: 'image/png', name: 'signature.png' }]]);
        expect(wrapper.text()).toContain('Saved');
    });

    it('never saves a tap', async () => {
        const wrapper = mountPad();
        const canvas = wrapper.find('canvas');

        await canvas.trigger('pointerdown', { pointerId: 1, pointerType: 'touch', clientX: 40, clientY: 40 });
        await canvas.trigger('pointerup', { pointerId: 1, pointerType: 'touch', clientX: 40, clientY: 40 });
        await rest();

        expect(upload).not.toHaveBeenCalled();
        expect(emitted(wrapper)).toEqual([]);
    });

    it('emits only the newest drawing when a stroke begins while a save is in flight', async () => {
        let finishFirst: (ref: MediaAttachmentRef) => void = () => undefined;
        upload
            .mockImplementationOnce(() => new Promise((resolve) => (finishFirst = resolve)))
            .mockResolvedValueOnce({ id: 'att-2', mime: 'image/png' });
        const wrapper = mountPad();

        await stroke(wrapper, 20, 120);
        await rest(); // the first save is now in flight
        await stroke(wrapper, 140, 260); // …and the person adds a stroke
        finishFirst({ id: 'att-1', mime: 'image/png' });
        await flushPromises();
        await rest();

        expect(upload).toHaveBeenCalledTimes(2);
        expect(emitted(wrapper)).toEqual([[{ id: 'att-2', mime: 'image/png' }]]);
    });

    it('clears the pad and the answer', async () => {
        upload.mockResolvedValue({ id: 'att-1', mime: 'image/png' });
        const wrapper = mountPad();
        await stroke(wrapper, 20, 200);
        await rest();

        await wrapper.findAll('button').find((b) => b.text() === 'Clear')!.trigger('click');

        expect(emitted(wrapper)).toEqual([[{ id: 'att-1', mime: 'image/png' }], null]);
        expect(wrapper.text()).toContain('Sign here');
    });

    it('shows an answer it did not draw as Signed, and Sign again empties it for a new signature', async () => {
        const wrapper = mountPad({ modelValue: [{ id: 'att-9', mime: 'image/png' }] });

        expect(wrapper.find('canvas').exists()).toBe(false);
        expect(wrapper.text()).toContain('Signed');

        await wrapper.findAll('button').find((b) => b.text() === 'Sign again')!.trigger('click');

        expect(emitted(wrapper)).toEqual([null]);
        expect(wrapper.find('canvas').exists()).toBe(true);
    });

    it('draws in the Preview without saving, and says so', async () => {
        const wrapper = mountPad({ field: signatureField({ upload: null }) });

        await stroke(wrapper, 20, 200);
        await rest();

        expect(upload).not.toHaveBeenCalled();
        expect(emitted(wrapper)).toEqual([]);
        expect(wrapper.text()).toContain('Not saved in the Preview');
    });

    it('says when a save fails, and Retry saves the same drawing', async () => {
        upload.mockRejectedValueOnce(new Error('The upload was rejected.')).mockResolvedValueOnce({ id: 'att-3', mime: 'image/png' });
        const wrapper = mountPad();
        await stroke(wrapper, 20, 200);
        await rest();

        expect(wrapper.text()).toContain('The upload was rejected.');
        expect(emitted(wrapper)).toEqual([]);

        await wrapper.findAll('button').find((b) => b.text() === 'Retry')!.trigger('click');
        await flushPromises();

        expect(emitted(wrapper)).toEqual([[{ id: 'att-3', mime: 'image/png' }]]);
    });

    it('names the pad for a screen reader and announces what happened', async () => {
        upload.mockResolvedValue({ id: 'att-1', mime: 'image/png' });
        const wrapper = mountPad();

        expect(wrapper.find('canvas').attributes('role')).toBe('img');
        expect(wrapper.find('canvas').attributes('aria-label')).toContain('Signature of the head of household');

        await stroke(wrapper, 20, 200);
        await rest();

        expect(wrapper.find('[aria-live="polite"]').text()).toBe('Signature saved.');
    });
});
