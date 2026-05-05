<?php
/**
 * Privacy data view — GDPR Art. 15 (Right of Access).
 *
 * Public-facing page: customer reviews what data is held about them.
 * Uses the VoxelBooking design system (admin-head.php for tokens).
 * Branded with the tenant's brand_color.
 *
 * Variables: $tenant, $customer, $bookings, $consentRecords, $csrfToken, $pageTitle
 */
use App\Engine\View;

$tenant = $tenant ?? [];
$customer = $customer ?? [];
$bookings = $bookings ?? [];
$consentRecords = $consentRecords ?? [];
$pageTitle = $pageTitle ?? __('booking.privacy.page_title');
$csrfToken = $csrfToken ?? '';
$brandColor = $tenant['brand_color'] ?? '#4F46E5';
$slug = $tenant['slug'] ?? '';
$customerId = $customer['id'] ?? '';
?>
<!DOCTYPE html>
<html lang="<?= \App\Engine\Locale::getLocale() ?>" dir="<?= \App\Engine\Locale::direction() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="description" content="<?= View::e(str_replace(':business', $tenant['name'] ?? '', __('booking.privacy.meta_description'))) ?>">
    <title><?= View::e($pageTitle) ?></title>
    <?php include dirname(__DIR__) . '/partials/admin-head.php'; ?>
    <link rel="stylesheet" href="/assets/css/admin-css.css?v=<?= filemtime(dirname(__DIR__, 2) . '/public/assets/css/admin-css.css') ?>">
    <style>
        body {
            background: var(--vb-admin-bg-base);
            min-height: 100vh;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 2rem 1rem;
        }

        .privacy-container {
            width: 100%;
            max-width: 700px;
            animation: vb-fade-in-up var(--vb-duration-slow) var(--vb-ease-out) both;
        }

        .privacy-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .privacy-brand {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: var(--vb-text-lg);
            font-weight: 700;
            color: <?= View::e($brandColor) ?>;
            letter-spacing: var(--vb-tracking-tight);
            margin-bottom: 0.5rem;
        }

        .privacy-brand svg {
            width: 24px;
            height: 24px;
        }

        .privacy-subtitle {
            font-size: var(--vb-text-sm);
            color: var(--vb-admin-text-secondary);
        }

        .privacy-section {
            background: var(--vb-admin-bg-surface);
            border-radius: var(--vb-radius-xl);
            padding: 1.5rem;
            margin-bottom: 1rem;
            box-shadow: var(--vb-admin-shadow-sm);
        }

        [data-theme="dark"] .privacy-section {
            border: 1px solid var(--vb-admin-border-subtle);
            box-shadow: var(--vb-admin-highlight);
        }

        .privacy-section-title {
            font-size: var(--vb-text-md);
            font-weight: 600;
            letter-spacing: var(--vb-tracking-tight);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .privacy-section-title svg {
            width: 18px;
            height: 18px;
            color: var(--vb-admin-text-tertiary);
        }

        .privacy-field {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.625rem 0;
            border-bottom: 1px solid var(--vb-admin-border-subtle);
        }

        .privacy-field:last-child {
            border-bottom: none;
        }

        .privacy-field-label {
            font-size: var(--vb-text-sm);
            font-weight: 500;
            color: var(--vb-admin-text-secondary);
        }

        .privacy-field-value {
            font-size: var(--vb-text-sm);
            color: var(--vb-admin-text-primary);
            text-align: right;
            max-width: 60%;
            word-break: break-word;
        }

        .privacy-booking {
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--vb-admin-border-subtle);
        }

        .privacy-booking:last-child {
            border-bottom: none;
        }

        .privacy-booking-date {
            font-size: var(--vb-text-sm);
            font-weight: 600;
            color: var(--vb-admin-text-primary);
        }

        .privacy-booking-meta {
            font-size: var(--vb-text-xs);
            color: var(--vb-admin-text-tertiary);
            margin-top: 0.25rem;
        }

        .privacy-status {
            display: inline-flex;
            align-items: center;
            padding: 0.125rem 0.5rem;
            border-radius: var(--vb-radius-full);
            font-size: var(--vb-text-2xs);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: var(--vb-tracking-wide);
        }

        .privacy-status-confirmed { background: var(--vb-admin-success-bg); color: var(--vb-admin-success); }
        .privacy-status-completed { background: var(--vb-admin-info-bg); color: var(--vb-admin-info); }
        .privacy-status-cancelled { background: var(--vb-admin-error-bg); color: var(--vb-admin-error); }
        .privacy-status-no_show { background: var(--vb-admin-warning-bg); color: var(--vb-admin-warning); }

        .privacy-actions {
            display: flex;
            gap: 0.75rem;
            margin-top: 1.5rem;
        }

        .privacy-actions form {
            flex: 1;
        }

        .privacy-empty {
            text-align: center;
            padding: 1.5rem;
            color: var(--vb-admin-text-tertiary);
            font-size: var(--vb-text-sm);
        }

        .privacy-footer {
            text-align: center;
            margin-top: 2rem;
            font-size: var(--vb-text-xs);
            color: var(--vb-admin-text-tertiary);
        }

        /* ── No-JS deletion confirmation ── */
        .privacy-confirm-toggle { display: none; }
        .privacy-confirm-step-2 { display: none; }
        .privacy-confirm-step-1 { display: block; }
        .privacy-confirm-toggle:checked ~ .privacy-confirm-step-1 { display: none; }
        .privacy-confirm-toggle:checked ~ .privacy-confirm-step-2 { display: block; }

        .privacy-confirm-label {
            cursor: pointer;
        }

        .privacy-confirm-warning {
            padding: 0.75rem 1rem;
            background: var(--vb-admin-error-bg);
            border-left: 3px solid var(--vb-admin-error);
            border-radius: var(--vb-radius-md);
            font-size: var(--vb-text-xs);
            color: var(--vb-admin-error);
            margin-bottom: 0.75rem;
        }

        /* Inline-style replacements */
        .privacy-booking-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .privacy-gdpr-text {
            font-size: var(--vb-text-sm);
            color: var(--vb-admin-text-secondary);
            margin-bottom: 1rem;
        }

        .privacy-btn-full { width: 100%; }

        .privacy-confirm-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .privacy-confirm-buttons > * {
            flex: 1;
        }

        @media (max-width: 640px) {
            .privacy-actions { flex-direction: column; }
            .privacy-field { flex-direction: column; align-items: flex-start; gap: 0.25rem; }
            .privacy-field-value { text-align: left; max-width: 100%; }
        }

        /* Theme toggle (self-contained; this page uses admin CSS, not booking CSS) */
        .vb-book-theme-toggle {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 36px;
            height: 36px;
            border-radius: 9999px;
            border: 1px solid var(--vb-admin-border-subtle);
            background: var(--vb-admin-bg-surface);
            color: var(--vb-admin-text-secondary);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: border-color 150ms ease-out, background 150ms ease-out,
                        color 150ms ease-out, box-shadow 150ms ease-out,
                        transform 150ms ease-out;
            z-index: 40;
            box-shadow: var(--vb-admin-shadow-sm);
            -webkit-tap-highlight-color: transparent;
        }
        .vb-book-theme-toggle:hover {
            border-color: var(--vb-admin-border);
            color: var(--vb-admin-text-primary);
            transform: scale(1.08);
        }
        .vb-book-theme-toggle:active {
            transform: scale(0.92);
            transition-duration: 60ms;
        }
        .vb-book-theme-icon { position: absolute; }
    </style>
    <script>
        (function() {
            var s = localStorage.getItem('vb-theme');
            var t = s || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>
</head>
<body>
    <div class="privacy-container">
        <!-- Header -->
        <div class="privacy-header vb-fade-in-up stagger-1">
            <div class="privacy-brand">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83Z"/><path d="m22 17.65-9.17 4.16a2 2 0 0 1-1.66 0L2 17.65"/><path d="m22 12.65-9.17 4.16a2 2 0 0 1-1.66 0L2 12.65"/></svg>
                <?= View::e($tenant['name'] ?? 'VoxelBooking') ?>
            </div>
            <p class="privacy-subtitle"><?= __('booking.privacy.subtitle') ?></p>
        </div>

        <!-- Personal Information -->
        <div class="privacy-section vb-fade-in-up stagger-2">
            <h2 class="privacy-section-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                <?= __('booking.privacy.personal_info') ?>
            </h2>
            <div class="privacy-field">
                <span class="privacy-field-label"><?= __('booking.privacy.name_label') ?></span>
                <span class="privacy-field-value"><?= View::e($customer['name'] ?? '') ?></span>
            </div>
            <div class="privacy-field">
                <span class="privacy-field-label"><?= __('booking.privacy.email_label') ?></span>
                <span class="privacy-field-value"><?= View::e($customer['email'] ?? '') ?></span>
            </div>
            <?php if (!empty($customer['phone'])): ?>
            <div class="privacy-field">
                <span class="privacy-field-label"><?= __('booking.privacy.phone_label') ?></span>
                <span class="privacy-field-value"><?= View::e($customer['phone']) ?></span>
            </div>
            <?php endif; ?>
            <div class="privacy-field">
                <span class="privacy-field-label"><?= __('booking.privacy.customer_since') ?></span>
                <span class="privacy-field-value"><?= View::e($customer['created_at'] ?? '') ?></span>
            </div>
        </div>

        <!-- Booking History -->
        <div class="privacy-section vb-fade-in-up stagger-3">
            <h2 class="privacy-section-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                <?= __('booking.privacy.booking_history') ?>
            </h2>
            <?php if (empty($bookings)): ?>
                <p class="privacy-empty"><?= __('booking.privacy.no_bookings') ?></p>
            <?php else: ?>
                <?php foreach ($bookings as $booking): ?>
                <div class="privacy-booking">
                    <div class="privacy-booking-row">
                        <span class="privacy-booking-date"><?= View::e($booking['start_datetime'] ?? '') ?></span>
                        <span class="privacy-status privacy-status-<?= View::e($booking['status'] ?? 'confirmed') ?>">
                            <?= View::e($booking['status'] ?? '') ?>
                        </span>
                    </div>
                    <div class="privacy-booking-meta">
                        <?= View::e($booking['booking_pattern'] ?? '') ?> · <?= __('booking.privacy.party_size') ?>: <?= View::e($booking['party_size'] ?? '1') ?>
                        <?php if (!empty($booking['source'])): ?>
                            · <?= __('booking.privacy.source') ?>: <?= View::e($booking['source']) ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Consent Records -->
        <?php if (!empty($consentRecords)): ?>
        <div class="privacy-section vb-fade-in-up stagger-4">
            <h2 class="privacy-section-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                <?= __('booking.privacy.consent_records') ?>
            </h2>
            <?php foreach ($consentRecords as $record): ?>
            <div class="privacy-field">
                <span class="privacy-field-label"><?= View::e($record['booking_date'] ?? '') ?></span>
                <span class="privacy-field-value">
                    <?= __('booking.privacy.consented') ?>: <?= View::e($record['consent_given_at'] ?? 'N/A') ?>
                    <?php if (!empty($record['consent_text_shown'])): ?>
                        <br><small>"<?= View::e($record['consent_text_shown']) ?>"</small>
                    <?php endif; ?>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Actions -->
        <div class="privacy-section vb-fade-in-up stagger-5">
            <h2 class="privacy-section-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                <?= __('booking.privacy.actions_title') ?>
            </h2>
            <p class="privacy-gdpr-text">
                <?= __('booking.privacy.gdpr_rights') ?>
            </p>
            <div class="privacy-actions">
                <form method="post" action="/book/<?= View::e($slug) ?>/privacy/<?= View::e($customerId) ?>">
                    <input type="hidden" name="_csrf_token" value="<?= View::e($csrfToken) ?>">
                    <input type="hidden" name="action" value="export">
                    <button type="submit" class="vb-btn vb-btn-secondary privacy-btn-full">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        <?= __('booking.privacy.export_data') ?>
                    </button>
                </form>
                <div>
                    <input type="checkbox" id="confirm-delete" class="privacy-confirm-toggle">
                    <div class="privacy-confirm-step-1">
                        <label for="confirm-delete" class="vb-btn vb-btn-danger privacy-confirm-label privacy-btn-full">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                            <?= __('booking.privacy.request_deletion') ?>
                        </label>
                    </div>
                    <div class="privacy-confirm-step-2">
                        <div class="privacy-confirm-warning">
                            <strong><?= __('booking.privacy.confirm_warning_title') ?></strong> <?= __('booking.privacy.confirm_warning_body') ?>
                        </div>
                        <form method="post" action="/book/<?= View::e($slug) ?>/privacy/<?= View::e($customerId) ?>">
                            <input type="hidden" name="_csrf_token" value="<?= View::e($csrfToken) ?>">
                            <input type="hidden" name="action" value="delete">
                            <div class="privacy-confirm-buttons">
                                <label for="confirm-delete" class="vb-btn vb-btn-secondary privacy-confirm-label">
                                    <?= __('booking.privacy.cancel') ?>
                                </label>
                                <button type="submit" class="vb-btn vb-btn-danger">
                                    <?= __('booking.privacy.confirm_deletion') ?>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="privacy-footer">
            <?= __('booking.footer.powered_by') ?> <?= app_name() ?> · <?= str_replace(':business', View::e($tenant['name'] ?? 'this business'), __('booking.privacy.footer_server')) ?>
        </div>
    </div>

    <!-- Theme Toggle (vanilla JS — no Alpine on this page) -->
    <button type="button" class="vb-book-theme-toggle" id="vb-theme-toggle"
            aria-label="<?= __('booking.theme.toggle') ?>" title="<?= __('booking.theme.toggle') ?>">
        <svg id="vb-theme-sun" class="vb-book-theme-icon" style="display:none" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
        <svg id="vb-theme-moon" class="vb-book-theme-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
    </button>

    <script>
        (function() {
            var btn = document.getElementById('vb-theme-toggle');
            var sun = document.getElementById('vb-theme-sun');
            var moon = document.getElementById('vb-theme-moon');
            function sync() {
                var d = document.documentElement.getAttribute('data-theme') === 'dark';
                sun.style.display = d ? '' : 'none';
                moon.style.display = d ? 'none' : '';
            }
            sync();
            btn.addEventListener('click', function() {
                var d = document.documentElement.getAttribute('data-theme') !== 'dark';
                document.documentElement.setAttribute('data-theme', d ? 'dark' : 'light');
                localStorage.setItem('vb-theme', d ? 'dark' : 'light');
                sync();
            });

            <?php if (\App\Engine\DemoMode::isActive()): ?>
            // Demo mode: block form submissions with a toast (§6 — buttons visible, writes blocked)
            document.querySelectorAll('form[method="post"]').forEach(function(form) {
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    showDemoToast();
                });
            });

            function showDemoToast() {
                var existing = document.getElementById('vb-demo-toast');
                if (existing) existing.remove();
                var toast = document.createElement('div');
                toast.id = 'vb-demo-toast';
                toast.textContent = <?= json_encode(__('admin.demo.write_blocked')) ?>;
                toast.style.cssText = 'position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:var(--vb-admin-error);color:#fff;padding:0.625rem 1.25rem;border-radius:var(--vb-radius-lg);font-size:var(--vb-text-sm);font-weight:500;z-index:9999;box-shadow:0 4px 12px rgba(0,0,0,.2);animation:vb-fade-in-up 200ms ease-out both;';
                document.body.appendChild(toast);
                setTimeout(function() { toast.remove(); }, 4000);
            }
            <?php endif; ?>
        })();
    </script>
</body>
</html>
