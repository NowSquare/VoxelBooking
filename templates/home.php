<?php
/**
 * Public homepage — two modes controlled by enable_applications setting.
 *
 * Default mode: simple logo/name page with admin login link.
 * Applications mode: full landing page with hero, features, and request form
 * with anti-spam (honeypot + timestamp) pipeline.
 *
 * Variables: $applicationsEnabled, $csrfToken, $flash
 */
$applicationsEnabled = $applicationsEnabled ?? false;
$csrfToken = $csrfToken ?? '';
$flash     = $flash ?? null;
$isLoggedIn = $isLoggedIn ?? false;
?>
<!DOCTYPE html>
<html lang="<?= \App\Engine\Locale::getLocale() ?>" dir="<?= \App\Engine\Locale::direction() ?>">
<head>
    <script src="/js/theme.js"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= __('public.meta_description') ?>">
    <title><?= __('public.meta_title') ?> — <?= htmlspecialchars(app_name(), ENT_QUOTES, 'UTF-8') ?></title>
    <?php include __DIR__ . '/partials/admin-head.php'; ?>
    <link rel="stylesheet" href="/assets/css/admin-css.css">
    <style>
        /* ─── Tokens ─── */
        :root {
            --lp-max: 1120px;
            --lp-gap: 2rem;
            --lp-accent: var(--vb-accent, #4F46E5);
            --lp-text: var(--vb-text-primary, #0F172A);
            --lp-muted: var(--vb-text-secondary, #64748B);
            --lp-faint: var(--vb-text-tertiary, #94A3B8);
            --lp-border: rgba(226, 232, 240, 0.6);
            --lp-bg: #FAFBFC;
            --lp-nav-bg: rgba(250, 251, 252, 0.85);
            --lp-card-bg: rgba(255, 255, 255, 0.65);
            --lp-card-border: rgba(255, 255, 255, 0.6);
            --lp-card-glow: rgba(255, 255, 255, 0.8);
            --lp-input-bg: #fff;
        }
        [data-theme="dark"] {
            --lp-accent: var(--vb-accent, #818CF8);
            --lp-text: var(--vb-text-primary, #ededf0);
            --lp-muted: var(--vb-text-secondary, #8888a0);
            --lp-faint: var(--vb-text-tertiary, #5c5c72);
            --lp-border: rgba(255, 255, 255, 0.08);
            --lp-bg: #0c0c14;
            --lp-nav-bg: rgba(12, 12, 20, 0.85);
            --lp-card-bg: rgba(21, 21, 32, 0.7);
            --lp-card-border: rgba(255, 255, 255, 0.06);
            --lp-card-glow: rgba(255, 255, 255, 0.03);
            --lp-input-bg: rgba(28, 28, 46, 0.8);
        }
        html { overflow: auto !important; }
        body {
            background: var(--lp-bg);
            color: var(--lp-text);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* ─── Atmosphere layer ─── */
        .lp-atmosphere {
            position: fixed;
            inset: 0;
            z-index: 0;
            pointer-events: none;
            overflow: hidden;
        }
        .lp-orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(80px);
            opacity: 0.12;
        }
        .lp-orb:nth-child(1) {
            width: 600px; height: 600px;
            top: -15%; left: -5%;
            background: #818cf8;
            animation: lp-orb-drift 28s ease-in-out infinite alternate;
        }
        .lp-orb:nth-child(2) {
            width: 500px; height: 500px;
            top: 10%; right: -10%;
            background: #a78bfa;
            animation: lp-orb-drift 25s ease-in-out infinite alternate-reverse;
        }
        .lp-orb:nth-child(3) {
            width: 450px; height: 450px;
            bottom: -10%; left: 30%;
            background: #6366f1;
            animation: lp-orb-drift 30s ease-in-out infinite alternate;
        }
        /* SVG noise grain overlay */
        body::after {
            content: '';
            position: fixed;
            inset: 0;
            z-index: 9999;
            pointer-events: none;
            opacity: 0.025;
            background: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
        }

        /* ─── Nav ─── */
        .lp-nav {
            position: sticky;
            top: 0;
            z-index: 100;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 max(1.5rem, calc((100% - var(--lp-max)) / 2 + var(--lp-gap)));
            height: 52px;
            background: var(--lp-nav-bg);
            backdrop-filter: blur(12px) saturate(180%);
            -webkit-backdrop-filter: blur(12px) saturate(180%);
            border-bottom: 1px solid var(--lp-border);
        }
        .lp-logo {
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            color: var(--lp-text);
        }
        .lp-logo-icon { width: 22px; height: 24px; color: var(--lp-accent); }
        .lp-logo-text { font-size: 0.875rem; font-weight: 650; letter-spacing: -0.03em; }
        .lp-nav-actions { display: flex; align-items: center; gap: 0.625rem; }
        .lp-nav-btn {
            font-size: 0.8125rem;
            font-weight: 500;
            color: var(--lp-muted);
            text-decoration: none;
            padding: 0.3125rem 0.625rem;
            border-radius: 6px;
            transition: color 150ms, background 150ms;
        }
        .lp-nav-btn:hover { color: var(--lp-text); background: var(--vb-bg-hover, rgba(0,0,0,0.03)); }
        .lp-nav-btn-primary {
            color: #fff;
            background: var(--lp-accent);
            font-weight: 600;
        }
        .lp-nav-btn-primary:hover { color: #fff; background: #4338CA; }

        /* ─── Simple mode ─── */
        .lp-simple {
            position: relative;
            z-index: 1;
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 1.5rem;
            padding: 2rem;
        }
        .lp-simple-cube-wrap { position: relative; }
        .lp-simple-cube-wrap::after {
            content: '';
            position: absolute;
            bottom: -20px; left: 50%; transform: translateX(-50%);
            width: 80px; height: 16px;
            background: radial-gradient(ellipse, rgba(79, 70, 229, 0.2) 0%, transparent 70%);
            filter: blur(6px);
            animation: lp-glow 4s ease-in-out infinite;
        }
        .lp-simple-cube {
            color: var(--lp-accent);
            filter: drop-shadow(0 12px 32px rgba(79, 70, 229, 0.25));
            animation: lp-float 6s ease-in-out infinite;
        }
        .lp-simple-name {
            font-size: clamp(2rem, 4vw, 2.75rem);
            font-weight: 800;
            letter-spacing: -0.05em;
            background: linear-gradient(135deg, var(--lp-text) 30%, var(--lp-accent) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .lp-simple-sub {
            font-size: 0.8125rem;
            color: var(--lp-faint);
            letter-spacing: 0.08em;
            text-transform: uppercase;
            font-weight: 500;
        }

        /* ─── Applications mode: unified hero ─── */
        .lp-hero {
            position: relative;
            z-index: 1;
            max-width: var(--lp-max);
            width: 100%;
            margin: 0 auto;
            padding: 3rem var(--lp-gap);
            min-height: calc(100vh - 52px);
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 3rem;
            overflow: hidden;
        }
        .lp-hero-top {
            display: grid;
            grid-template-columns: 1fr 380px;
            gap: 4rem;
            align-items: center;
            width: 100%;
        }
        .lp-hero-content {}

        /* Eyebrow */
        .lp-eyebrow {
            font-size: 0.6875rem;
            font-weight: 500;
            color: var(--lp-faint);
            letter-spacing: 0.12em;
            text-transform: uppercase;
            margin-bottom: 0.875rem;
        }

        .lp-hero h1 {
            font-size: clamp(2rem, 4vw, 2.75rem);
            font-weight: 800;
            letter-spacing: -0.04em;
            line-height: 1.1;
            margin-bottom: 0.875rem;
            color: var(--lp-text);
        }
        .lp-hero h1 em {
            font-style: normal;
            background: linear-gradient(135deg, var(--lp-accent) 0%, #818cf8 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .lp-hero-sub {
            font-size: 1rem;
            color: var(--lp-muted);
            line-height: 1.65;
            max-width: 420px;
            margin-bottom: 2.5rem;
        }

        /* ─── Feature list: inline under hero content ───
         *
         * Replaces the detached 4-column card strip with a compact vertical
         * list inside the hero left column. Each item has a curated inline
         * SVG (from Lucide icon set) + title + description on one line.
         *
         * SVGs are sourced from lucide.dev and used as inline markup because
         * the homepage is a standalone public page that intentionally does not
         * load the admin Lucide JS runtime (admin/app.js). This is documented
         * in .ai/11-VoxelBooking-Visual-Design.md §8.2.
         */
        .lp-features {
            display: flex;
            flex-direction: column;
            gap: 0.875rem;
            margin-top: 1.5rem;
        }
        .lp-feature-item {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
        }
        .lp-feature-icon {
            width: 20px;
            height: 20px;
            flex-shrink: 0;
            color: var(--lp-accent);
            margin-top: 1px;
        }
        .lp-feature-text h3 {
            font-size: 0.8125rem;
            font-weight: 650;
            letter-spacing: -0.01em;
            margin: 0 0 0.125rem;
        }
        .lp-feature-text p {
            font-size: 0.75rem;
            color: var(--lp-muted);
            line-height: 1.5;
            margin: 0;
        }

        /* ─── Form card — glassmorphic ─── */
        .lp-form-card {
            background: var(--lp-card-bg);
            backdrop-filter: blur(20px) saturate(180%);
            -webkit-backdrop-filter: blur(20px) saturate(180%);
            border: 1px solid var(--lp-card-border);
            border-radius: 20px;
            padding: 1.75rem;
            box-shadow:
                inset 0 1px 0 var(--lp-card-glow),
                0 1px 3px rgba(0, 0, 0, 0.04),
                0 8px 32px rgba(0, 0, 0, 0.06);
        }
        .lp-form-card h2 {
            font-size: 1rem;
            font-weight: 700;
            letter-spacing: -0.02em;
            margin-bottom: 0.1875rem;
        }
        .lp-form-desc {
            font-size: 0.8125rem;
            color: var(--lp-muted);
            margin-bottom: 1.25rem;
            line-height: 1.45;
        }
        .lp-row { display: grid; grid-template-columns: 1fr 1fr; gap: 0.625rem; }
        .lp-input {
            width: 100%;
            padding: 0.5rem 0.625rem;
            border: 1px solid var(--lp-border);
            border-radius: 7px;
            font-size: 0.8125rem;
            background: var(--lp-input-bg);
            transition: border-color 200ms, box-shadow 200ms;
            outline: none;
            font-family: inherit;
            color: var(--lp-text);
        }
        .lp-input:focus {
            border-color: var(--lp-accent);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.08);
        }
        .lp-input::placeholder { color: var(--lp-faint); }
        textarea.lp-input { resize: vertical; }
        .lp-label {
            display: block;
            font-size: 0.6875rem;
            font-weight: 550;
            margin-bottom: 0.25rem;
            color: var(--lp-muted);
            letter-spacing: 0.01em;
        }
        .lp-form-group { margin-bottom: 0.625rem; }
        .lp-submit {
            width: 100%;
            padding: 0.625rem 1rem;
            border: none;
            border-radius: 10px;
            font-size: 0.8125rem;
            font-weight: 600;
            font-family: inherit;
            color: #fff;
            cursor: pointer;
            background: linear-gradient(135deg, #4F46E5 0%, #6366f1 50%, #818cf8 100%);
            transition: all 200ms cubic-bezier(0.4, 0, 0.2, 1);
            margin-top: 0.375rem;
            letter-spacing: -0.01em;
        }
        .lp-submit:hover {
            box-shadow: 0 4px 16px rgba(79, 70, 229, 0.35), 0 1px 3px rgba(0, 0, 0, 0.08);
            filter: brightness(1.05);
        }
        .lp-submit:active { transform: scale(0.985); filter: brightness(0.98); }

        /* Honeypot */
        .lp-hp { position: absolute; left: -9999px; opacity: 0; height: 0; overflow: hidden; }

        /* ─── Footer ─── */
        .lp-footer {
            position: relative;
            z-index: 1;
            margin-top: auto;
            padding: 2rem var(--lp-gap);
            text-align: center;
            font-size: 0.6875rem;
            color: var(--lp-faint);
            letter-spacing: 0.01em;
        }

        /* ─── Toast ─── */
        .lp-toast {
            padding: 0.625rem 0.875rem;
            border-radius: 8px;
            font-size: 0.8125rem;
            font-weight: 500;
            margin-bottom: 0.875rem;
            animation: lp-toast-in 300ms ease-out;
        }
        .lp-toast-success {
            background: rgba(16, 185, 129, 0.08);
            color: #059669;
            border: 1px solid rgba(16, 185, 129, 0.2);
        }
        .lp-toast-error {
            background: rgba(239, 68, 68, 0.08);
            color: #DC2626;
            border: 1px solid rgba(239, 68, 68, 0.2);
        }
        @keyframes lp-toast-in {
            from { opacity: 0; transform: translateY(-6px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* ─── Animations ─── */
        @keyframes lp-float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-8px); }
        }
        @keyframes lp-glow {
            0%, 100% { opacity: 1; transform: translateX(-50%) scale(1); }
            50% { opacity: 0.4; transform: translateX(-50%) scale(0.8); }
        }
        @keyframes lp-orb-drift {
            0% { transform: translate(0, 0) scale(1); }
            33% { transform: translate(30px, -20px) scale(1.05); }
            66% { transform: translate(-20px, 15px) scale(0.95); }
            100% { transform: translate(10px, -10px) scale(1.02); }
        }

        /* Scroll reveal */
        .lp-reveal {
            opacity: 0;
            transform: translateY(24px);
            transition: opacity 600ms cubic-bezier(0.4, 0, 0.2, 1),
                        transform 600ms cubic-bezier(0.4, 0, 0.2, 1);
        }
        .lp-reveal.is-visible {
            opacity: 1;
            transform: translateY(0);
        }

        /* ─── Responsive ─── */
        @media (max-width: 768px) {
            .lp-hero-top {
                grid-template-columns: 1fr;
                gap: 2rem;
            }
            .lp-hero {
                padding: 2rem var(--lp-gap);
                min-height: auto;
            }
            .lp-form-card { max-width: 480px; }
        }
        @media (max-width: 520px) {
            .lp-row { grid-template-columns: 1fr; }
        }
        @media (prefers-reduced-motion: reduce) {
            .lp-simple-cube { animation: none; }
            .lp-simple-cube-wrap::after { animation: none; }
            .lp-orb { animation: none; }
            .lp-toast { animation: none; }
            .lp-reveal { opacity: 1; transform: none; transition: none; }
        }

        /* ─── Dark mode adjustments ─── */
        [data-theme="dark"] .lp-orb { opacity: 0.18; }
        [data-theme="dark"] body::after { opacity: 0.015; }
        [data-theme="dark"] .lp-simple-cube {
            filter: drop-shadow(0 12px 32px rgba(129, 140, 248, 0.35));
        }
        [data-theme="dark"] .lp-simple-cube-wrap::after {
            background: radial-gradient(ellipse, rgba(129, 140, 248, 0.25) 0%, transparent 70%);
        }
        [data-theme="dark"] .lp-submit {
            background: linear-gradient(135deg, #818cf8 0%, #6366f1 50%, #4F46E5 100%);
        }

        /* ─── Theme toggle (nav) ─── */
        .lp-theme-toggle {
            width: 36px; height: 36px; border-radius: 9999px;
            display: flex; align-items: center; justify-content: center;
            background: transparent; border: 1px solid var(--lp-border);
            cursor: pointer; position: relative;
            color: var(--lp-muted);
            transition: background 150ms, color 150ms;
        }
        .lp-theme-toggle:hover { background: var(--vb-bg-hover, rgba(0,0,0,0.03)); color: var(--lp-text); }
        .lp-theme-toggle .icon-sun,
        .lp-theme-toggle .icon-moon { position: absolute; transition: opacity 150ms, transform 200ms cubic-bezier(0.34, 1.56, 0.64, 1); }
        .lp-theme-toggle .icon-sun { opacity: 1; transform: rotate(0deg) scale(1); }
        .lp-theme-toggle .icon-moon { opacity: 0; transform: rotate(-90deg) scale(0.7); }
        [data-theme="dark"] .lp-theme-toggle .icon-sun { opacity: 0; transform: rotate(90deg) scale(0.7); }
        [data-theme="dark"] .lp-theme-toggle .icon-moon { opacity: 1; transform: rotate(0deg) scale(1); }
    </style>
</head>
<body>

    <!-- Atmosphere layer -->
    <div class="lp-atmosphere">
        <div class="lp-orb"></div>
        <div class="lp-orb"></div>
        <div class="lp-orb"></div>
    </div>

    <!-- Nav — full width -->
    <nav class="lp-nav">
        <a href="/" class="lp-logo">
            <svg class="lp-logo-icon" viewBox="0 0 48 52" xmlns="http://www.w3.org/2000/svg">
                <polygon points="24,2 46,14 24,26 2,14" fill="currentColor" opacity="1.0"/>
                <polygon points="2,14 24,26 24,50 2,38" fill="currentColor" opacity="0.7"/>
                <polygon points="46,14 24,26 24,50 46,38" fill="currentColor" opacity="0.4"/>
            </svg>
            <span class="lp-logo-text"><?= htmlspecialchars(app_name(), ENT_QUOTES, 'UTF-8') ?></span>
        </a>
        <div class="lp-nav-actions">
            <button type="button" class="lp-theme-toggle" id="lp-theme-toggle" aria-label="<?= __('public.toggle_theme') ?>">
                <svg class="icon-sun" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
                <svg class="icon-moon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
            </button>
            <?php if ($isLoggedIn): ?>
            <a href="/admin" class="lp-nav-btn lp-nav-btn-primary"><?= __('public.cta_dashboard') ?></a>
            <?php else: ?>
            <a href="/admin/login" class="lp-nav-btn"><?= __('public.cta_login') ?></a>
            <?php endif; ?>
        </div>
    </nav>

    <?php if (!$applicationsEnabled): ?>
    <!-- ═══ Simple mode ═══ -->
    <section class="lp-simple">
        <div class="lp-simple-cube-wrap">
            <svg class="lp-simple-cube" width="120" height="132" viewBox="0 0 48 52" xmlns="http://www.w3.org/2000/svg">
                <polygon points="24,2 46,14 24,26 2,14" fill="currentColor" opacity="1.0"/>
                <polygon points="2,14 24,26 24,50 2,38" fill="currentColor" opacity="0.7"/>
                <polygon points="46,14 24,26 24,50 46,38" fill="currentColor" opacity="0.4"/>
            </svg>
        </div>
        <div class="lp-simple-name"><?= htmlspecialchars(app_name(), ENT_QUOTES, 'UTF-8') ?></div>
        <div class="lp-simple-sub">Booking infrastructure</div>
    </section>

    <?php else: ?>
    <!-- ═══ Applications mode ═══ -->
    <section class="lp-hero">
        <!-- Top row: headline + form card side-by-side -->
        <div class="lp-hero-top">
            <div class="lp-hero-content">
                <p class="lp-eyebrow">Scheduling infrastructure</p>
                <h1><?= __('public.hero_heading') ?></h1>
                <p class="lp-hero-sub"><?= __('public.hero_sub') ?></p>

                <!-- Feature list — inline under hero description.
                     Icons: curated Lucide SVG paths (lucide.dev). Used as inline
                     markup because this public page intentionally does not load
                     the admin Lucide JS runtime (admin/app.js). -->
                <div class="lp-features">
                    <div class="lp-feature-item">
                        <svg class="lp-feature-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/><path d="M8 14h.01"/><path d="M12 14h.01"/><path d="M16 14h.01"/><path d="M8 18h.01"/><path d="M12 18h.01"/></svg>
                        <div class="lp-feature-text">
                            <h3><?= __('public.feature_1_title') ?></h3>
                            <p><?= __('public.feature_1_desc') ?></p>
                        </div>
                    </div>
                    <div class="lp-feature-item">
                        <svg class="lp-feature-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/><path d="M4 2C2.8 3.7 2 5.7 2 8"/><path d="M22 8c0-2.3-.8-4.3-2-6"/></svg>
                        <div class="lp-feature-text">
                            <h3><?= __('public.feature_2_title') ?></h3>
                            <p><?= __('public.feature_2_desc') ?></p>
                        </div>
                    </div>
                    <div class="lp-feature-item">
                        <svg class="lp-feature-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        <div class="lp-feature-text">
                            <h3><?= __('public.feature_3_title') ?></h3>
                            <p><?= __('public.feature_3_desc') ?></p>
                        </div>
                    </div>
                    <div class="lp-feature-item">
                        <svg class="lp-feature-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>
                        <div class="lp-feature-text">
                            <h3><?= __('public.feature_4_title') ?></h3>
                            <p><?= __('public.feature_4_desc') ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Form card -->
            <div>
                <?php if ($flash): ?>
                <div class="lp-toast lp-toast-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>">
                    <?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?>
                </div>
                <?php endif; ?>

                <div class="lp-form-card" id="request">
                    <h2><?= __('public.request_heading') ?></h2>
                    <p class="lp-form-desc"><?= __('public.request_desc') ?></p>

                    <form method="POST" action="/request-access" id="request-form">
                        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="__ts" id="ra-ts" value="">

                        <div class="lp-row">
                        <div class="lp-form-group">
                            <label for="ra-business" class="lp-label"><?= __('public.field_business_name') ?> *</label>
                            <input type="text" id="ra-business" name="business_name" class="lp-input" required
                                   value="<?= e(old('business_name')) ?>">
                        </div>
                        <div class="lp-form-group">
                            <label for="ra-contact" class="lp-label"><?= __('public.field_contact_name') ?> *</label>
                            <input type="text" id="ra-contact" name="contact_name" class="lp-input" required
                                   value="<?= e(old('contact_name')) ?>">
                        </div>
                        </div>

                        <div class="lp-form-group">
                            <label for="ra-email" class="lp-label"><?= __('public.field_email') ?> *</label>
                            <input type="email" id="ra-email" name="email" class="lp-input" required
                                   value="<?= e(old('email')) ?>">
                        </div>

                        <div class="lp-row">
                        <div class="lp-form-group">
                            <label for="ra-phone" class="lp-label"><?= __('public.field_phone') ?></label>
                            <input type="tel" id="ra-phone" name="phone" class="lp-input"
                                   value="<?= e(old('phone')) ?>">
                        </div>
                        <div class="lp-form-group">
                            <label for="ra-website" class="lp-label"><?= __('public.field_website') ?></label>
                            <input type="url" id="ra-website" name="website" class="lp-input"
                                   placeholder="https://example.com"
                                   value="<?= e(old('website')) ?>">
                        </div>
                        </div>

                        <div class="lp-form-group">
                            <label for="ra-message" class="lp-label"><?= __('public.field_message') ?></label>
                            <textarea id="ra-message" name="message" class="lp-input" rows="3"
                                      placeholder="<?= __('public.field_message_placeholder') ?>"><?= e(old('message')) ?></textarea>
                        </div>

                        <!-- Honeypot -->
                        <div class="lp-hp" aria-hidden="true" tabindex="-1">
                            <input type="text" name="__hp" autocomplete="off" tabindex="-1">
                        </div>

                        <button type="submit" class="lp-submit"><?= __('public.submit_button') ?></button>
                    </form>
                </div>
            </div>
        </div>
    </section>

    <script src="/js/homepage.js"></script>

    <?php endif; ?>

    <!-- Footer -->
    <footer class="lp-footer">
        <?= __('public.footer_text', ['year' => date('Y'), 'app_name' => app_name()]) ?>
    </footer>

    <script src="/js/theme-toggle.js"></script>
</body>
</html>

