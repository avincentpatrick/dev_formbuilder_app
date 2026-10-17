import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
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
 *
 * M175 (`R-2ce2f274`, ADR-0015 §D11) adds the STEP ASIDE: the panel was in every captured frame, because the
 * frame was taken while it was open. The capture now acquires the stream inside the click, closes the
 * panel, waits for the modal's `after-leave` (or a short timer), takes the frame, and reopens the panel with
 * the report as it was. `transition: false` runs the modal's REAL <Transition> so `after-leave` fires; the
 * test-utils default stub never calls its hooks, which is exactly the case the timer exists for.
 */

const mocks = vi.hoisted(() => ({
    post: vi.fn(),
    reset: vi.fn(),
    clearErrors: vi.fn(),
    acquire: vi.fn(),
    supported: vi.fn(() => true),
    lastForm: null as null | Record<string, unknown>,
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
        mocks.lastForm = form as Record<string, unknown>;

        return form;
    },
    usePage: () => ({ component: 'forms/Builder', url: '/forms/1/builder', props: {} }),
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

vi.mock('./screenshot', () => ({
    acquireScreen: mocks.acquire,
    isCaptureSupported: mocks.supported,
}));

// Imported AFTER the mocks so the component resolves them.
const FeedbackButton = (await import('./FeedbackButton.vue')).default;

function render(options: { realTransition?: boolean } = {}): VueWrapper {
    // `teleport: true` or MdsModal's panel renders outside the wrapper and find() sees nothing.
    return mount(FeedbackButton, {
        global: { stubs: { teleport: true, transition: options.realTransition === false ? true : false } },
    });
}

async function openPanel(wrapper: VueWrapper): Promise<void> {
    await wrapper.find('button[aria-label="Send feedback"]').trigger('click');
}

function captureButton(wrapper: VueWrapper) {
    return wrapper.findAll('button').find((button) => button.text().includes('Capture screen'));
}

function dialogShown(wrapper: VueWrapper): boolean {
    return wrapper.find('[role="dialog"]').exists();
}

/** A stand-in for the live capture `acquireScreen()` resolves: a frame on demand, and a stop. */
function screenWith(file: File | null, onGrab: () => void = () => {}) {
    return {
        grab: vi.fn(async () => {
            onGrab();
            return file;
        }),
        stop: vi.fn(),
    };
}

const png = (): File => new File(['x'], 'screenshot.png', { type: 'image/png' });

beforeEach(() => {
    mocks.post.mockClear();
    mocks.reset.mockClear();
    mocks.acquire.mockReset();
    mocks.supported.mockReset().mockReturnValue(true);
    mocks.lastForm = null;

    // happy-dom has no object-URL implementation worth relying on; the component only needs a string back.
    vi.stubGlobal('URL', {
        ...URL,
        createObjectURL: vi.fn(() => 'blob:preview'),
        revokeObjectURL: vi.fn(),
    });
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

describe('feedback panel — capture', () => {
    it('attaches the captured PNG and shows a preview', async () => {
        mocks.acquire.mockResolvedValue(screenWith(png()));

        const wrapper = render();
        await openPanel(wrapper);
        await captureButton(wrapper)!.trigger('click');
        await vi.waitFor(() => expect(wrapper.find('img[alt="Preview of the screenshot you attached"]').exists()).toBe(true));

        // Once something is attached the affordance becomes "replace it", not "add another".
        expect(wrapper.text()).toContain('Recapture');

        wrapper.unmount();
    });

    it('removing the screenshot restores the empty state', async () => {
        mocks.acquire.mockResolvedValue(screenWith(png()));

        const wrapper = render();
        await openPanel(wrapper);
        await captureButton(wrapper)!.trigger('click');
        await vi.waitFor(() => expect(wrapper.find('img[alt="Preview of the screenshot you attached"]').exists()).toBe(true));

        const remove = wrapper.findAll('button').find((button) => button.text() === 'Remove');
        await remove!.trigger('click');

        expect(wrapper.find('img[alt="Preview of the screenshot you attached"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('Capture screen');

        wrapper.unmount();
    });
});

describe('feedback panel — the panel steps aside for the capture (M175, ADR-0015 §D11)', () => {
    it('is off the screen when the frame is taken, and comes back with the report as it was', async () => {
        let dialogAtGrab: boolean | null = null;
        const wrapper = render();
        const screen = screenWith(png(), () => {
            dialogAtGrab = dialogShown(wrapper);
        });
        mocks.acquire.mockResolvedValue(screen);

        await openPanel(wrapper);
        await wrapper.find('textarea').setValue('The save button does nothing');
        await wrapper.find('input[type="radio"][value="bug"]').setValue(true);
        await captureButton(wrapper)!.trigger('click');

        await vi.waitFor(() => expect(screen.grab).toHaveBeenCalledTimes(1));
        await vi.waitFor(() => expect(dialogShown(wrapper)).toBe(true));

        // The frame was taken with no panel on the page — the whole point of the row.
        expect(dialogAtGrab).toBe(false);
        // M176 (`R-8496edfa`): and the grab is told WHEN the panel left, so it draws only a frame captured after that.
        expect(typeof screen.grab.mock.calls[0]?.[0]).toBe('number');
        // …and the reporter finds what they typed and chose, plus the picture, exactly where they left it.
        expect((wrapper.find('textarea').element as HTMLTextAreaElement).value).toBe('The save button does nothing');
        expect((wrapper.find('input[type="radio"][value="bug"]').element as HTMLInputElement).checked).toBe(true);
        expect(wrapper.find('img[alt="Preview of the screenshot you attached"]').exists()).toBe(true);
        // Reopening is NOT openPanel(): that resets the form, which would throw the report away.
        expect(mocks.reset).toHaveBeenCalledTimes(1);
        // The browser's "sharing your screen" indicator must not outlive the frame.
        expect(screen.stop).toHaveBeenCalledTimes(1);

        wrapper.unmount();
    });

    it('takes the frame within 400ms even when the panel never reports that it has left', async () => {
        // M176 (`R-8496edfa`): then two animation frames for the page without the panel to be painted, before the grab —
        // faked too, so the case still measures the 400ms cap and not this browser's frame rate.
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'setInterval', 'clearInterval', 'Date', 'performance', 'requestAnimationFrame'] });
        const screen = screenWith(png());
        mocks.acquire.mockResolvedValue(screen);

        // The test-utils transition stub never calls the leave hooks — the stand-in for a browser that
        // skips the transition, or a modal that never says so.
        const wrapper = render({ realTransition: false });
        await openPanel(wrapper);
        await captureButton(wrapper)!.trigger('click');
        await flushPromises();

        expect(screen.grab).not.toHaveBeenCalled();
        await vi.advanceTimersByTimeAsync(400);
        await vi.advanceTimersByTimeAsync(40); // the two painted frames
        expect(screen.grab).toHaveBeenCalledTimes(1);

        await vi.advanceTimersByTimeAsync(50);
        expect(dialogShown(wrapper)).toBe(true);

        wrapper.unmount();
    });
});

describe('feedback panel — the three degradation arms', () => {
    it('a decline attaches nothing, surfaces no error, never closes the panel, and leaves the report sendable', async () => {
        // acquireScreen() resolves null on NotAllowedError/AbortError: declining is the user using the
        // choice the prompt exists to offer, not a failure.
        mocks.acquire.mockResolvedValue(null);

        const wrapper = render();
        await openPanel(wrapper);
        await captureButton(wrapper)!.trigger('click');
        await flushPromises();

        expect(dialogShown(wrapper)).toBe(true);
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
        mocks.acquire.mockRejectedValue(new Error('getDisplayMedia exploded'));

        const wrapper = render();
        await openPanel(wrapper);
        await captureButton(wrapper)!.trigger('click');
        await flushPromises();

        expect(captureButton(wrapper)).toBeUndefined();
        expect(wrapper.find('input[type="file"]').exists()).toBe(true);

        wrapper.unmount();
    });
});

describe('feedback panel — what the report says (M175, `R-2ce2f274`)', () => {
    it('asks what kind of feedback it is: a bug, a suggestion or a question', async () => {
        const wrapper = render();
        await openPanel(wrapper);

        const radios = wrapper.findAll('input[type="radio"]');
        expect(radios.map((radio) => (radio.element as HTMLInputElement).value)).toEqual(['bug', 'suggestion', 'question']);
        // One group, so the arrow keys rove between them and the legend is the question they answer.
        expect(new Set(radios.map((radio) => radio.attributes('name'))).size).toBe(1);
        expect(wrapper.find('fieldset legend').text()).toMatch(/what kind/i);

        await wrapper.find('input[type="radio"][value="suggestion"]').setValue(true);
        expect(mocks.lastForm!.type).toBe('suggestion');

        wrapper.unmount();
    });

    it('sends the full page address, its title and the screen it was on, not only the path', async () => {
        document.title = 'Builder — Household survey';
        const wrapper = render();
        await openPanel(wrapper);

        const form = mocks.lastForm!;
        expect(form.page_url).toBe(window.location.href);
        expect(form.page_title).toBe('Builder — Household survey');
        expect(form.route).toBe(window.location.pathname);
        expect(form.browser_info).toMatchObject({ component: 'forms/Builder', bundle: 'dev' });

        wrapper.unmount();
    });

    it('offers the reporter their own reports, where a status and a reply are read (M175, `R-9bfd832e`)', async () => {
        const wrapper = render();
        await openPanel(wrapper);

        const link = wrapper.find('a[href="/feedback/mine"]');
        expect(link.exists()).toBe(true);
        expect(link.text()).toContain('See your reports');

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
