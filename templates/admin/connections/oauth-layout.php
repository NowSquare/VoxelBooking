<!DOCTYPE html>
<html lang="<?= \App\Engine\Locale::getLocale() ?>" dir="<?= \App\Engine\Locale::direction() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title><?= e($pageTitle) ?> · <?= e(app_name()) ?></title>
    <?php include dirname(__DIR__, 2) . '/partials/admin-head.php'; ?>
    <link rel="stylesheet" href="/assets/css/admin-css.css">
    <style>
        html { height: auto; overflow-y: auto; }
        body { min-height: 100svh; display: grid; place-items: center; padding: var(--space-6); background: var(--vb-bg-base); color: var(--vb-text-primary); }
        .oauth-review { width: min(100%, 38rem); min-width: 0; }
        .oauth-review .vb-card-header { flex-direction: column; align-items: stretch; gap: var(--space-2); }
        .oauth-review .vb-card-body { display: flex; flex-direction: column; gap: var(--space-5); }
        .oauth-review .vb-form-group { margin: 0; }
        .oauth-review .vb-info-row { padding-block: 0 var(--space-4); }
        .oauth-brand { display: inline-block; margin-block-end: var(--space-6); font-weight: 600; color: var(--vb-text-secondary); text-decoration: none; }
        .oauth-review code { overflow-wrap: anywhere; white-space: normal; }
        .oauth-review details summary { cursor: pointer; padding-block: var(--space-3); display: list-item; }
        .oauth-review ul { padding-inline-start: var(--space-6); margin-block: var(--space-3); }
        .oauth-review .vb-form-actions { flex-wrap: wrap; }
        .oauth-review .vb-btn { min-height: 44px; }
        .oauth-review .vb-info-row { flex-wrap: wrap; gap: var(--space-2); }
        .oauth-review .vb-info-value { overflow-wrap: anywhere; }
        @media (max-width: 480px) { body { padding: var(--space-4); } .oauth-review .vb-form-actions .vb-btn { flex: 1 1 100%; } }
    </style>
</head>
<body><main class="oauth-review"><a class="oauth-brand" href="/"><?= e(app_name()) ?></a><?= $content ?></main></body>
</html>
