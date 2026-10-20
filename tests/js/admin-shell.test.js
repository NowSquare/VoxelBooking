/** @vitest-environment jsdom */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { readFileSync } from 'node:fs';

// Exercise the production Alpine component without starting unrelated page components.
const source = readFileSync(process.cwd() + '/resources/js/admin/app.js', 'utf8');
const start = source.indexOf("Alpine.data('adminShell'");
const end = source.indexOf('// ── Alpine: Slug Editor', start);
let factory;
new Function('Alpine', source.slice(start, end))({ data(name, value) { factory = value; } });

describe('mobile admin navigation', () => {
    let shell, media, onMediaChange;
    beforeEach(() => {
        document.body.innerHTML = '<aside id="sidebar"><a href="/admin">Home</a><a href="/bookings">Bookings</a></aside><div id="main"><button id="trigger">Menu</button><a href="/other">Other</a></div>';
        media = { matches: true, addEventListener: vi.fn((name, handler) => { onMediaChange = handler; }), removeEventListener: vi.fn() };
        vi.stubGlobal('matchMedia', vi.fn(() => media));
        shell = factory();
        shell.$refs = { sidebar: document.querySelector('#sidebar'), main: document.querySelector('#main'), sidebarTrigger: document.querySelector('#trigger') };
        shell.$nextTick = callback => callback();
        shell.init();
    });
    afterEach(() => { shell.destroy?.(); vi.unstubAllGlobals(); });

    it('keeps the closed offscreen sidebar out of keyboard navigation', () => {
        expect(shell.$refs.sidebar.inert).toBe(true);
        expect(shell.$refs.sidebar.getAttribute('aria-hidden')).toBe('true');
    });
    it('opens with focus inside and prevents background interaction', () => {
        shell.$refs.sidebarTrigger.focus();
        shell.toggleSidebar();
        expect(shell.$refs.main.inert).toBe(true);
        expect(shell.$refs.sidebar.inert).toBe(false);
        expect(document.activeElement).toBe(shell.$refs.sidebar.querySelector('a'));
        expect(shell.$refs.sidebarTrigger.getAttribute('aria-expanded')).toBe('true');
    });
    it('cycles tab at both boundaries and restores focus on escape', () => {
        shell.toggleSidebar();
        const links = shell.$refs.sidebar.querySelectorAll('a');
        links[1].focus();
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true }));
        expect(document.activeElement).toBe(links[0]);
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', shiftKey: true, bubbles: true, cancelable: true }));
        expect(document.activeElement).toBe(links[1]);
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        expect(shell.sidebarOpen).toBe(false);
        expect(shell.$refs.main.inert).toBe(false);
        expect(document.activeElement).toBe(shell.$refs.sidebarTrigger);
    });
    it('restores the desktop navigation after resizing', () => {
        shell.toggleSidebar();
        media.matches = false;
        onMediaChange();
        expect(shell.$refs.sidebar.inert).toBe(false);
        expect(shell.$refs.sidebar.hasAttribute('aria-hidden')).toBe(false);
        expect(shell.$refs.main.inert).toBe(false);
        expect(shell.sidebarOpen).toBe(false);
    });
});
