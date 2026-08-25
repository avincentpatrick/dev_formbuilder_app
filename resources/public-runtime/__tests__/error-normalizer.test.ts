import { describe, expect, it } from 'vitest';
import { normalizeError } from '../lib/error-normalizer';

describe('normalizeError', () => {
    it('groups the submission_invalid LIST shape by field', () => {
        const n = normalizeError(422, {
            error: {
                code: 'submission_invalid',
                message: '1 field failed.',
                details: {
                    fields: [
                        { field: 'full_name', rule: 'field_required', message: 'This field is required.' },
                        { field: 'age', rule: 'min_value', message: 'Too small.' },
                    ],
                },
            },
        });
        expect(n.kind).toBe('field');
        expect(n.fieldErrors).toEqual({
            full_name: ['This field is required.'],
            age: ['Too small.'],
        });
    });

    it('handles the validation_failed MAP shape and strips the answers. prefix', () => {
        const n = normalizeError(422, {
            error: {
                code: 'validation_failed',
                message: 'Invalid.',
                details: { fields: { 'answers.email': ['Bad email.'], guest_contact_email: ['Bad.'] } },
            },
        });
        expect(n.kind).toBe('field');
        expect(n.fieldErrors).toEqual({ email: ['Bad email.'], guest_contact_email: ['Bad.'] });
    });

    it.each([
        [401, 'share_token_expired', 'remint'],
        [401, 'invalid_share_token', 'terminal'],
        [403, 'guest_disabled', 'terminal'],
        // Increment H12b — schedule 403s get their own kind (App shows the closed/opens-soon/full state).
        [403, 'form_closed', 'schedule'],
        [403, 'form_not_open', 'schedule'],
        [403, 'max_responses_reached', 'schedule'],
        [409, 'form_updated', 'refresh'],
        [409, 'submission_version_superseded', 'refresh'],
        // Increment M14 — the four 409s that are NOT a republish. Each row is independently mutable: delete
        // one arm of the classifier's 409 block and exactly one row here goes red, because every other row
        // names a different code and takes a different branch.
        [409, 'draft_conflict', 'draft_stale'],
        [409, 'submission_conflict', 'conflict'],
        [409, 'submission_uuid_claimed', 'uuid_claimed'],
        [409, 'draft_already_finalized', 'finalized'],
        [429, 'rate_limited', 'rate_limited'],
        [404, 'not_found', 'terminal'],
    ])('classifies %i %s as kind %s', (status, code, kind) => {
        const n = normalizeError(status, { error: { code, message: 'x' } });
        expect(n.kind).toBe(kind);
        expect(n.code).toBe(code);
    });

    it('leaves an unrecognised 409 on the reload path, which is the safe direction', () => {
        // Increment M14 — THE CONTROL FOR THE SPLIT ABOVE, and the reason the classifier keeps a tail rather
        // than a fifth `if`. A bodyless 409 (a proxy, a future cause this build has never heard of) has no
        // code to branch on and gets the synthetic `http_409`. It must keep landing on `refresh`: re-minting
        // and re-fetching the schema is harmless whatever really happened, whereas re-reading a draft that
        // may not exist, or parking a row for review, are both remedies to a question nobody answered.
        //
        // Every mutation of the four rows above leaves this case green, which is what makes it a control.
        const n = normalizeError(409, null);

        expect(n.code).toBe('http_409');
        expect(n.kind).toBe('refresh');
    });

    it('tolerates a non-enveloped {message} body (mint 404)', () => {
        const n = normalizeError(404, { message: 'Not Found' });
        expect(n.message).toBe('Not Found');
        expect(n.kind).toBe('terminal');
        expect(n.fieldErrors).toEqual({});
    });

    it('parses Retry-After for rate limiting', () => {
        const n = normalizeError(429, { error: { code: 'rate_limited', message: 'slow down' } }, '30');
        expect(n.retryAfterSeconds).toBe(30);
    });
});
