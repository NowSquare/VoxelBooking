<?php
/**
 * Privacy anonymized view — shown when customer data has already been removed.
 *
 * Variables: $tenant, $pageTitle
 */
use App\Engine\View;

$tenant = $tenant ?? [];
$pageTitle = $pageTitle ?? __('booking.privacy.anonymized_page_title');
$brandColor = $tenant['brand_color'] ?? '#4F46E5';
?>
<!DOCTYPE html>
<html lang="<?= \App\Engine\Locale::getLocale() ?>" dir="<?= \App\Engine\Locale::direction() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= View::e($pageTitle) ?></title>
    <?php include dirname(__DIR__) . '/partials/admin-head.php'; ?>
    <link rel="stylesheet" href="/assets/css/admin-css.css?v=<?= filemtime(dirname(__DIR__, 2) . '/public/assets/css/admin-css.css') ?>">
    <style>
        body {
            background: var(--vb-admin-bg-base);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
        }

        .privacy-container {
            width: 100%;
            max-width: 480px;
            text-align: center;
            animation: vb-fade-in-up var(--vb-duration-slow) var(--vb-ease-out) both;
        }

        .privacy-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 1.5rem;
            color: var(--vb-admin-success);
            opacity: 0.8;
        }

        .privacy-title {
            font-size: var(--vb-text-2xl);
            font-weight: 700;
            letter-spacing: var(--vb-tracking-tight);
            margin-bottom: 0.75rem;
        }

        .privacy-message {
            font-size: var(--vb-text-sm);
            color: var(--vb-admin-text-secondary);
            line-height: var(--vb-leading-relaxed);
            max-width: 380px;
            margin: 0 auto;
        }

        .privacy-footer {
            margin-top: 2.5rem;
            font-size: var(--vb-text-xs);
            color: var(--vb-admin-text-tertiary);
        }
    </style>
</head>
<body>
    <div class="privacy-container">
        <svg class="privacy-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
            <polyline points="9 12 11 14 15 10"/>
        </svg>

        <h1 class="privacy-title"><?= __('booking.privacy.anonymized_title') ?></h1>

        <p class="privacy-message">
            <?= __('booking.privacy.anonymized_message') ?>
        </p>

        <div class="privacy-footer">
            <?= __('booking.footer.powered_by') ?> <?= app_name() ?> · <?= View::e($tenant['name'] ?? '') ?>
        </div>
    </div>
</body>
</html>
