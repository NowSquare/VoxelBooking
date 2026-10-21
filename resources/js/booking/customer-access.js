/** Tokens arrive in fragments so reverse proxies never receive them in URLs. */
export async function exchangeCustomerAccess(element, environment = window) {
    const fragment = new URLSearchParams(environment.location.hash.slice(1));
    const token = fragment.get('access');
    if (!token) return false;
    environment.history.replaceState(null, '', environment.location.pathname + environment.location.search);
    const status = element.querySelector('[data-access-status]');
    if (status) status.textContent = element.dataset.verifying;
    try {
        const response = await environment.fetch(element.dataset.exchangeUrl, {
            method: 'POST', credentials: 'same-origin', cache: 'no-store',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': element.dataset.csrf },
            body: JSON.stringify({ token, purpose: element.dataset.purpose, subject_id: element.dataset.subjectId }),
        });
        if (!response.ok) throw new Error('verification_required');
        environment.location.reload();
        return true;
    } catch {
        if (status) status.textContent = element.dataset.expired;
        element.querySelector('input[type="email"]')?.focus();
        return false;
    }
}

if (typeof document !== 'undefined') {
    const element = document.querySelector('[data-customer-access]');
    if (element) exchangeCustomerAccess(element);
}
