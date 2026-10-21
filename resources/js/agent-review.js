/** Review handles stay out of request URLs and are scoped to one browser review. */
export async function exchangeReview(element, environment = window) {
    const handle = new URLSearchParams(environment.location.hash.slice(1)).get('review');
    if (!handle) return false;
    environment.history.replaceState(null, '', environment.location.pathname);
    try {
        const response = await environment.fetch(element.dataset.exchangeUrl, {
            method: 'POST', credentials: 'same-origin', cache: 'no-store',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': element.dataset.csrf },
            body: JSON.stringify({ handle }),
        });
        if (!response.ok) throw new Error('review_unavailable');
        environment.location.reload();
        return true;
    } catch {
        const status = element.querySelector('[data-review-status]');
        if (status) status.textContent = element.dataset.failed;
        return false;
    }
}
if (typeof document !== 'undefined') {
    const review = document.querySelector('[data-agent-review]');
    if (review) exchangeReview(review);
}
