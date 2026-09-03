import { beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick, ref, reactive } from 'vue';

import { createServerAutosave, type ServerAutosaveOptions } from '../useServerAutosave';

/**
 * Increment I9b — the manual-encode autosave.
 *
 * Every case here is a failure mode rather than a happy path, because the happy path ("it eventually POSTs")
 * is satisfied by almost any implementation. What is worth pinning is the behaviour under a burst of typing,
 * under an in-flight request, on a dead session, and on a page view where nobody has typed anything at all.
 */

function jsonResponse(status: number, data: Record<string, unknown> = {}): Response {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: async () => ({ data }),
    } as unknown as Response;
}

function harness(overrides: Partial<ServerAutosaveOptions> = {}) {
    const post = vi.fn(async () => jsonResponse(200, { last_saved_at: '2026-08-08T00:00:00+00:00', completeness_percent: 50 }));
    const answers = reactive<Record<string, unknown>>({});
    const enabled = ref(true);
    const currentStepKey = ref('step-1');

    const autosave = createServerAutosave({
        url: '/forms/f1/submissions/draft',
        clientSubmissionUuid: 'uuid-1',
        answers,
        currentStepKey,
        enabled,
        debounceMs: 100,
        backstopMs: 1_000,
        post,
        ...overrides,
    });

    return { autosave, post, answers, enabled, currentStepKey };
}

beforeEach(() => {
    vi.useFakeTimers();
});

describe('useServerAutosave — batching', () => {
    it('collapses a burst of typing into ONE request', async () => {
        const { post, answers } = harness();

        answers.a = '1';
        await nextTick();
        answers.a = '12';
        await nextTick();
        answers.a = '123';
        await nextTick();

        expect(post).not.toHaveBeenCalled(); // still inside the debounce window

        await vi.advanceTimersByTimeAsync(150);

        expect(post).toHaveBeenCalledTimes(1);
        // And it sends the LAST value, not the first.
        expect((post.mock.calls[0][0] as { answers: Record<string, unknown> }).answers).toEqual({ a: '123' });
    });

    it('sends the uuid and the step cursor with every save', async () => {
        const { post, answers } = harness();

        answers.a = '1';
        await nextTick();
        await vi.advanceTimersByTimeAsync(150);

        const body = post.mock.calls[0][0] as Record<string, unknown>;
        expect(body.client_submission_uuid).toBe('uuid-1');
        expect(body.draft_current_step).toBe('step-1');
    });
});

describe('useServerAutosave — the empty-draft guard', () => {
    it('POSTs nothing while disabled, however much the answer map changes', async () => {
        // ⚠️ THE ROW-PER-PAGE-VIEW GUARD. `Encode.vue` seeds each repeatable section's min_instances during
        // mount, which MUTATES the answer map before any human has typed. Armed at setup, the composable
        // would create a `status = draft` row with a 30-day TTL on every single page view of any form with a
        // repeat group.
        const { post, answers, enabled } = harness();
        enabled.value = false;

        answers.a = 'seeded-by-mount';
        await nextTick();
        await vi.advanceTimersByTimeAsync(1_000);

        expect(post).not.toHaveBeenCalled();
    });

    it('starts saving once armed by a real edit', async () => {
        // The control: without it, the case above passes against a composable that never saves at all.
        const { post, answers, enabled } = harness();
        enabled.value = false;

        answers.a = 'seeded';
        await nextTick();
        await vi.advanceTimersByTimeAsync(1_000);
        expect(post).not.toHaveBeenCalled();

        enabled.value = true;
        answers.a = 'typed';
        await nextTick();
        await vi.advanceTimersByTimeAsync(150);

        expect(post).toHaveBeenCalledTimes(1);
    });
});

describe('useServerAutosave — concurrency', () => {
    it('never runs two requests at once, and schedules exactly one follow-up', async () => {
        // Two concurrent POSTs would serialize on the server's row lock, so the risk is not corruption but an
        // OLDER payload landing last.
        let resolveFirst: ((r: Response) => void) | null = null;
        const post = vi.fn(
            () =>
                new Promise<Response>((resolve) => {
                    if (resolveFirst === null) {
                        resolveFirst = resolve;

                        return;
                    }
                    resolve(jsonResponse(200));
                }),
        );

        const { answers } = harness({ post });

        answers.a = '1';
        await nextTick();
        await vi.advanceTimersByTimeAsync(150);
        expect(post).toHaveBeenCalledTimes(1); // in flight, unresolved

        // Three more edits land while it is in flight.
        answers.a = '2';
        await nextTick();
        answers.a = '3';
        await nextTick();
        await vi.advanceTimersByTimeAsync(150);

        expect(post).toHaveBeenCalledTimes(1); // still only one — the rest coalesced

        resolveFirst!(jsonResponse(200));
        await vi.advanceTimersByTimeAsync(150);

        expect(post).toHaveBeenCalledTimes(2); // exactly ONE follow-up, not three
    });
});

describe('useServerAutosave — the backstop', () => {
    it('is dirty-gated: a quiet tab issues no periodic requests', async () => {
        const { post } = harness();

        await vi.advanceTimersByTimeAsync(5_000);

        expect(post).not.toHaveBeenCalled();
    });

    it('does fire when there is unsaved work', async () => {
        // The control for the case above. A save that failed leaves the work dirty, and the backstop is what
        // eventually retries it without the keyer having to type again.
        const post = vi.fn(async () => jsonResponse(500));
        const { answers } = harness({ post });

        answers.a = '1';
        await nextTick();
        await vi.advanceTimersByTimeAsync(150);
        expect(post).toHaveBeenCalledTimes(1); // the failed attempt

        await vi.advanceTimersByTimeAsync(1_100); // one backstop interval

        expect(post.mock.calls.length).toBeGreaterThan(1);
    });
});

describe('useServerAutosave — terminal failures', () => {
    it('stops permanently on a 409 and names what happened', async () => {
        // The draft was submitted from another tab. Retrying can never succeed.
        const post = vi.fn(async () => jsonResponse(409));
        const { autosave, answers } = harness({ post });

        answers.a = '1';
        await nextTick();
        await vi.advanceTimersByTimeAsync(150);

        expect(autosave.state.value).toBe('stopped');
        expect(autosave.message.value).toContain('already been submitted');

        const callsAtStop = post.mock.calls.length;
        answers.a = '2';
        await nextTick();
        await vi.advanceTimersByTimeAsync(5_000);

        expect(post).toHaveBeenCalledTimes(callsAtStop); // never tries again
    });

    it('stops permanently on a 419, because a dead session loses work silently otherwise', async () => {
        const post = vi.fn(async () => jsonResponse(419));
        const { autosave, answers } = harness({ post });

        answers.a = '1';
        await nextTick();
        await vi.advanceTimersByTimeAsync(150);

        expect(autosave.state.value).toBe('stopped');
        expect(autosave.message.value).toContain('expired');
    });

    it('keeps retrying after a 5xx rather than giving up', async () => {
        const post = vi.fn(async () => jsonResponse(503));
        const { autosave, answers } = harness({ post });

        answers.a = '1';
        await nextTick();
        await vi.advanceTimersByTimeAsync(150);

        expect(autosave.state.value).toBe('error');

        answers.a = '2';
        await nextTick();
        await vi.advanceTimersByTimeAsync(150);

        expect(post.mock.calls.length).toBeGreaterThan(1);
    });

    it('keeps retrying after a network throw', async () => {
        const post = vi.fn(async () => {
            throw new Error('offline');
        });
        const { autosave, answers } = harness({ post });

        answers.a = '1';
        await nextTick();
        await vi.advanceTimersByTimeAsync(150);

        expect(autosave.state.value).toBe('error');
        expect(autosave.message.value).toContain('keep trying');
    });
});

describe('useServerAutosave — lifecycle', () => {
    it('flushes pending work on demand', async () => {
        const { autosave, post, answers } = harness();

        answers.a = '1';
        await nextTick();

        expect(post).not.toHaveBeenCalled(); // debounce not yet elapsed
        await autosave.flush();

        expect(post).toHaveBeenCalledTimes(1);
    });

    it('SAVES pending work on dispose(), because Inertia navigation fires no unload event', async () => {
        // ⚠️ THE MOST COMMON WAY TO LEAVE THIS PAGE IS AN INERTIA <Link> (Cancel, the breadcrumb, any sidebar
        // item), which unmounts the component client-side and fires no `beforeunload` at all. An earlier
        // draft of dispose() cleared the timers WITHOUT saving, so up to a full debounce window of typing
        // vanished on every click-away — no error, no prompt, nothing to notice.
        const { autosave, post, answers } = harness();

        answers.a = '1';
        await nextTick();
        expect(post).not.toHaveBeenCalled(); // still inside the debounce window

        autosave.dispose();

        expect(post).toHaveBeenCalledTimes(1);
        expect((post.mock.calls[0][0] as { answers: Record<string, unknown> }).answers).toEqual({ a: '1' });
    });

    it('stops the backstop on dispose(), so a torn-down page issues nothing further', async () => {
        const { autosave, post, answers } = harness();

        answers.a = '1';
        await nextTick();
        autosave.dispose();
        const afterDispose = post.mock.calls.length;

        await vi.advanceTimersByTimeAsync(10_000);

        expect(post).toHaveBeenCalledTimes(afterDispose);
    });

    it('saves nothing on dispose() when there is nothing pending', async () => {
        // The control: dispose() must not manufacture a write on a page nobody touched.
        const { autosave, post } = harness();

        autosave.dispose();

        expect(post).not.toHaveBeenCalled();
    });

    it('still writes on dispose() when the work is dirty but NO timer is armed', async () => {
        // ⚠️ FOUND BY MUTATION, NOT BY READING (M62). `dispose()`'s gate is `dirty || debounceTimer !== null`,
        // and narrowing that `||` to `&&` left the whole of this file green — every other dispose case here
        // happens to have both true at once, so the `||` arm was load-bearing and pinned by nothing.
        //
        // This is the state that separates them: a 5xx sets `dirty = true` and deliberately does NOT
        // re-schedule (the next keystroke is what retries), so a keyer who hits a server error and then
        // clicks away has unsaved work and no armed timer. Under `&&` that work is dropped in silence —
        // the same class of loss as the race above, reached from the other side.
        const post = vi
            .fn()
            .mockResolvedValueOnce(jsonResponse(500))
            .mockResolvedValue(jsonResponse(200, { content_checksum: 'sum-2' }));
        const { autosave, answers } = harness({ post });

        answers.a = '1';
        await nextTick();
        await vi.advanceTimersByTimeAsync(150);

        // Non-vacuity: the case is only about the dirty-without-timer state, so prove we are in it.
        expect(post).toHaveBeenCalledTimes(1);
        expect(autosave.state.value).toBe('error');

        autosave.dispose();

        expect(post).toHaveBeenCalledTimes(2);
        expect((post.mock.calls[1][0] as { answers: Record<string, unknown> }).answers).toEqual({ a: '1' });
    });
});

/**
 * Increment P3a — the lost-update baseline on the encode channel.
 *
 * Two tabs on one draft is the reachable case here (bootstrap/app.php names it), and before P3a the second
 * tab's tick silently replaced the first tab's answers: the write is a whole-document replace.
 */
describe('useServerAutosave — P3a lost-update baseline', () => {
    /** A 409 carrying a typed error envelope, which the `{ data }` helper above cannot express. */
    function conflict(code: string): Response {
        return {
            ok: false,
            status: 409,
            json: async () => ({ error: { code, message: 'nope' } }),
        } as unknown as Response;
    }

    it('sends the seeded baseline, then advances it from each response', async () => {
        const post = vi
            .fn()
            .mockResolvedValueOnce(jsonResponse(200, { last_saved_at: 't1', completeness_percent: 10, content_checksum: 'sum-2' }))
            .mockResolvedValueOnce(jsonResponse(200, { last_saved_at: 't2', completeness_percent: 20, content_checksum: 'sum-3' }));
        const { answers } = harness({ post, baseContentChecksum: 'sum-1' });

        answers.a = '1';
        await nextTick();
        await vi.advanceTimersByTimeAsync(150);

        // The page's rendered baseline goes out first.
        expect((post.mock.calls[0][0] as Record<string, unknown>).base_content_checksum).toBe('sum-1');

        answers.a = '2';
        await nextTick();
        await vi.advanceTimersByTimeAsync(150);

        // ...and the SECOND tick carries what the server just wrote, not the stale page value. Without this
        // every save after the first would present a stale base and read as a second tab.
        expect((post.mock.calls[1][0] as Record<string, unknown>).base_content_checksum).toBe('sum-2');
    });

    it('sends an explicit null baseline on the blank keying form', async () => {
        const post = vi.fn(async () => jsonResponse(200, { content_checksum: 'sum-1' }));
        const { answers } = harness({ post });

        answers.a = '1';
        await nextTick();
        await vi.advanceTimersByTimeAsync(150);

        const body = post.mock.calls[0][0] as Record<string, unknown>;
        expect(body).toHaveProperty('base_content_checksum');
        expect(body.base_content_checksum).toBeNull();
    });

    it('tells a lost update apart from an already-submitted draft, and says something different', async () => {
        const post = vi.fn(async () => conflict('draft_conflict'));
        const { autosave, answers } = harness({ post, baseContentChecksum: 'stale' });

        answers.a = '1';
        await nextTick();
        await vi.advanceTimersByTimeAsync(150);

        expect(autosave.state.value).toBe('stopped');
        // Reading the two 409 causes alike told a keyer whose colleague had merely SAVED that their work was
        // "already submitted" — false, and it names a remedy that does not exist.
        expect(autosave.message.value).toContain('Reload');
        expect(autosave.message.value).not.toContain('already been submitted');
    });

    it('keeps the pre-P3a message for the promotion race', async () => {
        const post = vi.fn(async () => conflict('draft_already_finalized'));
        const { autosave, answers } = harness({ post });

        answers.a = '1';
        await nextTick();
        await vi.advanceTimersByTimeAsync(150);

        expect(autosave.state.value).toBe('stopped');
        expect(autosave.message.value).toContain('already been submitted');
    });

    it('falls back to the pre-P3a message when a 409 body is unreadable', async () => {
        const post = vi.fn(async () => ({
            ok: false,
            status: 409,
            json: async () => {
                throw new SyntaxError('not json');
            },
        }) as unknown as Response);
        const { autosave, answers } = harness({ post });

        answers.a = '1';
        await nextTick();
        await vi.advanceTimersByTimeAsync(150);

        // An unparseable body is not EVIDENCE of a lost update, so it must not claim one — and it must not
        // turn a typed refusal into an unhandled rejection either.
        expect(autosave.state.value).toBe('stopped');
        expect(autosave.message.value).toContain('already been submitted');
    });

    /*
     * Increment M67 — the THIRD cause, which this channel has been able to return since M11.
     *
     * ⛔ THE ASSERTIONS ARE ON THE MESSAGE, NOT THE STATE, AND THAT IS THE WHOLE POINT. All three causes end
     * in `stopped`, so a case asserting the state passes identically whether the arm exists or not — which
     * is how the missing arm survived two increments of green.
     */
    it('tells an unmatched identifier apart from an already-submitted draft', async () => {
        const post = vi.fn(async () => conflict('submission_uuid_claimed'));
        const { autosave, answers } = harness({ post });

        answers.a = '1';
        await nextTick();
        await vi.advanceTimersByTimeAsync(150);

        expect(autosave.state.value).toBe('stopped');
        // ENTITLEMENT, not timing. Nothing was submitted, and saying so sends the keyer looking for a
        // submission that is not theirs; the sentence must name the identifier and what still helps.
        expect(autosave.message.value).toContain('could not be matched');
        expect(autosave.message.value).not.toContain('already been submitted');
        // ...and it must not borrow the OTHER cause's remedy either: reloading to pick up newer answers is
        // the draft_conflict fix, and there are no newer answers here.
        expect(autosave.message.value).not.toContain('pick up the newer answers');
    });

    it('falls back to the finalized sentence for a cause this build has never heard of', async () => {
        // A server-side cause added ahead of the front end. Of the sentences available, the one that is safe
        // when wrong is the one true of EVERY cause: the loop has stopped and this is no longer being saved.
        const post = vi.fn(async () => conflict('some_future_cause'));
        const { autosave, answers } = harness({ post });

        answers.a = '1';
        await nextTick();
        await vi.advanceTimersByTimeAsync(150);

        expect(autosave.state.value).toBe('stopped');
        expect(autosave.message.value).toContain('already been submitted');
    });

    /*
     * Increment M62 — dispose() must not RACE the save it is disposing.
     *
     * `send()` clears `dirty` at its top and advances `baseline` only on a 200. So everything typed WHILE a
     * save is open leaves the composable dirty against the PRE-save checksum, and the last-chance keepalive
     * dispose() fires on an Inertia navigation used to carry that stale base. The server serializes the two
     * on `lockForUpdate`, the open save moves the checksum, and the keepalive is refused `draft_conflict` —
     * swallowed by the fire-and-forget catch, so the keyer loses those edits with no error and no trace.
     *
     * These two cases need a post that STAYS OPEN, which no other case in this file has: every helper above
     * resolves immediately, so `inFlight` is always null by the time the assertion runs and the race is
     * unreachable by construction.
     */

    /** A post whose promise is held open, so the composable really is mid-flight when dispose() lands. */
    function deferredPost() {
        const releases: Array<(r: Response) => void> = [];
        const post = vi.fn(() => new Promise<Response>((resolve) => { releases.push(resolve); }));

        return { post, release: (r: Response) => releases.shift()?.(r) };
    }

    it('does not race an in-flight save on dispose(), and follows it with the ADVANCED baseline', async () => {
        const { post, release } = deferredPost();
        const { autosave, answers } = harness({ post, baseContentChecksum: 'sum-1' });

        answers.a = '1';
        await nextTick();
        await vi.advanceTimersByTimeAsync(150);

        expect(post).toHaveBeenCalledTimes(1);
        expect((post.mock.calls[0][0] as Record<string, unknown>).base_content_checksum).toBe('sum-1');

        // The keyer types on while that request is still open, then clicks an Inertia <Link>.
        answers.a = '12';
        await nextTick();
        autosave.dispose();

        // ⛔ THE DEFECT, PINNED: a keepalive fired HERE would carry `sum-1`, which the open save is about to
        // supersede, and be refused. Nothing may go out until that save has landed.
        expect(post).toHaveBeenCalledTimes(1);

        release(jsonResponse(200, { last_saved_at: 't1', content_checksum: 'sum-2' }));
        await vi.advanceTimersByTimeAsync(0);

        // ...and now it goes, carrying what the server just wrote and the text typed during the request.
        expect(post).toHaveBeenCalledTimes(2);
        const body = post.mock.calls[1][0] as { answers: Record<string, unknown>; base_content_checksum: unknown };
        expect(body.base_content_checksum).toBe('sum-2');
        expect(body.answers).toEqual({ a: '12' });
    });

    it('sends nothing after dispose() when the in-flight save has ENDED the loop', async () => {
        // The continuation resolves after `stop()` has run, which is exactly the window `stop()` exists to
        // close. Without the re-check inside it, dispose() would post into a session the server has already
        // refused — the "we'll keep trying" behaviour the terminal cases above deliberately forbid.
        const { post, release } = deferredPost();
        const { autosave, answers } = harness({ post, baseContentChecksum: 'sum-1' });

        answers.a = '1';
        await nextTick();
        await vi.advanceTimersByTimeAsync(150);

        answers.a = '12';
        await nextTick();
        autosave.dispose();

        release(conflict('draft_conflict'));
        await vi.advanceTimersByTimeAsync(0);

        expect(autosave.state.value).toBe('stopped');
        expect(post).toHaveBeenCalledTimes(1);
    });
});
