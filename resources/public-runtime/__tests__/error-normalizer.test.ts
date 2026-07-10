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
        [409, 'form_updated', 'refresh'],
        [409, 'submission_version_superseded', 'refresh'],
        [429, 'rate_limited', 'rate_limited'],
        [404, 'not_found', 'terminal'],
    ])('classifies %i %s as kind %s', (status, code, kind) => {
        const n = normalizeError(status, { error: { code, message: 'x' } });
        expect(n.kind).toBe(kind);
        expect(n.code).toBe(code);
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
