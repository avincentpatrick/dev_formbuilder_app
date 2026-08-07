import { mount, type VueWrapper } from '@vue/test-utils';
import { reactive } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * The shell's Send Feedback panel and its screenshot capture (Increment I7a / ADR-0015).
 *
 * The load-bearing assertions are the THREE DEGRADATION ARMS, none of which any other gate can see:
 * a browser without the Screen Capture API, a user who declines the permission prompt, and a capture
 * that throws. PRD Feature #11 requires that submitting feedback never blocks or interrupts the task the
 * user was in the middle of — so in every one of those cases the report must still be sendable, and the
 * file input must still be there. A regression here would look like a working feature to anyone testing
 * on Chrome with the prompt accepted, which is everyone.
 */

const mocks = vi.hoisted(() => ({
    post: vi.fn(),
    reset: vi.fn(),
    clearErrors: vi.fn(),
    capture: vi.fn(),
    supported: vi.fn(() => true),
}));

vi.mock('@inertiajs/vue3', () => ({
    useForm: (initial: Record<string, unknown>) => {
        // MUST be reactive, or the component's computeds freeze against the initial snapshot.
        const form = reactive({
            ...initial,
            errors: {} as Record<string, string>,
            processing: false,
            post: mocks.post,
            reset: mocks.reset,
            clearErrors: mocks.clearErrors,
        });

        return form;
    },
}));

vi.mock('./screenshot', () => ({
    captureScreenshot: mocks.capture,
    isCaptureSupported: mocks.supported,
}));

// Imported AFTER the mocks so the component resolves them.
const FeedbackButton = (await import('./FeedbackButton.vue')).default;

function render(): VueWrapper {
    // `teleport: true` or MdsModal's panel renders outside the wrapper and find() sees nothing.
    return mount(FeedbackButton, { global: { stubs: { teleport: true } } });
}

async function openPanel(wrapper: VueWrapper): Promise<void> {
    await wrapper.find('button[aria-label="Send feedback"]').trigger('click');
}

function captureButton(wrapper: VueWrapper) {
    return wrapper.findAll('button').find((button) => button.text().includes('Capture screen'));
}

beforeEach(() => {
    mocks.post.mockClear();
    mocks.capture.mockReset();
    mocks.supported.mockReset().mockReturnValue(true);

    // happy-dom has no object-URL implementation worth relying on; the component only needs a string back.
    vi.stubGlobal('URL', {
        ...URL,
        createObjectURL: vi.fn(() => 'blob:preview'),
        revokeObjectURL: vi.fn(),
    });
});

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('feedback panel — capture', () => {
    it('attaches the captured PNG and shows a preview', async () => {
        const file = new File(['x'], 'screenshot.png', { type: 'image/png' });
        mocks.capture.mockResolvedValue(file);

        const wrapper = render();
        await openPanel(wrapper);
        await captureButton(wrapper)!.trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.find('img[alt="Preview of the screenshot you attached"]').exists()).toBe(true);
        // Once something is attached the affordance becomes "replace it", not "add another".
        expect(wrapper.text()).toContain('Recapture');

        wrapper.unmount();
    });

    it('removing the screenshot restores the empty state', async () => {
        mocks.capture.mockResolvedValue(new File(['x'], 'screenshot.png', { type: 'image/png' }));

        const wrapper = render();
        await openPanel(wrapper);
        await captureButton(wrapper)!.trigger('click');
        await wrapper.vm.$nextTick();

        const remove = wrapper.findAll('button').find((button) => button.text() === 'Remove');
        await remove!.trigger('click');

        expect(wrapper.find('img[alt="Preview of the screenshot you attached"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('Capture screen');

        wrapper.unmount();
    });
});

describe('feedback panel — the three degradation arms', () => {
    it('a decline attaches nothing, surfaces no error, and leaves the report sendable', async () => {
        // captureScreenshot() resolves null on NotAllowedError/AbortError: declining is the user using the
        // choice the prompt exists to offer, not a failure.
        mocks.capture.mockResolvedValue(null);

        const wrapper = render();
        await openPanel(wrapper);
        await captureButton(wrapper)!.trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.find('img[alt="Preview of the screenshot you attached"]').exists()).toBe(false);
        expect(wrapper.text()).not.toMatch(/error|failed|denied/i);
        // The capture affordance survives a decline — the user may simply have picked the wrong window.
        expect(captureButton(wrapper)).toBeDefined();
        expect(wrapper.find('input[type="file"]').exists()).toBe(true);

        wrapper.unmount();
    });

    it('a browser without the API hides the capture button but keeps the file input', async () => {
        mocks.supported.mockReturnValue(false);

        const wrapper = render();
        await openPanel(wrapper);

        expect(captureButton(wrapper)).toBeUndefined();
        // The fallback is not a fallback if nobody can find it.
        expect(wrapper.find('input[type="file"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Attach an image');

        wrapper.unmount();
    });

    it('a thrown capture drops the affordance instead of inviting a retry that cannot work', async () => {
        mocks.capture.mockRejectedValue(new Error('getDisplayMedia exploded'));

        const wrapper = render();
        await openPanel(wrapper);
        await captureButton(wrapper)!.trigger('click');
        await wrapper.vm.$nextTick();

        expect(captureButton(wrapper)).toBeUndefined();
        expect(wrapper.find('input[type="file"]').exists()).toBe(true);

        wrapper.unmount();
    });
});

describe('feedback panel — submit', () => {
    it('posts to the tenant endpoint preserving scroll, so the reporter never loses their place', async () => {
        const wrapper = render();
        await openPanel(wrapper);

        const send = wrapper.findAll('button').find((button) => button.text() === 'Send feedback');
        await send!.trigger('click');

        expect(mocks.post).toHaveBeenCalledTimes(1);
        expect(mocks.post.mock.calls[0][0]).toBe('/feedback');
        expect(mocks.post.mock.calls[0][1]).toMatchObject({ preserveScroll: true });

        wrapper.unmount();
    });
});
