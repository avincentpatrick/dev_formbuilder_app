import { describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';
import SaveForLater from '../components/SaveForLater.vue';
import { DraftFlowKey } from '../composables/context';
import { ApiError } from '../lib/api-client';
import { normalizeError } from '../lib/error-normalizer';
import type { DraftFlow, DraftSaveResult } from '../composables/context';

/**
 * Increment M14 — the first test to mount `SaveForLater` at all, which is why the second backlog row could
 * describe two silent swallows in a 191-line component and no gate noticed.
 *
 * ⚠️ `MdsModal` TELEPORTS TO `body` BY DEFAULT (`Modal.vue`'s `teleport` prop defaults true) and this
 * component does not pass `teleport`, so the panel's contents land outside the wrapper's own tree. Every case
 * stubs Teleport so the modal renders in place and the assertions can use the wrapper rather than reaching
 * into `document.body` — the first draft of this file did the latter and could not drive `MdsTextInput`'s
 * v-model at all, because a native `input` event on a detached node never reaches Vue's handler.
 */
function conflict(code: string, message: string): ApiError {
    return new ApiError(normalizeError(409, { error: { code, message } }));
}

function flow(saveDraft: DraftFlow['saveDraft']): DraftFlow {
    return { saving: ref(false), completeness: ref(null), saveDraft };
}

function render(saveDraft: DraftFlow['saveDraft']) {
    return mount(SaveForLater, {
        global: { provide: { [DraftFlowKey as symbol]: flow(saveDraft) } , stubs: { teleport: true } },
    });
}

const saved: DraftSaveResult = { resumeUrl: 'https://acme.test/f/resume/rt-1', emailed: false };

/** The panel's own trigger is the first button; the modal's own buttons follow it. */
async function openPanel(wrapper: ReturnType<typeof render>): Promise<void> {
    await wrapper.get('button').trigger('click');
    await flushPromises();
}

function clickButton(wrapper: ReturnType<typeof render>, label: string): Promise<void> {
    const button = wrapper.findAll('button').find((b) => b.text().includes(label));
    if (button === undefined) {
        throw new Error(`no button labelled ${label}`);
    }

    return button.trigger('click');
}

describe('SaveForLater', () => {
    it('SAYS WHAT HAPPENED when the save is refused, instead of closing with nothing', async () => {
        // ⚠️ THE SECOND BACKLOG ROW, PINNED. A `draft_already_finalized` remounts nothing, so before M14 it
        // arrived here as `null` — indistinguishable from "a drift is remounting the session" — and the panel
        // simply closed. A deliberate "Save and finish later" produced no save, no resume link and no error,
        // and the respondent had no way to know any of that.
        const wrapper = render(
            vi.fn(async () => {
                throw conflict('draft_already_finalized', 'This draft has already been submitted and can no longer be saved as a draft.');
            }),
        );

        await openPanel(wrapper);

        expect(wrapper.text()).toContain('already been submitted');
        expect(wrapper.find('[role="alert"]').exists()).toBe(true);

        wrapper.unmount();
    });

    it('shows the SERVER’s sentence rather than one generic apology for every refusal', async () => {
        // The reason `catch` binds its error at all. Two 409s that used to render identically now differ,
        // and the difference is the only thing telling a respondent which refusal they hit — this project
        // has already recorded that the message is what a person actually reads.
        const wrapper = render(
            vi.fn(async () => {
                throw conflict('submission_uuid_claimed', 'This submission identifier already belongs to another response.');
            }),
        );

        await openPanel(wrapper);

        expect(wrapper.text()).toContain('already belongs to another response');
        expect(wrapper.text()).not.toContain('We couldn’t save your progress just now');

        wrapper.unmount();
    });

    it('keeps the generic apology for a failure that carries no server sentence', async () => {
        // The control for the two above. A dropped connection is not an `ApiError` and has no message worth
        // showing, so the pre-M14 copy is still exactly right — binding the error must not turn every
        // network blip into a raw exception string.
        const wrapper = render(
            vi.fn(async () => {
                throw new TypeError('Failed to fetch');
            }),
        );

        await openPanel(wrapper);

        expect(wrapper.text()).toContain('We couldn’t save your progress just now');

        wrapper.unmount();
    });

    it('still closes silently when the session is remounting, which is the one case null means now', async () => {
        // ⚠️ A CONTROL THAT MUST SURVIVE EVERY MUTATION ABOVE, and the reason M14 narrowed `null` rather than
        // deleting it. A republish (or a draft re-read) really does remount the session a beat later, taking
        // this component with it — so a message here would flash and vanish, and the banner on the remounted
        // session is what explains it. Silence is correct HERE and was only ever wrong because four other
        // causes were reaching it too.
        const wrapper = render(vi.fn(async () => null));

        await openPanel(wrapper);

        expect(wrapper.find('[role="alert"]').exists()).toBe(false);

        wrapper.unmount();
    });

    it('does not swallow a refusal on the EMAIL action either — the swallow the row does not name', async () => {
        // `onEmail` did `if (result !== null)` and had a `catch` that bound nothing, so a refusal while
        // emailing the link produced no message on a panel that was already open and staying open. A
        // different function from `onOpen`, a different swallow, and its own case: reverting either one
        // leaves the other green.
        const saveDraft = vi
            .fn<DraftFlow['saveDraft']>()
            .mockResolvedValueOnce(saved)
            .mockRejectedValueOnce(conflict('draft_conflict', 'This draft was updated on another device. Reload it to pick up the newer answers before saving again.'));
        const wrapper = render(saveDraft);

        await openPanel(wrapper);
        expect(wrapper.text()).toContain('Your progress is saved');

        await wrapper.get('input[type="email"]').setValue('ada@example.test');
        await clickButton(wrapper, 'Email me the link');
        await flushPromises();

        expect(wrapper.text()).toContain('updated on another device');
        // The link is still on screen and still works, so this arm names copying as the way out.
        expect(wrapper.text()).toContain('copy the link above');

        wrapper.unmount();
    });

    it('renders nothing at all when the form has save-and-resume disabled', async () => {
        // `useDraftFlow()` injects with a null DEFAULT rather than throwing, unlike its three siblings — that
        // asymmetry IS the self-hiding contract, so it is asserted rather than left to be rediscovered.
        // ⚠️ Stated limit: this case passes vacuously on a component that renders nothing for any reason. It
        // is only meaningful beside the cases above, which prove the provided path renders.
        const wrapper = mount(SaveForLater, { global: { stubs: { teleport: true } } });

        expect(wrapper.find('button').exists()).toBe(false);

        wrapper.unmount();
    });
});
