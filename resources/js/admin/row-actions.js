/** Place a row menu beside its trigger without escaping the visible viewport. */
export function positionRowActionMenu(anchor, menu, viewport, rtl = false) {
    const gutter = 8, gap = 6;
    const leftEdge = (viewport.left || 0) + gutter;
    const topEdge = (viewport.top || 0) + gutter;
    const rightEdge = (viewport.left || 0) + viewport.width - gutter;
    const bottomEdge = (viewport.top || 0) + viewport.height - gutter;
    const maxWidth = Math.max(0, rightEdge - leftEdge);
    const width = Math.min(menu.width, maxWidth);
    const below = Math.max(0, bottomEdge - anchor.bottom - gap);
    const above = Math.max(0, anchor.top - gap - topEdge);
    const opensBelow = menu.height <= below || below >= above;
    const maxHeight = opensBelow ? below : above;
    return {
        left: Math.max(leftEdge, Math.min(rtl ? anchor.left : anchor.right - width, rightEdge - width)),
        top: opensBelow ? anchor.bottom + gap : anchor.top - gap - Math.min(menu.height, maxHeight),
        maxWidth,
        maxHeight,
    };
}

/** Menus live at body level while open so table overflow cannot clip them. */
export function initRowActions(doc = document) {
    const win = doc.defaultView;
    const menus = new Map();
    let active = null;
    const items = menu => [...menu.querySelectorAll('a[href], button:not([disabled])')];
    const viewport = () => ({
        left: win.visualViewport?.offsetLeft || 0,
        top: win.visualViewport?.offsetTop || 0,
        width: win.visualViewport?.width || win.innerWidth,
        height: win.visualViewport?.height || win.innerHeight,
    });

    function close(restoreFocus = false) {
        if (!active) return;
        const { details, trigger, menu } = active;
        active = null;
        menu.hidden = true;
        details.open = false;
        trigger.setAttribute('aria-expanded', 'false');
        if (restoreFocus) trigger.focus();
    }

    function position() {
        if (!active) return;
        const { trigger, menu } = active;
        const anchor = trigger.getBoundingClientRect();
        const view = viewport();
        if (anchor.bottom <= view.top || anchor.top >= view.top + view.height ||
            anchor.right <= view.left || anchor.left >= view.left + view.width) {
            close(menu.contains(doc.activeElement));
            return;
        }
        menu.style.maxWidth = Math.max(0, view.width - 16) + 'px';
        const bounds = menu.getBoundingClientRect();
        const placement = positionRowActionMenu(anchor,
            { width: bounds.width, height: Math.max(bounds.height, menu.scrollHeight) },
            view, doc.documentElement.dir === 'rtl');
        for (const key of ['left', 'top', 'maxWidth', 'maxHeight']) menu.style[key] = placement[key] + 'px';
    }

    function open(details, last = false) {
        close();
        const trigger = details.querySelector('summary');
        const menu = menus.get(details) || details.querySelector('.vb-row-actions-menu');
        if (!trigger || !menu) return;
        menus.set(details, menu);
        items(menu).forEach(item => {
            item.setAttribute('role', 'menuitem');
            item.tabIndex = -1;
        });
        doc.body.append(menu);
        active = { details, trigger, menu };
        details.open = true;
        trigger.setAttribute('aria-expanded', 'true');
        menu.hidden = false;
        position();
        const controls = items(menu);
        (last ? controls.at(-1) : controls[0])?.focus();
    }

    function click(event) {
        const trigger = event.target.closest('[data-row-actions] > summary');
        if (trigger) {
            event.preventDefault();
            if (active?.trigger === trigger) close(true);
            else open(trigger.parentElement);
        } else if (active?.menu.contains(event.target)) {
            if (event.target.closest('a[href], button')) close(true);
        } else if (active) {
            close(active.menu.contains(doc.activeElement));
        }
    }

    function keydown(event) {
        const trigger = event.target.closest('[data-row-actions] > summary');
        if (trigger && ['ArrowDown', 'ArrowUp'].includes(event.key)) {
            event.preventDefault();
            open(trigger.parentElement, event.key === 'ArrowUp');
            return;
        }
        if (!active?.menu.contains(event.target)) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            close(true);
        } else if (event.key === 'Tab') {
            close(true); // The browser continues from the trigger in table order.
        } else if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
            event.preventDefault();
            const controls = items(active.menu);
            const current = controls.indexOf(doc.activeElement);
            const next = event.key === 'Home' ? 0 : event.key === 'End' ? controls.length - 1 :
                (current + (event.key === 'ArrowDown' ? 1 : -1) + controls.length) % controls.length;
            controls[next]?.focus();
        }
    }

    doc.addEventListener('click', click);
    doc.addEventListener('keydown', keydown);
    doc.addEventListener('scroll', position, true);
    win.addEventListener('resize', position);
    win.visualViewport?.addEventListener('resize', position);
    win.visualViewport?.addEventListener('scroll', position);
    return () => {
        close();
        doc.removeEventListener('click', click);
        doc.removeEventListener('keydown', keydown);
        doc.removeEventListener('scroll', position, true);
        win.removeEventListener('resize', position);
        win.visualViewport?.removeEventListener('resize', position);
        win.visualViewport?.removeEventListener('scroll', position);
        menus.forEach((menu, details) => details.append(menu));
    };
}
