/** @vitest-environment jsdom */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { initRowActions, positionRowActionMenu } from '../../resources/js/admin/row-actions.js';

describe('row action menu positioning', () => {
    it('aligns to the action trigger right edge below the row', () => {
        expect(positionRowActionMenu({ left: 800, right: 880, top: 300, bottom: 336 },
            { width: 240, height: 180 }, { width: 1024, height: 768 })).toMatchObject({ left: 640, top: 342 });
    });
    it('flips above a trigger near the viewport bottom', () => {
        expect(positionRowActionMenu({ left: 800, right: 880, top: 530, bottom: 566 },
            { width: 240, height: 180 }, { width: 1024, height: 600 })).toMatchObject({ left: 640, top: 344 });
    });
    it('clamps a wide mobile menu and scrolls within the available height', () => {
        const position = positionRowActionMenu({ left: 250, right: 310, top: 100, bottom: 136 },
            { width: 400, height: 700 }, { width: 320, height: 500 });
        expect(position).toEqual({ left: 8, top: 142, maxWidth: 304, maxHeight: 350 });
    });
});

describe('floating row actions', () => {
    let destroy, details, trigger, menu;
    beforeEach(() => {
        document.body.innerHTML = '<table><tbody><tr><td><details data-row-actions><summary aria-controls="actions">Actions</summary><div id="actions" class="vb-row-actions-menu" role="menu" hidden><a href="/edit">Edit</a><button type="button">Copy</button><form action="/archive" data-confirm="Archive?"><input type="hidden" name="_csrf_token" value="test"><button type="submit">Archive</button></form></div></details></td></tr></tbody></table><button id="outside">Outside</button>';
        details = document.querySelector('details');
        trigger = details.querySelector('summary');
        menu = details.querySelector('[role="menu"]');
        trigger.getBoundingClientRect = () => ({ left: 500, right: 580, top: 200, bottom: 236 });
        menu.getBoundingClientRect = () => ({ width: 220, height: 150 });
        destroy = initRowActions();
    });
    afterEach(() => destroy());
    it('opens outside the table flow and focuses the first action', () => {
        trigger.click();
        expect(details.open).toBe(true);
        expect(trigger.getAttribute('aria-expanded')).toBe('true');
        expect(menu.parentElement).toBe(document.body);
        expect(menu.hidden).toBe(false);
        expect(menu.style.left).toBe('360px');
        expect(menu.style.top).toBe('242px');
        expect(document.activeElement).toBe(menu.querySelector('a'));
    });
    it('cycles arrow-key focus and returns focus on Escape', () => {
        trigger.click();
        const first = menu.querySelector('a');
        first.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowUp', bubbles: true, cancelable: true }));
        expect(document.activeElement.textContent).toBe('Archive');
        document.activeElement.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
        expect(menu.hidden).toBe(true);
        expect(details.open).toBe(false);
        expect(document.activeElement).toBe(trigger);
    });
    it('opens from ArrowUp at the last action', () => {
        trigger.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowUp', bubbles: true, cancelable: true }));
        expect(document.activeElement.textContent).toBe('Archive');
    });
    it('dismisses on outside click while preserving newly focused controls', () => {
        trigger.click();
        const outside = document.querySelector('#outside');
        outside.focus();
        outside.click();
        expect(menu.hidden).toBe(true);
        expect(document.activeElement).toBe(outside);
    });
    it('returns focus to the trigger before normal Tab navigation continues', () => {
        trigger.click();
        const event = new KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true });
        menu.querySelector('a').dispatchEvent(event);
        expect(menu.hidden).toBe(true);
        expect(document.activeElement).toBe(trigger);
        expect(event.defaultPrevented).toBe(false);
    });
    it('retains form ownership, CSRF and confirmation while dismissing on action', () => {
        trigger.click();
        const form = menu.querySelector('form');
        const button = form.querySelector('button');
        expect(button.form).toBe(form);
        expect(form.querySelector('[name="_csrf_token"]').value).toBe('test');
        const submit = vi.fn(event => event.preventDefault());
        form.addEventListener('submit', submit);
        button.click();
        expect(submit).toHaveBeenCalledOnce();
        expect(menu.hidden).toBe(true);
        expect(form.dataset.confirm).toBe('Archive?');
    });
    it('repositions during table scrolling and closes when the trigger leaves the viewport', () => {
        trigger.click();
        trigger.getBoundingClientRect = () => ({ left: 400, right: 480, top: 100, bottom: 136 });
        document.dispatchEvent(new Event('scroll'));
        expect(menu.style.left).toBe('260px');
        trigger.getBoundingClientRect = () => ({ left: 400, right: 480, top: -50, bottom: -10 });
        document.dispatchEvent(new Event('scroll'));
        expect(menu.hidden).toBe(true);
    });
});
