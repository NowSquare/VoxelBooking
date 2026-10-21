import { describe, expect, it, vi } from 'vitest';
import { exchangeCustomerAccess } from '../../resources/js/booking/customer-access.js';

function context(hash = '#access=private-token') {
    const status = { textContent: '' };
    const input = { focus: vi.fn() };
    const element = {
        dataset: { exchangeUrl: '/api/a/customer-access', purpose: 'booking.manage', subjectId: 'booking-a', csrf: 'csrf', verifying: 'Verifying', expired: 'Request a new link' },
        querySelector: selector => selector === '[data-access-status]' ? status : input,
    };
    const environment = {
        location: { hash, pathname: '/book/a/manage/booking-a', search: '', reload: vi.fn() },
        history: { replaceState: vi.fn() },
        fetch: vi.fn().mockResolvedValue({ ok: true }),
    };
    return { element, environment, status, input };
}

describe('customer access exchange', () => {
    it('removes the secret from history before sending it in a POST body', async () => {
        const { element, environment } = context();
        await exchangeCustomerAccess(element, environment);
        expect(environment.history.replaceState).toHaveBeenCalledWith(null, '', '/book/a/manage/booking-a');
        expect(environment.history.replaceState.mock.invocationCallOrder[0]).toBeLessThan(environment.fetch.mock.invocationCallOrder[0]);
        const [url, options] = environment.fetch.mock.calls[0];
        expect(url).not.toContain('private-token');
        expect(options.cache).toBe('no-store');
        expect(JSON.parse(options.body)).toEqual({ token: 'private-token', purpose: 'booking.manage', subject_id: 'booking-a' });
        expect(environment.location.reload).toHaveBeenCalledOnce();
    });

    it('keeps an expired link recoverable and moves focus to email', async () => {
        const { element, environment, status, input } = context();
        environment.fetch.mockResolvedValue({ ok: false });
        expect(await exchangeCustomerAccess(element, environment)).toBe(false);
        expect(status.textContent).toBe('Request a new link');
        expect(input.focus).toHaveBeenCalledOnce();
        expect(environment.location.reload).not.toHaveBeenCalled();
    });

    it('makes no request for an identifier-only legacy link', async () => {
        const { element, environment } = context('');
        expect(await exchangeCustomerAccess(element, environment)).toBe(false);
        expect(environment.fetch).not.toHaveBeenCalled();
    });
});
