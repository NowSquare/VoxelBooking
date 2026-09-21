/**
 * Regression tests for the platform-wide admin form validator.
 *
 * Imports the PRODUCTION functions from form-validator.js — the same
 * module consumed by admin/app.js. No re-implemented copies.
 *
 * Covers:
 * - shouldSkipInput: hidden-field skip logic for Alpine x-show
 * - validateForm: full validation pipeline against real DOM
 * - clearFieldError / markFieldError: error state management
 * - getErrorMessage: custom data-error-* attribute support
 *
 * NOTE: JSDOM has no layout engine, so offsetParent is always null.
 * The offsetParent fallback in shouldSkipInput (line 21 of form-validator.js)
 * cannot be tested in JSDOM. The production-critical regressions — the
 * display:none ancestor check and the disabled check — are fully covered.
 *
 * @vitest-environment jsdom
 */
import { describe, expect, it, beforeEach, afterEach } from 'vitest';
import {
    shouldSkipInput,
    clearFieldError,
    markFieldError,
    getErrorMessage,
    validateForm,
} from '../../resources/js/admin/form-validator.js';

// ── shouldSkipInput ─────────────────────────────────────────────

describe('shouldSkipInput (production)', () => {
    let container;

    beforeEach(() => {
        container = document.createElement('div');
        document.body.appendChild(container);
    });

    afterEach(() => {
        container.remove();
    });

    it('returns true for inputs inside display:none ancestors', () => {
        container.innerHTML = `
            <div style="display: none;">
                <input type="email" class="vb-input" required>
            </div>
        `;
        expect(shouldSkipInput(container.querySelector('input'))).toBe(true);
    });

    it('returns true for disabled inputs', () => {
        container.innerHTML = `
            <input type="text" class="vb-input" required disabled>
        `;
        expect(shouldSkipInput(container.querySelector('input'))).toBe(true);
    });

    it('returns true for deeply nested display:none', () => {
        container.innerHTML = `
            <div style="display: none;">
                <div><div>
                    <input type="text" class="vb-input" required>
                </div></div>
            </div>
        `;
        expect(shouldSkipInput(container.querySelector('input'))).toBe(true);
    });

    it('returns true for the exact Alpine x-show collapsed pattern', () => {
        container.innerHTML = `
            <div x-show="enabled" x-transition.duration.200ms style="display: none;">
                <input type="email" class="vb-input" required value="invalid">
            </div>
        `;
        expect(shouldSkipInput(container.querySelector('input'))).toBe(true);
    });

    it('does NOT trigger display:none or disabled checks for expanded Alpine section', () => {
        container.innerHTML = `
            <div x-show="enabled" x-transition.duration.200ms>
                <input type="email" class="vb-input" required>
            </div>
        `;
        const input = container.querySelector('input');
        // The two production-critical checks must NOT trigger:
        expect(input.disabled).toBe(false);
        expect(input.closest('[style*="display: none"]')).toBe(null);
        // (offsetParent is always null in JSDOM — tested in browser only)
    });
});

// ── validateForm ────────────────────────────────────────────────

describe('validateForm (production)', () => {
    let container;

    beforeEach(() => {
        container = document.createElement('div');
        document.body.appendChild(container);
    });

    afterEach(() => {
        container.remove();
    });

    it('skips hidden inputs and does NOT mark them as invalid', () => {
        container.innerHTML = `
            <form>
                <div style="display: none;">
                    <div class="vb-form-group">
                        <input type="email" id="hidden_owner" class="vb-input" required value="">
                    </div>
                </div>
            </form>
        `;
        const form = container.querySelector('form');
        const result = validateForm(form);
        // No visible inputs → nothing to fail
        expect(result).toBe(null);
        expect(container.querySelector('#hidden_owner').classList.contains('is-invalid')).toBe(false);
    });

    it('skips hidden inputs even when they have invalid values', () => {
        container.innerHTML = `
            <form>
                <div style="display: none;">
                    <div class="vb-form-group">
                        <input type="email" id="stale_email" class="vb-input" required value="not-an-email">
                    </div>
                </div>
            </form>
        `;
        const form = container.querySelector('form');
        const result = validateForm(form);
        expect(result).toBe(null);
        expect(container.querySelector('#stale_email').classList.contains('is-invalid')).toBe(false);
        expect(container.querySelector('.vb-form-error')).toBe(null);
    });

    it('skips disabled inputs', () => {
        container.innerHTML = `
            <form>
                <div class="vb-form-group">
                    <input type="text" class="vb-input" required disabled value="">
                </div>
            </form>
        `;
        const form = container.querySelector('form');
        expect(validateForm(form)).toBe(null);
    });

    it('clears previous errors before re-validating', () => {
        container.innerHTML = `
            <form>
                <div style="display: none;">
                    <div class="vb-form-group">
                        <input type="text" class="vb-input is-invalid" required value="">
                        <div class="vb-form-error">Old error</div>
                    </div>
                </div>
            </form>
        `;
        const form = container.querySelector('form');
        validateForm(form);
        // The old error should be cleared even on hidden inputs
        const input = container.querySelector('input');
        expect(input.classList.contains('is-invalid')).toBe(false);
        expect(container.querySelector('.vb-form-error')).toBe(null);
    });
});

// ── markFieldError + getErrorMessage (direct function tests) ──

describe('markFieldError (production)', () => {
    it('adds .is-invalid and injects .vb-form-error with message', () => {
        const container = document.createElement('div');
        container.innerHTML = `
            <div class="vb-form-group">
                <input type="text" class="vb-input">
            </div>
        `;
        document.body.appendChild(container);
        const input = container.querySelector('input');

        markFieldError(input, 'This field is required.');

        expect(input.classList.contains('is-invalid')).toBe(true);
        const err = container.querySelector('.vb-form-error');
        expect(err).not.toBe(null);
        expect(err.textContent).toBe('This field is required.');
        expect(err.getAttribute('role')).toBe('alert');
        container.remove();
    });

    it('does not duplicate error messages on repeated calls', () => {
        const container = document.createElement('div');
        container.innerHTML = `
            <div class="vb-form-group">
                <input type="text" class="vb-input">
            </div>
        `;
        document.body.appendChild(container);
        const input = container.querySelector('input');

        markFieldError(input, 'Error 1');
        markFieldError(input, 'Error 2');

        const errors = container.querySelectorAll('.vb-form-error');
        expect(errors.length).toBe(1);
        expect(errors[0].textContent).toBe('Error 1');
        container.remove();
    });

    it('inserts error after .vb-password-field wrapper', () => {
        const container = document.createElement('div');
        container.innerHTML = `
            <div class="vb-form-group">
                <div class="vb-password-field">
                    <input type="password" class="vb-input">
                </div>
            </div>
        `;
        document.body.appendChild(container);
        const input = container.querySelector('input');

        markFieldError(input, 'Password required.');

        // Error should be after the wrapper, not after the input
        const wrapper = container.querySelector('.vb-password-field');
        const err = wrapper.nextSibling;
        expect(err.classList.contains('vb-form-error')).toBe(true);
        container.remove();
    });

    it('works with .vb-settings-field container (settings forms)', () => {
        const container = document.createElement('div');
        container.innerHTML = `
            <div class="vb-settings-field">
                <input type="text" class="vb-input">
            </div>
        `;
        document.body.appendChild(container);
        const input = container.querySelector('input');

        markFieldError(input, 'Name is required.');

        expect(input.classList.contains('is-invalid')).toBe(true);
        const err = container.querySelector('.vb-form-error');
        expect(err).not.toBe(null);
        expect(err.textContent).toBe('Name is required.');
        container.remove();
    });
});

describe('clearFieldError (production)', () => {
    it('removes .is-invalid class and .vb-form-error element', () => {
        const container = document.createElement('div');
        container.innerHTML = `
            <div class="vb-form-group">
                <input type="text" class="vb-input is-invalid">
                <div class="vb-form-error">Error text</div>
            </div>
        `;
        document.body.appendChild(container);
        const input = container.querySelector('input');

        clearFieldError(input);

        expect(input.classList.contains('is-invalid')).toBe(false);
        expect(container.querySelector('.vb-form-error')).toBe(null);
        container.remove();
    });

    it('is a no-op when no error exists', () => {
        const container = document.createElement('div');
        container.innerHTML = `
            <div class="vb-form-group">
                <input type="text" class="vb-input">
            </div>
        `;
        document.body.appendChild(container);
        const input = container.querySelector('input');

        // Should not throw
        clearFieldError(input);

        expect(input.classList.contains('is-invalid')).toBe(false);
        container.remove();
    });

    it('clears errors in .vb-settings-field containers', () => {
        const container = document.createElement('div');
        container.innerHTML = `
            <div class="vb-settings-field">
                <input type="text" class="vb-input is-invalid">
                <div class="vb-form-error">Settings error</div>
            </div>
        `;
        document.body.appendChild(container);
        const input = container.querySelector('input');

        clearFieldError(input);

        expect(input.classList.contains('is-invalid')).toBe(false);
        expect(container.querySelector('.vb-form-error')).toBe(null);
        container.remove();
    });
});

describe('getErrorMessage (production)', () => {
    it('returns custom message from data-error-required', () => {
        const container = document.createElement('div');
        container.innerHTML = `
            <input type="text" class="vb-input" required value=""
                   data-error-required="Business name is required.">
        `;
        document.body.appendChild(container);
        const input = container.querySelector('input');

        // Force validity check so validity.valueMissing is true
        input.checkValidity();

        const msg = getErrorMessage(input);
        expect(msg).toBe('Business name is required.');
        container.remove();
    });

    it('returns default message when no data attribute', () => {
        const container = document.createElement('div');
        container.innerHTML = `<input type="text" class="vb-input" required value="">`;
        document.body.appendChild(container);
        const input = container.querySelector('input');

        input.checkValidity();

        const msg = getErrorMessage(input);
        expect(msg).toBe('This field is required.');
        container.remove();
    });

    it('returns custom type mismatch message', () => {
        const container = document.createElement('div');
        container.innerHTML = `
            <input type="email" class="vb-input" value="not-email"
                   data-error-type="Please enter a valid email.">
        `;
        document.body.appendChild(container);
        const input = container.querySelector('input');

        input.checkValidity();

        // JSDOM may or may not enforce email validation — check
        if (input.validity.typeMismatch) {
            expect(getErrorMessage(input)).toBe('Please enter a valid email.');
        }
        container.remove();
    });

    describe('server-translated fallback messages (window.__VB_ADMIN_I18N__)', () => {
        afterEach(() => {
            delete window.__VB_ADMIN_I18N__;
        });

        it('uses the translated required message when the payload is present', () => {
            window.__VB_ADMIN_I18N__ = { validation: { required: '[translated] required' } };
            const container = document.createElement('div');
            container.innerHTML = `<input type="text" class="vb-input" required value="">`;
            document.body.appendChild(container);
            const input = container.querySelector('input');

            input.checkValidity();

            expect(getErrorMessage(input)).toBe('[translated] required');
            container.remove();
        });

        it('substitutes :min in the translated minlength message', () => {
            window.__VB_ADMIN_I18N__ = { validation: { minlength: '[translated] min :min chars' } };
            const input = document.createElement('input');
            input.minLength = 8;
            // JSDOM only flags tooShort for user-edited values, so stub the validity state.
            Object.defineProperty(input, 'validity', {
                value: { valueMissing: false, typeMismatch: false, tooShort: true, patternMismatch: false },
            });

            expect(getErrorMessage(input)).toBe('[translated] min 8 chars');
        });

        it('data-error-* attributes still take precedence over the translated payload', () => {
            window.__VB_ADMIN_I18N__ = { validation: { required: '[translated] required' } };
            const container = document.createElement('div');
            container.innerHTML = `<input type="text" class="vb-input" required value=""
                                          data-error-required="Custom name required.">`;
            document.body.appendChild(container);
            const input = container.querySelector('input');

            input.checkValidity();

            expect(getErrorMessage(input)).toBe('Custom name required.');
            container.remove();
        });

        it('falls back to the English default when a key is missing from the payload', () => {
            window.__VB_ADMIN_I18N__ = { validation: {} };
            const container = document.createElement('div');
            container.innerHTML = `<input type="text" class="vb-input" required value="">`;
            document.body.appendChild(container);
            const input = container.querySelector('input');

            input.checkValidity();

            expect(getErrorMessage(input)).toBe('This field is required.');
            container.remove();
        });
    });
});
