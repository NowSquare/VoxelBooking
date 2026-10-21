<?php
use App\Engine\Locale;
use App\Engine\View;
?>
<!DOCTYPE html>
<html lang="<?= Locale::getLocale() ?>" dir="<?= Locale::direction() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title><?= View::e(__('booking.access.title')) ?></title>
    <?php include dirname(__DIR__) . '/partials/admin-head.php'; ?>
    <link rel="stylesheet" href="/assets/css/admin-css.css">
    <style>
        html { overflow-y: auto !important; height: auto !important; }
        body { min-height: 100svh; display: grid; place-items: center; padding: var(--space-6); background: var(--vb-bg-base); }
        .customer-access { width: 100%; max-width: 28rem; }
        .customer-access h1 { font-size: var(--vb-text-2xl); font-weight: 600; letter-spacing: var(--vb-tracking-tight); margin-block: var(--space-4); }
        .customer-access p { color: var(--vb-text-secondary); line-height: var(--vb-leading-relaxed); margin-block-end: var(--space-6); }
        .customer-access footer { margin-block-start: var(--space-6); font-size: var(--vb-text-sm); }
        .customer-access button { width: 100%; min-height: 44px; }
        .customer-access input { min-height: 44px; }
        .customer-access footer a { display: inline-flex; align-items: center; min-height: 44px; }
    </style>
    <script type="module" src="/assets/js/customer-access.js"></script>
</head>
<body>
    <main class="customer-access" data-customer-access data-purpose="<?= View::e($purpose) ?>"
        data-subject-id="<?= View::e($subjectId) ?>" data-csrf="<?= View::e($csrfToken) ?>"
        data-exchange-url="/api/<?= View::e(rawurlencode($tenant['slug'])) ?>/customer-access"
        data-verifying="<?= View::e(__('booking.access.verifying')) ?>" data-expired="<?= View::e(__('booking.access.expired')) ?>">
        <span class="vb-badge"><?= View::e($tenant['name'] ?: __('booking.access.secure_access')) ?></span>
        <h1><?= View::e(__($accepted ? 'booking.access.sent_title' : 'booking.access.title')) ?></h1>
        <p role="status" aria-live="polite" data-access-status><?= View::e(__($accepted ? 'booking.access.sent' : ($purpose === \App\Engine\CustomerCapability::MANAGE ? 'booking.access.manage_description' : 'booking.access.privacy_description'))) ?></p>
        <?php if (!$accepted): ?>
        <form method="post" action="/book/<?= View::e(rawurlencode($tenant['slug'])) ?>/access-recovery">
            <input type="hidden" name="_csrf_token" value="<?= View::e($csrfToken) ?>">
            <input type="hidden" name="purpose" value="<?= View::e($purpose) ?>">
            <input type="hidden" name="subject_id" value="<?= View::e($subjectId) ?>">
            <div class="vb-form-group">
                <label class="vb-label" for="recovery-email"><?= View::e(__('booking.form.email_label')) ?></label>
                <input class="vb-input" id="recovery-email" name="email" type="email" autocomplete="email" maxlength="255" required>
            </div>
            <button class="vb-btn vb-btn-primary" type="submit"><?= View::e(__('booking.access.send')) ?></button>
        </form>
        <?php endif; ?>
        <footer><a class="vb-link" href="/book/<?= View::e(rawurlencode($tenant['slug'])) ?>"><?= View::e(__('booking.access.back')) ?></a></footer>
    </main>
</body>
</html>
