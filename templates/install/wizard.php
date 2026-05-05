<?php
/**
 * Installation Wizard Template — VoxelBooking
 *
 * Uses the official admin design tokens and VoxelBooking logo system.
 * Dark mode via [data-theme]. Lucide icons only. No emoji.
 *
 * Variables: $step (int|'complete'), $checks (array), $errors (array),
 *            $flash (array), $session (array), $csrfToken (string)
 */

$allChecksPassed = empty(array_filter($checks, fn($c) => $c['required'] && !$c['passed']));
$stepTitles = [1 => __('install.wizard.step_bar_1'), 2 => __('install.wizard.step_bar_2'), '2-reconnect' => __('install.wizard.step_bar_2'), 3 => __('install.wizard.step_bar_3'), 4 => __('install.wizard.step_bar_4'), 5 => __('install.wizard.step_bar_5')];
$displayStep = is_numeric($step) ? (int) $step : (str_starts_with((string) $step, '2') ? 2 : (int) $step);
?>
<!DOCTYPE html>
<html lang="<?= \App\Engine\Locale::getLocale() ?>" dir="<?= \App\Engine\Locale::direction() ?>" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="description" content="<?= ($_ENV['APP_NAME'] ?? 'VoxelBooking') ?> Installation Wizard">
    <title><?= __('install.wizard.page_title', ['app_name' => $_ENV['APP_NAME'] ?? 'VoxelBooking']) ?></title>
    <link rel="icon" href="/favicon.ico" type="image/png">
    <style>
        /* ── Self-hosted Inter (PRD §II: WOFF2, self-hosted) ── */
        @font-face {
            font-family: 'Inter';
            font-style: normal;
            font-weight: 100 900;
            font-display: swap;
            src: url('/fonts/inter-latin-ext.woff2') format('woff2');
            unicode-range: U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C0, U+2113, U+2C60-2C7F, U+A720-A7FF;
        }
        @font-face {
            font-family: 'Inter';
            font-style: normal;
            font-weight: 100 900;
            font-display: swap;
            src: url('/fonts/inter-latin.woff2') format('woff2');
            unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD;
        }
    </style>
    <style>
        /* ── Admin Design Tokens (PRD §3 / Visual Design §3) ── */
        :root {
            /* Light mode tokens */
            --vb-admin-bg-base: #F8FAFC;
            --vb-admin-bg-surface: #FFFFFF;
            --vb-admin-bg-raised: #FFFFFF;
            --vb-admin-bg-input: #FFFFFF;
            --vb-admin-bg-well: #F1F5F9;
            --vb-admin-bg-hover: #F1F5F9;
            --vb-admin-border-subtle: #E2E8F0;
            --vb-admin-border-medium: #CBD5E1;
            --vb-admin-border-strong: #94A3B8;
            --vb-admin-text-primary: #0F172A;
            --vb-admin-text-secondary: #475569;
            --vb-admin-text-tertiary: #94A3B8;
            --vb-admin-text-ghost: #CBD5E1;
            --vb-admin-accent: #4F46E5;
            --vb-admin-accent-hover: #4338CA;
            --vb-admin-accent-dim: rgba(79, 70, 229, 0.08);
            --vb-admin-accent-glow: rgba(79, 70, 229, 0.15);
            --vb-admin-shadow-sm: 0 1px 3px rgba(0,0,0,0.04), 0 1px 2px rgba(0,0,0,0.03);
            --vb-admin-shadow-md: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -2px rgba(0,0,0,0.03);
            --vb-admin-shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.06), 0 4px 6px -4px rgba(0,0,0,0.04);

            /* Semantic */
            --vb-admin-success: #059669;
            --vb-admin-success-bg: #ECFDF5;
            --vb-admin-warning: #D97706;
            --vb-admin-warning-bg: #FFFBEB;
            --vb-admin-error: #DC2626;
            --vb-admin-error-bg: #FEF2F2;
            --vb-admin-info: #2563EB;
            --vb-admin-info-bg: #EFF6FF;

            /* Typography */
            --vb-font-sans: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
            --vb-text-sm: 0.8125rem;
            --vb-text-base: 0.875rem;
            --vb-text-md: 0.9375rem;
            --vb-text-lg: 1.125rem;
            --vb-text-xl: 1.375rem;
            --vb-leading-normal: 1.5;
            --vb-tracking-tight: -0.025em;
            --vb-tracking-normal: -0.011em;

            /* Layout */
            --vb-radius: 12px;
            --vb-radius-sm: 8px;
        }

        [data-theme="dark"] {
            --vb-admin-bg-base: #0F172A;
            --vb-admin-bg-surface: #1E293B;
            --vb-admin-bg-raised: #334155;
            --vb-admin-bg-input: rgba(30, 41, 59, 0.6);
            --vb-admin-bg-well: #0F172A;
            --vb-admin-bg-hover: #334155;
            --vb-admin-border-subtle: #334155;
            --vb-admin-border-medium: #475569;
            --vb-admin-border-strong: #64748B;
            --vb-admin-text-primary: #F8FAFC;
            --vb-admin-text-secondary: #94A3B8;
            --vb-admin-text-tertiary: #64748B;
            --vb-admin-text-ghost: #475569;
            --vb-admin-accent: #818CF8;
            --vb-admin-accent-hover: #6366F1;
            --vb-admin-accent-dim: rgba(129, 140, 248, 0.12);
            --vb-admin-accent-glow: rgba(129, 140, 248, 0.3);
            --vb-admin-shadow-sm: none;
            --vb-admin-shadow-md: none;
            --vb-admin-shadow-lg: none;
            --vb-admin-success: #34D399;
            --vb-admin-success-bg: rgba(52, 211, 153, 0.1);
            --vb-admin-warning: #FBBF24;
            --vb-admin-warning-bg: rgba(251, 191, 36, 0.1);
            --vb-admin-error: #F87171;
            --vb-admin-error-bg: rgba(248, 113, 113, 0.1);
            --vb-admin-info: #60A5FA;
            --vb-admin-info-bg: rgba(96, 165, 250, 0.1);
        }

        /* ── Reset ── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: var(--vb-font-sans);
            font-feature-settings: 'cv02' 1, 'cv03' 1, 'cv04' 1, 'cv11' 1;
            background:
                radial-gradient(ellipse at 20% 50%, rgba(79, 70, 229, 0.03) 0%, transparent 60%),
                radial-gradient(ellipse at 80% 20%, rgba(99, 102, 241, 0.025) 0%, transparent 50%),
                var(--vb-admin-bg-base);
            color: var(--vb-admin-text-primary);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
            line-height: var(--vb-leading-normal);
            letter-spacing: var(--vb-tracking-normal);
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            transition: background-color 0.2s ease, color 0.2s ease;
        }

        [data-theme="dark"] body {
            font-weight: 350;
            background:
                radial-gradient(ellipse at 20% 50%, rgba(129, 140, 248, 0.05) 0%, transparent 60%),
                radial-gradient(ellipse at 80% 20%, rgba(99, 102, 241, 0.04) 0%, transparent 50%),
                var(--vb-admin-bg-base);
        }

        /* ── Reduced motion ── */
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }

        /* ── Global focus-visible (WCAG 2.1 AA) ── */
        :focus-visible {
            outline: 2px solid var(--vb-admin-accent);
            outline-offset: 2px;
        }

        :focus:not(:focus-visible) {
            outline: none;
        }

        /* ── Layout ── */
        .wizard { width: 100%; max-width: 560px; }

        /* ── Entrance Animations ── */
        .wizard-header {
            animation: wizard-rise 0.7s cubic-bezier(0.16, 1, 0.3, 1) both;
        }

        .steps {
            animation: wizard-rise 0.6s cubic-bezier(0.16, 1, 0.3, 1) 0.08s both;
        }

        .card {
            animation: wizard-rise 0.7s cubic-bezier(0.16, 1, 0.3, 1) 0.14s both;
        }

        @keyframes wizard-rise {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* ── Hero Logo (Visual Design §2 — hero variant) ── */
        .vb-hero-logo {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 16px;
            margin-bottom: 0.5rem;
        }

        .vb-hero-icon {
            width: 56px;
            height: 56px;
            color: var(--vb-admin-accent);
            filter: drop-shadow(0 12px 24px var(--vb-admin-accent-glow));
            animation: voxel-float 5s cubic-bezier(0.4, 0, 0.2, 1) infinite;
        }

        .voxel-top   { fill: currentColor; opacity: 1; }
        .voxel-left  { fill: currentColor; opacity: 0.7; }
        .voxel-right { fill: currentColor; opacity: 0.4; }

        .vb-hero-text {
            font-size: 1.875rem;
            font-weight: 700;
            letter-spacing: -0.04em;
        }

        @keyframes voxel-float {
            0%, 100% { transform: translateY(0) scale(1); }
            50% { transform: translateY(-5px) scale(1.015); }
        }

        .wizard-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .wizard-subtitle {
            color: var(--vb-admin-text-tertiary);
            font-size: var(--vb-text-base);
            margin-top: 0.375rem;
            letter-spacing: 0;
        }

        /* ── Step Indicator (dots + connecting track) ── */
        .steps {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0;
            margin-bottom: 2rem;
        }

        .step-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: var(--vb-admin-border-subtle);
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            z-index: 1;
        }

        .step-dot.active {
            background: var(--vb-admin-accent);
            transform: scale(1.3);
            box-shadow: 0 0 0 4px var(--vb-admin-accent-dim);
        }

        .step-dot.done { background: var(--vb-admin-success); }

        .step-track {
            width: 28px;
            height: 2px;
            background: var(--vb-admin-border-subtle);
            transition: background-color 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .step-track.done {
            background: var(--vb-admin-success);
        }

        /* ── Card (layered shadows + inner highlight for tactile depth) ── */
        .card {
            background: var(--vb-admin-bg-surface);
            border-radius: var(--vb-radius);
            padding: 2rem;
            box-shadow:
                inset 0 1px 0 rgba(255, 255, 255, 0.7),
                0 0 0 1px rgba(0, 0, 0, 0.03),
                0 2px 4px rgba(0, 0, 0, 0.02),
                0 8px 16px -4px rgba(0, 0, 0, 0.04),
                0 24px 48px -12px rgba(0, 0, 0, 0.06);
        }

        [data-theme="dark"] .card {
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.06);
            border: 1px solid var(--vb-admin-border-subtle);
        }

        .card-header {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }

        .card-header-icon {
            width: 20px;
            height: 20px;
            flex-shrink: 0;
            color: var(--vb-admin-text-tertiary);
            margin-top: 2px;
        }

        .card-header-content {
            flex: 1;
            min-width: 0;
        }

        .card-title {
            font-size: var(--vb-text-lg);
            font-weight: 600;
            letter-spacing: var(--vb-tracking-tight);
            margin: 0;
        }

        .card-desc {
            font-size: var(--vb-text-sm);
            color: var(--vb-admin-text-tertiary);
            margin-top: 0.25rem;
            line-height: 1.5;
        }

        /* ── Forms ── */
        .form-group { margin-bottom: 1.25rem; }

        .form-label {
            display: block;
            font-size: var(--vb-text-sm);
            font-weight: 500;
            margin-bottom: 0.375rem;
            color: var(--vb-admin-text-primary);
        }

        .form-required {
            color: var(--vb-admin-error);
            font-weight: 400;
        }

        .form-input {
            width: 100%;
            padding: 0.5625rem 0.75rem;
            font-size: var(--vb-text-base);
            font-family: var(--vb-font-sans);
            background: var(--vb-admin-bg-input);
            border: 1px solid var(--vb-admin-border-subtle);
            border-radius: var(--vb-radius-sm);
            color: var(--vb-admin-text-primary);
            transition: border-color 0.15s, box-shadow 0.15s;
            outline: none;
        }

        .form-input:focus {
            border-color: var(--vb-admin-accent);
            box-shadow: 0 0 0 3px var(--vb-admin-accent-dim);
            transition: border-color 0.15s, box-shadow 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .form-input.error { border-color: var(--vb-admin-error); }
        .form-input.error:focus { box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.08); }

        .form-error {
            font-size: var(--vb-text-sm);
            color: var(--vb-admin-error);
            margin-top: 0.25rem;
        }

        .form-hint {
            font-size: var(--vb-text-sm);
            color: var(--vb-admin-text-tertiary);
            margin-top: 0.25rem;
        }

        select.form-input {
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' width='20' height='20' viewBox='0 0 24 24' fill='none' stroke='%2394A3B8' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'%3e%3cpath d='m6 9 6 6 6-6'/%3e%3c/svg%3e");
            background-position: right 0.5rem center;
            background-repeat: no-repeat;
            background-size: 1.25rem;
            padding-inline-end: 2.5rem;
        }

        [dir="rtl"] select.form-input {
            background-position: left 0.5rem center;
        }

        /* ── Buttons (tactile depth via inner glow + accent shadow) ── */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.5625rem 1.5rem;
            font-size: var(--vb-text-base);
            font-weight: 500;
            font-family: var(--vb-font-sans);
            border: none;
            border-radius: var(--vb-radius-sm);
            cursor: pointer;
            transition: background-color 0.15s, opacity 0.15s, transform 0.1s, box-shadow 0.15s;
            text-decoration: none;
            gap: 0.5rem;
            min-height: 40px;
            position: relative;
        }

        .btn:active:not(:disabled) { transform: scale(0.97); transition-duration: 80ms; }

        .btn-primary {
            background: var(--vb-admin-accent);
            color: #FFFFFF;
            box-shadow:
                inset 0 1px 0 rgba(255, 255, 255, 0.15),
                inset 0 -1px 0 rgba(0, 0, 0, 0.1),
                0 1px 3px rgba(0, 0, 0, 0.08),
                0 2px 8px var(--vb-admin-accent-glow);
        }

        .btn-primary:hover:not(:disabled) {
            background: var(--vb-admin-accent-hover);
            box-shadow:
                inset 0 1px 0 rgba(255, 255, 255, 0.15),
                inset 0 -1px 0 rgba(0, 0, 0, 0.1),
                0 2px 4px rgba(0, 0, 0, 0.1),
                0 4px 12px var(--vb-admin-accent-glow);
            transform: translateY(-1px);
        }

        .btn-primary:active:not(:disabled) {
            box-shadow:
                inset 0 2px 3px rgba(0, 0, 0, 0.15),
                0 1px 2px rgba(0, 0, 0, 0.05);
            transform: scale(0.97);
            transition-duration: 80ms;
        }

        .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }

        .btn-ghost {
            background: transparent;
            color: var(--vb-admin-text-secondary);
            padding: 0.5rem 1rem;
        }

        .btn-ghost:hover {
            color: var(--vb-admin-text-primary);
            background: var(--vb-admin-bg-well);
        }

        .btn-block { width: 100%; }

        /* Password field wrapper: input + ghost icon buttons in a row */
        .password-field {
            display: flex;
            align-items: center;
            gap: 0.375rem;
        }
        .password-field .form-input { flex: 1; }
        .password-field .btn-icon {
            background: transparent;
            border: none;
            border-radius: var(--vb-radius-sm);
            padding: 0.375rem;
            cursor: pointer;
            color: var(--vb-admin-text-secondary);
            display: flex;
            align-items: center;
            justify-content: center;
            transition: color 0.15s, background 0.15s;
            min-height: 30px;
            min-width: 30px;
        }
        .password-field .btn-icon:hover {
            color: var(--vb-admin-accent);
            background: var(--vb-admin-accent-dim);
        }
        .password-field .btn-icon svg { width: 15px; height: 15px; }

        /* Section divider for form grouping */
        .section-divider {
            border: none;
            border-top: 1px solid var(--vb-admin-border-subtle);
            margin: 1.5rem 0;
        }

        /* ── Reconnect Option Cards (Step 2b) ── */
        .reconnect-options {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .reconnect-option {
            border: 1px solid var(--vb-admin-border-subtle);
            border-radius: var(--vb-radius);
            padding: 1.25rem;
            transition: border-color 0.15s, background-color 0.15s;
        }

        .reconnect-option:hover {
            border-color: var(--vb-admin-accent);
            background: var(--vb-admin-bg-hover);
        }

        .reconnect-option-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 0.5rem;
        }

        .reconnect-option-header svg {
            width: 1.25rem;
            height: 1.25rem;
            flex-shrink: 0;
        }

        .reconnect-option-header strong {
            font-size: 1rem;
        }

        .reconnect-option-body {
            margin-left: 2rem;
        }

        .reconnect-option-desc {
            font-size: var(--vb-text-sm);
            color: var(--vb-admin-text-tertiary);
            margin-bottom: 0.75rem;
            line-height: 1.5;
        }

        .reconnect-option-danger {
            border-color: var(--vb-admin-border-subtle);
        }

        .reconnect-option-danger:hover {
            border-color: var(--vb-admin-error);
            background: var(--vb-admin-error-bg);
        }

        .reconnect-danger-text {
            color: var(--vb-admin-error);
        }

        .reconnect-confirm {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 0.75rem;
        }

        .reconnect-confirm input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: var(--vb-admin-error);
            cursor: pointer;
        }

        .reconnect-confirm label {
            font-size: var(--vb-text-sm);
            color: var(--vb-admin-text-secondary);
            cursor: pointer;
        }

        /* Button loading state */
        .btn.is-loading {
            pointer-events: none;
            position: relative;
        }

        .btn.is-loading .btn-text { opacity: 0; }

        .btn.is-loading::after {
            content: '';
            position: absolute;
            width: 18px;
            height: 18px;
            border: 2px solid rgba(255,255,255,0.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: btn-spin 0.6s linear infinite;
        }

        @keyframes btn-spin {
            to { transform: rotate(360deg); }
        }

        /* ── System Checks ── */
        .check-list { list-style: none; }

        .check-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.625rem 0;
            border-bottom: 1px solid var(--vb-admin-border-subtle);
            font-size: var(--vb-text-base);
            opacity: 0;
            animation: fadeIn 0.3s ease forwards;
        }

        .check-item:last-child { border-bottom: none; }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(4px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .check-icon { width: 20px; height: 20px; flex-shrink: 0; }
        .check-icon.pass { color: var(--vb-admin-success); }
        .check-icon.fail { color: var(--vb-admin-error); }

        .check-name { flex: 1; font-weight: 400; }
        .check-status { font-size: var(--vb-text-sm); color: var(--vb-admin-text-tertiary); }

        /* ── Flash Messages ── */
        .flash {
            display: flex;
            align-items: center;
            gap: 0.625rem;
            padding: 0.75rem 1rem;
            border-radius: var(--vb-radius-sm);
            margin-bottom: 1rem;
            font-size: var(--vb-text-sm);
            font-weight: 500;
        }

        .flash-success { background: var(--vb-admin-success-bg); color: var(--vb-admin-success); }
        .flash-error { background: var(--vb-admin-error-bg); color: var(--vb-admin-error); }
        .flash-info { background: var(--vb-admin-info-bg); color: var(--vb-admin-info); }
        .flash-warning { background: var(--vb-admin-warning-bg); color: var(--vb-admin-warning); }

        .flash-icon { width: 18px; height: 18px; flex-shrink: 0; }

        /* ── Connection Error ── */
        .connection-error {
            background: var(--vb-admin-error-bg);
            border: 1px solid var(--vb-admin-error);
            border-radius: var(--vb-radius-sm);
            padding: 0.75rem 1rem;
            margin-bottom: 1rem;
            color: var(--vb-admin-error);
            font-size: var(--vb-text-sm);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* ── Password Strength ── */
        .password-strength {
            height: 4px;
            border-radius: 2px;
            background: var(--vb-admin-border-subtle);
            margin-top: 0.5rem;
            overflow: hidden;
        }

        .password-strength-bar {
            height: 100%;
            border-radius: 2px;
            transition: width 0.3s ease, background-color 0.3s ease;
            width: 0;
        }

        /* ── Theme Toggle (PRD: sun/moon cross-fade 150ms) ── */
        .theme-toggle {
            position: fixed;
            top: 1rem;
            inset-inline-end: 1rem;
            background: var(--vb-admin-bg-surface);
            border: 1px solid var(--vb-admin-border-subtle);
            border-radius: 50%;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: var(--vb-admin-shadow-sm);
            transition: box-shadow 0.15s, border-color 0.15s;
            z-index: 10;
            padding: 0;
            color: var(--vb-admin-text-secondary);
        }

        .theme-toggle:hover {
            border-color: var(--vb-admin-border-medium);
            color: var(--vb-admin-text-primary);
        }

        .theme-toggle svg {
            width: 20px;
            height: 20px;
            position: absolute;
            transition: opacity 150ms ease, transform 150ms ease;
        }

        .theme-toggle .icon-sun { opacity: 0; transform: rotate(-90deg); }
        .theme-toggle .icon-moon { opacity: 1; transform: rotate(0deg); }

        [data-theme="dark"] .theme-toggle .icon-sun { opacity: 1; transform: rotate(0deg); }
        [data-theme="dark"] .theme-toggle .icon-moon { opacity: 0; transform: rotate(90deg); }

        /* ── Booking Pattern Cards (PRD §1362) ── */
        .pattern-cards {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
            margin-bottom: 1.25rem;
        }

        .pattern-card {
            border: 2px solid var(--vb-admin-border-subtle);
            border-radius: var(--vb-radius-sm);
            padding: 1rem;
            cursor: pointer;
            transition: border-color 0.15s, background-color 0.15s, transform 0.15s;
            text-align: center;
        }

        .pattern-card:hover {
            border-color: var(--vb-admin-border-medium);
            background: var(--vb-admin-bg-well);
            transform: translateY(-1px);
        }

        .pattern-card.selected {
            border-color: var(--vb-admin-accent);
            background: var(--vb-admin-accent-dim);
        }

        .pattern-card input { display: none; }

        .pattern-card-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 0.5rem;
            color: var(--vb-admin-accent);
        }

        .pattern-card-icon svg { width: 28px; height: 28px; }

        .pattern-card-name {
            font-weight: 600;
            font-size: var(--vb-text-sm);
            display: block;
            margin-bottom: 0.125rem;
        }

        .pattern-card-desc {
            font-size: 0.6875rem;
            color: var(--vb-admin-text-tertiary);
            display: block;
            line-height: 1.3;
        }

        /* ── Color Input ── */
        .color-input-group {
            display: flex;
            gap: 0.75rem;
            align-items: center;
        }

        .color-swatch {
            width: 40px;
            height: 40px;
            border-radius: var(--vb-radius-sm);
            border: 2px solid var(--vb-admin-border-subtle);
            cursor: pointer;
            padding: 0;
        }

        /* ── Completion Screen ── */
        .completion {
            text-align: center;
            padding: 2rem 1rem;
        }

        .completion-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 1.5rem;
            color: var(--vb-admin-success);
            opacity: 0;
            transform: scale(0.5);
            animation: completion-pop 0.6s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards;
        }

        @keyframes completion-pop {
            to { opacity: 1; transform: scale(1); }
        }

        .completion h2 {
            font-size: var(--vb-text-xl);
            font-weight: 700;
            letter-spacing: var(--vb-tracking-tight);
            margin-bottom: 0.5rem;
            opacity: 0;
            animation: fadeIn 0.4s ease 0.3s forwards;
        }

        .completion p {
            color: var(--vb-admin-text-secondary);
            margin-bottom: 1.5rem;
            font-size: var(--vb-text-md);
            opacity: 0;
            animation: fadeIn 0.4s ease 0.45s forwards;
        }

        .completion .booking-url {
            opacity: 0;
            animation: fadeIn 0.4s ease 0.55s forwards;
        }

        .completion .btn {
            opacity: 0;
            animation: fadeIn 0.4s ease 0.65s forwards;
        }

        .booking-url {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: var(--vb-admin-bg-well);
            padding: 0.75rem 1rem;
            border-radius: var(--vb-radius-sm);
            margin-bottom: 1.5rem;
            font-size: var(--vb-text-sm);
            word-break: break-all;
        }

        .booking-url a {
            color: var(--vb-admin-accent);
            text-decoration: none;
            flex: 1;
        }

        .booking-url a:hover { text-decoration: underline; }

        .copy-btn {
            background: none;
            border: none;
            cursor: pointer;
            color: var(--vb-admin-text-tertiary);
            padding: 0.25rem;
            display: flex;
            border-radius: 4px;
            transition: color 0.15s;
        }

        .copy-btn:hover { color: var(--vb-admin-text-primary); }
        .copy-btn.copied { color: var(--vb-admin-success); }
        .copy-btn svg { width: 16px; height: 16px; }

        /* ── Version Badge ── */
        .version-badge {
            font-size: var(--vb-text-sm);
            color: var(--vb-admin-text-tertiary);
            margin-top: 1rem;
        }

        /* ── Form Row ── */
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        @media (max-width: 480px) {
            .form-row { grid-template-columns: 1fr; }
            .pattern-cards { grid-template-columns: 1fr; }
        }

        .skip-link {
            display: block;
            text-align: center;
            margin-top: 1rem;
            color: var(--vb-admin-text-tertiary);
            font-size: var(--vb-text-sm);
            text-decoration: none;
            cursor: pointer;
            background: none;
            border: none;
            font-family: var(--vb-font-sans);
            width: 100%;
            padding: 0.5rem;
            border-radius: var(--vb-radius-sm);
            transition: color 0.15s, background-color 0.15s;
        }

        .skip-link:hover {
            color: var(--vb-admin-text-primary);
            background: var(--vb-admin-bg-well);
        }

        .actions { margin-top: 1.5rem; }

        .back-link {
            display: block;
            text-align: center;
            margin-top: 1rem;
            font-size: var(--vb-text-sm);
            color: var(--vb-admin-text-tertiary);
            text-decoration: none;
            transition: color 0.15s;
        }

        .back-link:hover {
            color: var(--vb-admin-text-primary);
        }

        .back-link svg {
            width: 14px;
            height: 14px;
            vertical-align: -2px;
            margin-right: 2px;
        }

        /* Back + Skip side-by-side row (Steps 3, 5) */
        .secondary-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 1rem;
        }

        .secondary-actions .back-link,
        .secondary-actions .skip-link {
            margin-top: 0;
        }

        .secondary-actions .skip-form {
            margin: 0;
        }

        .secondary-actions .skip-link {
            width: auto;
            padding: 0.25rem 0.5rem;
        }
    </style>
</head>
<body>
    <!-- Theme Toggle (PRD: sun/moon cross-fade 150ms, Lucide icons) -->
    <button class="theme-toggle" id="vb-theme-toggle" aria-label="<?= __('install.wizard.toggle_theme') ?>" title="<?= __('install.wizard.toggle_theme') ?>">
        <!-- Lucide Sun: viewBox 0 0 24 24, 1.5px stroke, round caps -->
        <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/>
        </svg>
        <!-- Lucide Moon -->
        <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>
        </svg>
    </button>

    <div class="wizard">
        <div class="wizard-header">
            <!-- Official VoxelBooking Hero Logo (Visual Design §2 — hero variant) -->
            <div class="vb-hero-logo">
                <svg class="vb-hero-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path class="voxel-top"   d="M12 3L20 7.5L12 12L4 7.5Z" />
                    <path class="voxel-left"  d="M4 7.5L12 12L12 21L4 16.5Z" />
                    <path class="voxel-right" d="M20 7.5L12 12L12 21L20 16.5Z" />
                </svg>
                <div class="vb-hero-text"><?= htmlspecialchars($_ENV['APP_NAME'] ?? 'VoxelBooking', ENT_QUOTES, 'UTF-8') ?></div>
            </div>

            <?php if ($step === 'complete'): ?>
                <p class="wizard-subtitle"><?= __('install.wizard.complete_page_title') ?></p>
            <?php else: ?>
                <p class="wizard-subtitle"><?= htmlspecialchars($stepTitles[$step] ?? '', ENT_QUOTES, 'UTF-8') ?> · <?= str_replace([':step', ':total'], [$displayStep, 5], __('install.wizard.step_of')) ?></p>
            <?php endif; ?>
        </div>

        <?php if ($step !== 'complete'): ?>
        <div class="steps" role="list" aria-label="<?= __('install.wizard.step_of', [':step' => '', ':total' => '']) ?>">
            <?php for ($i = 1; $i <= 5; $i++): ?>
                <div class="step-dot <?= $i === $displayStep ? 'active' : ($i < $displayStep ? 'done' : '') ?>"
                     role="listitem"
                     aria-label="<?= htmlspecialchars($stepTitles[$i] ?? '', ENT_QUOTES, 'UTF-8') ?><?= $i === $displayStep ? ' (' . __('install.wizard.step_current') . ')' : ($i < $displayStep ? ' (' . __('install.wizard.step_done') . ')' : '') ?>"></div>
                <?php if ($i < 5): ?>
                    <div class="step-track <?= $i < $displayStep ? 'done' : '' ?>" aria-hidden="true"></div>
                <?php endif; ?>
            <?php endfor; ?>
        </div>
        <?php endif; ?>

        <!-- Flash messages -->
        <?php foreach ($flash as $msg): ?>
            <div class="flash flash-<?= htmlspecialchars($msg['type'], ENT_QUOTES, 'UTF-8') ?>">
                <?php if ($msg['type'] === 'success'): ?>
                    <svg class="flash-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>
                <?php elseif ($msg['type'] === 'error'): ?>
                    <svg class="flash-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>
                <?php elseif ($msg['type'] === 'info'): ?>
                    <svg class="flash-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                <?php else: ?>
                    <svg class="flash-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                <?php endif; ?>
                <?= htmlspecialchars($msg['message'], ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endforeach; ?>

        <div class="card">

            <?php if ($step === 1): ?>
            <!-- ═══ Step 1: System Requirements ═══ -->
            <div class="card-header">
                <svg class="card-header-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/></svg>
                <div class="card-header-content">
                    <h2 class="card-title"><?= __('install.wizard.step1_title') ?></h2>
                    <p class="card-desc"><?= __('install.wizard.step1_desc') ?></p>
                </div>
            </div>

            <ul class="check-list">
                <?php foreach ($checks as $index => $check): ?>
                <li class="check-item" style="animation-delay: <?= $index * 150 ?>ms">
                    <!-- Lucide check-circle / x-circle -->
                    <svg class="check-icon <?= $check['passed'] ? 'pass' : 'fail' ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <?php if ($check['passed']): ?>
                            <circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>
                        <?php else: ?>
                            <circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>
                        <?php endif; ?>
                    </svg>
                    <span class="check-name"><?= htmlspecialchars($check['name'], ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="check-status"><?= htmlspecialchars($check['message'], ENT_QUOTES, 'UTF-8') ?></span>
                </li>
                <?php endforeach; ?>
            </ul>

            <div class="actions">
                <?php if ($allChecksPassed): ?>
                    <a href="/install?step=2" class="btn btn-primary btn-block"><?= __('install.wizard.continue') ?></a>
                <?php else: ?>
                    <button type="button" class="btn btn-primary btn-block" disabled><?= __('install.wizard.continue') ?></button>
                <?php endif; ?>
            </div>

            <?php elseif ($step === 2): ?>
            <!-- ═══ Step 2: Database Configuration ═══ -->
            <div class="card-header">
                <svg class="card-header-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5V19A9 3 0 0 0 21 19V5"/><path d="M3 12A9 3 0 0 0 21 12"/></svg>
                <div class="card-header-content">
                    <h2 class="card-title"><?= __('install.wizard.step2_title') ?></h2>
                    <p class="card-desc"><?= __('install.wizard.step2_desc') ?></p>
                </div>
            </div>

            <?php if (!empty($errors['db_connection'])): ?>
                <div class="connection-error">
                    <svg class="flash-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>
                    <?= htmlspecialchars($errors['db_connection'], ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($errors['db_migration'])): ?>
                <div class="connection-error">
                    <svg class="flash-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                    <?= htmlspecialchars($errors['db_migration'], ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <?php
                // Re-populate form values from session on failed submissions
                $dbForm = $session['form_db'] ?? [];
                $dbHost = htmlspecialchars($dbForm['db_host'] ?? 'localhost', ENT_QUOTES, 'UTF-8');
                $dbPort = htmlspecialchars($dbForm['db_port'] ?? '3306', ENT_QUOTES, 'UTF-8');
                $dbDatabase = htmlspecialchars($dbForm['db_database'] ?? 'voxelbooking', ENT_QUOTES, 'UTF-8');
                $dbUsername = htmlspecialchars($dbForm['db_username'] ?? '', ENT_QUOTES, 'UTF-8');
            ?>

            <form method="POST" action="/install/step/2" id="vb-form-db">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="db_host"><?= __('install.wizard.db_host') ?> <span class="form-required">*</span></label>
                        <input type="text" id="db_host" name="db_host" class="form-input <?= isset($errors['db_host']) ? 'error' : '' ?>" value="<?= $dbHost ?>" required>
                        <?php if (isset($errors['db_host'])): ?>
                            <div class="form-error"><?= htmlspecialchars($errors['db_host'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="db_port"><?= __('install.wizard.db_port') ?> <span class="form-required">*</span></label>
                        <input type="text" id="db_port" name="db_port" class="form-input <?= isset($errors['db_port']) ? 'error' : '' ?>" value="<?= $dbPort ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="db_database"><?= __('install.wizard.db_name') ?> <span class="form-required">*</span></label>
                    <input type="text" id="db_database" name="db_database" class="form-input <?= isset($errors['db_database']) ? 'error' : '' ?>" value="<?= $dbDatabase ?>" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="db_username"><?= __('install.wizard.db_username') ?> <span class="form-required">*</span></label>
                        <input type="text" id="db_username" name="db_username" class="form-input" value="<?= $dbUsername ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="db_password"><?= __('install.wizard.db_password') ?></label>
                        <input type="password" id="db_password" name="db_password" class="form-input" value="">
                        <div class="form-hint"><?= __('install.wizard.db_password_hint') ?></div>
                    </div>
                </div>

                <div class="actions">
                    <button type="submit" class="btn btn-primary btn-block" data-loading><span class="btn-text"><?= __('install.wizard.db_submit') ?></span></button>
                </div>
            </form>
            <a href="/install?step=1" class="back-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg><?= __('install.wizard.back') ?></a>

            <?php elseif ($step === 3): ?>
            <!-- ═══ Step 3: Email Configuration ═══ -->
            <div class="card-header">
                <svg class="card-header-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                <div class="card-header-content">
                    <h2 class="card-title"><?= __('install.wizard.step3_title') ?></h2>
                    <p class="card-desc"><?= __('install.wizard.step3_desc') ?></p>
                </div>
            </div>

            <form method="POST" action="/install/step/3" id="vb-form-email">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

                <?php
                    $selectedTransport = $mailTransport ?? 'smtp';
                    $mf = $mailForm ?? [];
                ?>

                <div class="form-group">
                    <label class="form-label" for="mail_transport"><?= __('install.wizard.mail_transport') ?></label>
                    <select id="mail_transport" name="mail_transport" class="form-input">
                        <option value="smtp" <?= $selectedTransport === 'smtp' ? 'selected' : '' ?>><?= __('install.wizard.mail_transport_smtp') ?></option>
                        <option value="resend" <?= $selectedTransport === 'resend' ? 'selected' : '' ?>><?= __('install.wizard.mail_transport_resend') ?></option>
                        <option value="mailpit" <?= $selectedTransport === 'mailpit' ? 'selected' : '' ?>><?= __('install.wizard.mail_transport_mailpit') ?></option>
                        <option value="log" <?= $selectedTransport === 'log' ? 'selected' : '' ?>><?= __('install.wizard.mail_transport_log') ?></option>
                    </select>
                </div>

                <div id="smtp-fields">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="mail_host"><?= __('install.wizard.mail_host') ?> <span class="form-required">*</span></label>
                            <input type="text" id="mail_host" name="mail_host" class="form-input <?= isset($errors['mail_host']) ? 'error' : '' ?>" placeholder="smtp.example.com" value="<?= htmlspecialchars($mf['mail_host'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                            <?php if (isset($errors['mail_host'])): ?>
                                <div class="form-error"><?= htmlspecialchars($errors['mail_host'], ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="mail_port"><?= __('install.wizard.mail_port') ?> <span class="form-required">*</span></label>
                            <input type="text" id="mail_port" name="mail_port" class="form-input <?= isset($errors['mail_port']) ? 'error' : '' ?>" value="<?= htmlspecialchars($mf['mail_port'] ?? '587', ENT_QUOTES, 'UTF-8') ?>">
                            <?php if (isset($errors['mail_port'])): ?>
                                <div class="form-error"><?= htmlspecialchars($errors['mail_port'], ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="mail_username"><?= __('install.wizard.mail_username') ?></label>
                            <input type="text" id="mail_username" name="mail_username" class="form-input" value="<?= htmlspecialchars($mf['mail_username'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="mail_password"><?= __('install.wizard.mail_password') ?></label>
                            <input type="password" id="mail_password" name="mail_password" class="form-input">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="mail_encryption"><?= __('install.wizard.mail_encryption') ?></label>
                        <select id="mail_encryption" name="mail_encryption" class="form-input">
                            <option value="tls" <?= ($mf['mail_encryption'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>TLS</option>
                            <option value="ssl" <?= ($mf['mail_encryption'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL</option>
                            <option value="none" <?= ($mf['mail_encryption'] ?? '') === 'none' ? 'selected' : '' ?>><?= __('install.wizard.mail_encryption_none') ?></option>
                        </select>
                    </div>
                </div>

                <!-- Resend fields (shown for transport=resend) -->
                <div id="resend-fields">
                    <div class="form-group">
                        <label class="form-label" for="resend_api_key"><?= __('install.wizard.resend_api_key') ?> <span class="form-required">*</span></label>
                        <input type="password" id="resend_api_key" name="mail_password" class="form-input <?= isset($errors['mail_password']) ? 'error' : '' ?>">
                        <?php if (isset($errors['mail_password'])): ?>
                            <div class="form-error"><?= htmlspecialchars($errors['mail_password'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                        <div class="form-hint"><?= __('install.wizard.resend_api_key_hint') ?></div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="mail_from_address"><?= __('install.wizard.mail_from_address') ?> <span class="form-required">*</span></label>
                        <input type="email" id="mail_from_address" name="mail_from_address" class="form-input <?= isset($errors['mail_from_address']) ? 'error' : '' ?>" placeholder="noreply@example.com" value="<?= htmlspecialchars($mf['mail_from_address'] ?? 'noreply@' . ($_SERVER['SERVER_NAME'] ?? $_SERVER['HTTP_HOST'] ?? 'example.com'), ENT_QUOTES, 'UTF-8') ?>">
                        <?php if (isset($errors['mail_from_address'])): ?>
                            <div class="form-error"><?= htmlspecialchars($errors['mail_from_address'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="mail_from_name"><?= __('install.wizard.mail_from_name') ?> <span class="form-required">*</span></label>
                        <input type="text" id="mail_from_name" name="mail_from_name" class="form-input <?= isset($errors['mail_from_name']) ? 'error' : '' ?>" value="<?= htmlspecialchars($mf['mail_from_name'] ?? __('install.wizard.mail_from_name_default'), ENT_QUOTES, 'UTF-8') ?>">
                        <?php if (isset($errors['mail_from_name'])): ?>
                            <div class="form-error"><?= htmlspecialchars($errors['mail_from_name'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="actions">
                    <button type="submit" class="btn btn-primary btn-block" data-loading><span class="btn-text"><?= __('install.wizard.mail_submit') ?></span></button>
                </div>
            </form>

            <div class="secondary-actions">
                <a href="/install?step=2" class="back-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg><?= __('install.wizard.back') ?></a>
                <form method="POST" action="/install/step/3" class="skip-form">
                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="skip" value="1">
                    <button type="submit" class="skip-link"><?= __('install.wizard.mail_skip') ?></button>
                </form>
            </div>

            <script>
            (function() {
                var sel = document.getElementById('mail_transport');
                var smtpFields = document.getElementById('smtp-fields');
                var resendFields = document.getElementById('resend-fields');
                function toggle() {
                    smtpFields.style.display = sel.value === 'smtp' ? '' : 'none';
                    resendFields.style.display = sel.value === 'resend' ? '' : 'none';
                }
                sel.addEventListener('change', toggle);
                toggle();
            })();
            </script>

            <?php elseif ($step === '2-reconnect'): ?>
            <!-- ═══ Step 2b: Reconnect Decision ═══ -->
            <div class="card-header">
                <!-- Lucide Database icon -->
                <svg class="card-header-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5V19A9 3 0 0 0 21 19V5"/><path d="M3 12A9 3 0 0 0 21 12"/></svg>
                <div class="card-header-content">
                    <h2 class="card-title"><?= __('install.wizard.reconnect_title') ?></h2>
                    <p class="card-desc"><?= str_replace(':version', htmlspecialchars($mysqlVersion ?? '', ENT_QUOTES, 'UTF-8'), __('install.wizard.reconnect_desc')) ?></p>
                </div>
            </div>

            <div class="reconnect-options">
                <!-- Option 1: Use existing data -->
                <form method="POST" action="/install/step/2" id="vb-form-reconnect-keep">
                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="_reconnect_action" value="keep">
                    <?php foreach ($dbCredentials ?? [] as $key => $val): ?>
                    <input type="hidden" name="<?= $key ?>" value="<?= htmlspecialchars($val, ENT_QUOTES, 'UTF-8') ?>">
                    <?php endforeach; ?>

                    <div class="reconnect-option">
                        <div class="reconnect-option-header">
                            <svg style="color: var(--vb-admin-accent);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                            <strong><?= __('install.wizard.reconnect_keep_title') ?></strong>
                        </div>
                        <div class="reconnect-option-body">
                            <p class="reconnect-option-desc"><?= __('install.wizard.reconnect_keep_desc') ?></p>
                            <button type="submit" class="btn btn-primary" style="width: auto; padding: 0.5rem 1.5rem;"><?= __('install.wizard.reconnect_keep_btn') ?></button>
                        </div>
                    </div>
                </form>

                <!-- Option 2: Fresh install -->
                <form method="POST" action="/install/step/2" id="vb-form-reconnect-refresh">
                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="_reconnect_action" value="refresh">
                    <?php foreach ($dbCredentials ?? [] as $key => $val): ?>
                    <input type="hidden" name="<?= $key ?>" value="<?= htmlspecialchars($val, ENT_QUOTES, 'UTF-8') ?>">
                    <?php endforeach; ?>

                    <div class="reconnect-option reconnect-option-danger">
                        <div class="reconnect-option-header">
                            <svg class="reconnect-danger-text" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/></svg>
                            <strong class="reconnect-danger-text"><?= __('install.wizard.reconnect_refresh_title') ?></strong>
                        </div>
                        <div class="reconnect-option-body">
                            <p class="reconnect-option-desc"><?= __('install.wizard.reconnect_refresh_desc') ?></p>
                            <div class="reconnect-confirm">
                                <input type="checkbox" id="confirm_refresh" name="confirm_refresh" value="1">
                                <label for="confirm_refresh"><?= __('install.wizard.reconnect_refresh_confirm_label') ?></label>
                            </div>
                            <div style="margin-top: 0.75rem;">
                                <button type="submit" class="btn" id="btn-refresh" disabled style="width: auto; padding: 0.5rem 1.5rem; background: var(--vb-admin-error); color: #fff; opacity: 0.5; cursor: not-allowed;"><?= __('install.wizard.reconnect_refresh_btn') ?></button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <a href="/install?step=2" class="back-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg><?= __('install.wizard.back') ?></a>

            <script>
            (function() {
                const checkbox = document.getElementById('confirm_refresh');
                const btn = document.getElementById('btn-refresh');
                if (!checkbox || !btn) return;
                checkbox.addEventListener('change', function() {
                    btn.disabled = !this.checked;
                    btn.style.opacity = this.checked ? '1' : '0.5';
                    btn.style.cursor = this.checked ? 'pointer' : 'not-allowed';
                });
            })();
            </script>

            <?php elseif ($step === 4): ?>
            <!-- ═══ Step 4: Operator Account ═══ -->
            <div class="card-header">
                <svg class="card-header-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" x2="19" y1="8" y2="14"/><line x1="22" x2="16" y1="11" y2="11"/></svg>
                <div class="card-header-content">
                    <h2 class="card-title"><?= __('install.wizard.step4_title') ?></h2>
                    <p class="card-desc"><?= __('install.wizard.step4_desc') ?></p>
                </div>
            </div>

            <form method="POST" action="/install/step/4" id="vb-form-account">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

                <!-- ── Identity ── -->
                <div class="form-group">
                    <label class="form-label" for="name"><?= __('install.wizard.op_name') ?> <span class="form-required">*</span></label>
                    <input type="text" id="name" name="name" class="form-input <?= isset($errors['name']) ? 'error' : '' ?>" value="<?= htmlspecialchars($session['op_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="<?= __('install.wizard.op_name_placeholder') ?>" required autocomplete="name">
                    <?php if (isset($errors['name'])): ?><div class="form-error"><?= htmlspecialchars($errors['name'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                </div>

                <div class="form-group">
                    <label class="form-label" for="email"><?= __('install.wizard.op_email') ?> <span class="form-required">*</span></label>
                    <input type="email" id="email" name="email" class="form-input <?= isset($errors['email']) ? 'error' : '' ?>" required autocomplete="email">
                    <?php if (isset($errors['email'])): ?><div class="form-error"><?= htmlspecialchars($errors['email'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                    <div class="form-hint"><?= __('install.wizard.op_email_hint') ?></div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="password"><?= __('install.wizard.op_password') ?> <span class="form-required">*</span></label>
                    <div class="password-field">
                        <input type="password" id="password" name="password" class="form-input <?= isset($errors['password']) ? 'error' : '' ?>" required minlength="8" autocomplete="new-password">
                        <button type="button" id="btn-toggle-password" class="btn-icon" title="<?= __('install.wizard.op_password_show') ?>">
                            <svg id="icon-eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg id="icon-eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display: none;"><path d="M10.733 5.076a10.744 10.744 0 0 1 11.205 6.575 1 1 0 0 1 0 .696 10.747 10.747 0 0 1-1.444 2.49"/><path d="M14.084 14.158a3 3 0 0 1-4.242-4.242"/><path d="M17.479 17.499a10.75 10.75 0 0 1-15.417-5.151 1 1 0 0 1 0-.696 10.75 10.75 0 0 1 4.446-5.143"/><path d="m2 2 20 20"/></svg>
                        </button>
                        <button type="button" id="btn-generate-password" class="btn-icon" title="<?= __('install.wizard.op_password_generate') ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/></svg>
                        </button>
                    </div>
                    <?php if (isset($errors['password'])): ?><div class="form-error"><?= htmlspecialchars($errors['password'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                    <div class="password-strength"><div class="password-strength-bar" id="strength-bar"></div></div>
                    <div class="form-hint"><?= __('install.wizard.op_password_hint') ?></div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="password_confirmation"><?= __('install.wizard.op_password_confirm') ?> <span class="form-required">*</span></label>
                    <input type="password" id="password_confirmation" name="password_confirmation" class="form-input <?= isset($errors['password_confirmation']) ? 'error' : '' ?>" required autocomplete="new-password">
                    <?php if (isset($errors['password_confirmation'])): ?><div class="form-error"><?= htmlspecialchars($errors['password_confirmation'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                </div>

                <!-- ── Application Settings ── -->
                <hr class="section-divider">
                <div class="card-header">
                    <!-- Lucide Settings icon -->
                    <svg class="card-header-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
                    <div class="card-header-content">
                        <h3 class="card-title"><?= __('install.wizard.regional_section_title') ?></h3>
                        <p class="card-desc"><?= __('install.wizard.regional_section_desc') ?></p>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="app_name"><?= __('install.wizard.app_name') ?></label>
                    <input type="text" id="app_name" name="app_name" class="form-input" value="<?= htmlspecialchars($_ENV['APP_NAME'] ?? 'VoxelBooking', ENT_QUOTES, 'UTF-8') ?>" placeholder="VoxelBooking">
                    <div class="form-hint"><?= __('install.wizard.app_name_hint') ?></div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="timezone"><?= __('install.wizard.timezone') ?></label>
                    <select id="timezone" name="timezone" class="form-input">
                        <?php
                        $browserTz = $session['timezone'] ?? 'UTC';
                        foreach (get_supported_timezones() as $tz):
                        ?>
                        <option value="<?= htmlspecialchars($tz, ENT_QUOTES, 'UTF-8') ?>" <?= $browserTz === $tz ? 'selected' : '' ?>><?= htmlspecialchars(format_timezone($tz), ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-hint"><?= __('install.wizard.timezone_hint') ?></div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="locale"><?= __('install.wizard.locale') ?></label>
                        <select id="locale" name="locale" class="form-input">
                            <?php
                            $selectedLocale = $session['locale'] ?? 'en';
                            foreach (\App\Engine\Locale::localeOptions() as $code => $label):
                            ?>
                            <option value="<?= $code ?>" <?= $selectedLocale === $code ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-hint"><?= __('install.wizard.locale_hint') ?></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="default_currency"><?= __('install.wizard.currency') ?></label>
                        <select id="default_currency" name="default_currency" class="form-input">
                            <?php
                            $selectedCurrency = $session['default_currency'] ?? 'EUR';
                            foreach (get_supported_currencies() as $code => $label):
                            ?>
                            <option value="<?= $code ?>" <?= $selectedCurrency === $code ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-hint"><?= __('install.wizard.currency_hint') ?></div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="date_format"><?= __('install.wizard.date_format') ?></label>
                        <select id="date_format" name="date_format" class="form-input">
                            <?php
                            $dateFormats = [
                                'Y-m-d'  => '2026-04-19',
                                'd/m/Y'  => '19/04/2026',
                                'm/d/Y'  => '04/19/2026',
                                'd-m-Y'  => '19-04-2026',
                                'd.m.Y'  => '19.04.2026',
                            ];
                            $selectedDateFormat = $session['date_format'] ?? 'Y-m-d';
                            foreach ($dateFormats as $fmt => $example):
                            ?>
                            <option value="<?= $fmt ?>" <?= $selectedDateFormat === $fmt ? 'selected' : '' ?>><?= $example ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="number_format"><?= __('install.wizard.number_format') ?></label>
                        <select id="number_format" name="number_format" class="form-input">
                            <?php
                            $numberFormats = \App\Engine\Locale::numberFormatPresets();
                            $selectedNumberFormat = $session['number_format'] ?? 'period';
                            foreach ($numberFormats as $key => $example):
                            ?>
                            <option value="<?= $key ?>" <?= $selectedNumberFormat === $key ? 'selected' : '' ?>><?= $example ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="time_format"><?= __('install.wizard.time_format') ?></label>
                        <select id="time_format" name="time_format" class="form-input">
                            <?php
                            $selectedTimeFormat = $session['time_format'] ?? '24h';
                            $timeFormats = [
                                '12h' => __('install.wizard.time_format_12h'),
                                '24h' => __('install.wizard.time_format_24h'),
                            ];
                            foreach ($timeFormats as $tv => $tl):
                            ?>
                            <option value="<?= $tv ?>" <?= $selectedTimeFormat === $tv ? 'selected' : '' ?>><?= htmlspecialchars($tl, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="week_start"><?= __('install.wizard.week_start') ?></label>
                        <select id="week_start" name="week_start" class="form-input">
                            <?php
                            $selectedWeekStart = $session['week_start'] ?? '1';
                            $weekDays = [
                                '1' => __('booking.days.1'),
                                '0' => __('booking.days.0'),
                                '6' => __('booking.days.6'),
                            ];
                            foreach ($weekDays as $wv => $wl):
                            ?>
                            <option value="<?= $wv ?>" <?= $selectedWeekStart === $wv ? 'selected' : '' ?>><?= htmlspecialchars($wl, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="actions" style="margin-top: 1.5rem;">
                    <button type="submit" class="btn btn-primary btn-block" data-loading><span class="btn-text"><?= __('install.wizard.op_submit') ?></span></button>
                </div>
            </form>
            <a href="/install?step=3" class="back-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg><?= __('install.wizard.back') ?></a>

            <?php elseif ($step === 5): ?>
            <!-- ═══ Step 5: First Tenant ═══ -->
            <div class="card-header">
                <svg class="card-header-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"/><path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/><path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"/><path d="M10 6h4"/><path d="M10 10h4"/><path d="M10 14h4"/><path d="M10 18h4"/></svg>
                <div class="card-header-content">
                    <h2 class="card-title"><?= __('install.wizard.step5_title') ?></h2>
                    <p class="card-desc"><?= __('install.wizard.step5_desc') ?></p>
                </div>
            </div>

            <form method="POST" action="/install/step/5" id="vb-form-tenant">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

                <div class="form-group">
                    <label class="form-label" for="tenant_name"><?= __('install.wizard.tenant_name') ?> <span class="form-required">*</span></label>
                    <input type="text" id="tenant_name" name="name" class="form-input <?= isset($errors['name']) ? 'error' : '' ?>" required placeholder="e.g. Salon Bella">
                    <?php if (isset($errors['name'])): ?><div class="form-error"><?= htmlspecialchars($errors['name'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                </div>

                <div class="form-group">
                    <label class="form-label"><?= __('install.wizard.tenant_pattern') ?></label>
                    <div class="pattern-cards">
                        <!-- Clock icon: Time Slots -->
                        <label class="pattern-card selected">
                            <input type="radio" name="booking_pattern" value="timeslot" checked>
                            <span class="pattern-card-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            </span>
                            <span class="pattern-card-name"><?= __('install.wizard.pattern_timeslot') ?></span>
                            <span class="pattern-card-desc"><?= __('install.wizard.pattern_timeslot_desc') ?></span>
                        </label>
                        <!-- Bed-double icon: Resources -->
                        <label class="pattern-card">
                            <input type="radio" name="booking_pattern" value="resource">
                            <span class="pattern-card-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M2 20v-8a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v8"/><path d="M4 10V6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v4"/><path d="M12 4v6"/><path d="M2 18h20"/></svg>
                            </span>
                            <span class="pattern-card-name"><?= __('install.wizard.pattern_resource') ?></span>
                            <span class="pattern-card-desc"><?= __('install.wizard.pattern_resource_desc') ?></span>
                        </label>
                        <!-- Utensils icon: Capacity -->
                        <label class="pattern-card">
                            <input type="radio" name="booking_pattern" value="capacity">
                            <span class="pattern-card-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 2v7c0 1.1.9 2 2 2h4a2 2 0 0 0 2-2V2"/><path d="M7 2v20"/><path d="M21 15V2a5 5 0 0 0-5 5v6c0 1.1.9 2 2 2h3Zm0 0v7"/></svg>
                            </span>
                            <span class="pattern-card-name"><?= __('install.wizard.pattern_capacity') ?></span>
                            <span class="pattern-card-desc"><?= __('install.wizard.pattern_capacity_desc') ?></span>
                        </label>
                        <!-- Ticket icon: Events -->
                        <label class="pattern-card">
                            <input type="radio" name="booking_pattern" value="event">
                            <span class="pattern-card-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/><path d="M13 5v2"/><path d="M13 17v2"/><path d="M13 11v2"/></svg>
                            </span>
                            <span class="pattern-card-name"><?= __('install.wizard.pattern_event') ?></span>
                            <span class="pattern-card-desc"><?= __('install.wizard.pattern_event_desc') ?></span>
                        </label>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="tenant_email"><?= __('install.wizard.tenant_email') ?> <span class="form-required">*</span></label>
                    <input type="email" id="tenant_email" name="email" class="form-input" required value="<?= htmlspecialchars($session['operator_email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div class="form-group">
                    <label class="form-label" for="brand_color"><?= __('install.wizard.tenant_brand_color') ?></label>
                    <div class="color-input-group">
                        <input type="color" id="brand_color_picker" class="color-swatch" value="#2563EB">
                        <input type="text" id="brand_color" name="brand_color" class="form-input" value="#2563EB" maxlength="7">
                    </div>
                </div>

                <div class="actions">
                    <button type="submit" class="btn btn-primary btn-block" data-loading><span class="btn-text"><?= __('install.wizard.tenant_submit') ?></span></button>
                </div>
            </form>

            <div class="secondary-actions">
                <a href="/install?step=4" class="back-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg><?= __('install.wizard.back') ?></a>
                <form method="POST" action="/install/step/5" class="skip-form">
                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="skip" value="1">
                    <button type="submit" class="skip-link"><?= __('install.wizard.tenant_skip') ?></button>
                </form>
            </div>

            <?php elseif ($step === 'complete'): ?>
            <!-- ═══ Completion ═══ -->
            <div class="completion">
                <!-- Lucide check-circle -->
                <svg class="completion-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>
                </svg>

                <h2><?= __('install.wizard.complete_title') ?></h2>
                <p><?= __('install.wizard.ready_message', ['app_name' => $_ENV['APP_NAME'] ?? 'VoxelBooking']) ?></p>

                <?php if (!empty($session['tenant_slug'])): ?>
                <div class="booking-url">
                    <?php $bookingUrl = app_url('/book/' . ($session['tenant_slug'] ?? '')); ?>
                    <a href="<?= htmlspecialchars($bookingUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank">
                        <?= htmlspecialchars($bookingUrl, ENT_QUOTES, 'UTF-8') ?>
                    </a>
                    <button class="copy-btn" id="vb-copy-url" title="<?= __('install.wizard.copy_url') ?>">
                        <!-- Lucide copy -->
                        <svg class="icon-copy" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
                        <!-- Lucide check (shown after copy) -->
                        <svg class="icon-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="display:none"><path d="M20 6 9 17l-5-5"/></svg>
                    </button>
                </div>
                <?php endif; ?>

                <a href="/admin" class="btn btn-primary btn-block"><?= __('install.wizard.go_to_dashboard') ?></a>

                <div class="version-badge"><?= __('install.wizard.version_label', ['version' => \App\Engine\Version::get()]) ?></div>
            </div>
            <?php endif; ?>

        </div>
    </div>

    <script>
    (function() {
        'use strict';

        // ── Theme Resolution (PRD §2236) ──
        // 1. localStorage `vb-theme`
        // 2. prefers-color-scheme
        // 3. fallback: light

        var STORAGE_KEY = 'vb-theme';

        function getResolvedTheme() {
            var stored = localStorage.getItem(STORAGE_KEY);
            if (stored === 'light' || stored === 'dark') return stored;
            if (window.matchMedia('(prefers-color-scheme: dark)').matches) return 'dark';
            return 'light';
        }

        function applyTheme(theme) {
            document.documentElement.setAttribute('data-theme', theme);
        }

        // Apply immediately (before paint)
        applyTheme(getResolvedTheme());

        // OS-level theme change listener
        var mq = window.matchMedia('(prefers-color-scheme: dark)');
        mq.addEventListener('change', function(e) {
            if (!localStorage.getItem(STORAGE_KEY)) {
                applyTheme(e.matches ? 'dark' : 'light');
            }
        });

        // Theme toggle button
        var toggleBtn = document.getElementById('vb-theme-toggle');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', function() {
                var current = document.documentElement.getAttribute('data-theme');
                var next = current === 'dark' ? 'light' : 'dark';
                document.documentElement.setAttribute('data-theme', next);
                localStorage.setItem(STORAGE_KEY, next);
            });
        }

        // ── Password Strength (PRD: 4px bar, animated width, red→amber→green) ──
        var passwordInput = document.getElementById('password');
        if (passwordInput) {
            passwordInput.addEventListener('input', function() {
                var bar = document.getElementById('strength-bar');
                if (!bar) return;
                var password = this.value;
                var score = 0;
                if (password.length >= 8) score++;
                if (password.length >= 12) score++;
                if (/[A-Z]/.test(password)) score++;
                if (/[0-9]/.test(password)) score++;
                if (/[^A-Za-z0-9]/.test(password)) score++;

                var width = Math.min(100, score * 20);
                var colors = ['#DC2626', '#D97706', '#D97706', '#059669', '#059669'];
                bar.style.width = width + '%';
                bar.style.backgroundColor = colors[Math.min(score, colors.length) - 1] || '#DC2626';
            });
        }

        // ── Password Toggle (show/hide) ──
        var toggleBtn = document.getElementById('btn-toggle-password');
        if (toggleBtn && passwordInput) {
            toggleBtn.addEventListener('click', function() {
                var isPassword = passwordInput.type === 'password';
                passwordInput.type = isPassword ? 'text' : 'password';
                document.getElementById('icon-eye-open').style.display = isPassword ? 'none' : 'block';
                document.getElementById('icon-eye-closed').style.display = isPassword ? 'block' : 'none';
                // Also toggle confirmation field
                var confirmField = document.getElementById('password_confirmation');
                if (confirmField) confirmField.type = passwordInput.type;
            });
        }

        // ── Password Generate (random 16-char) ──
        var generateBtn = document.getElementById('btn-generate-password');
        if (generateBtn && passwordInput) {
            generateBtn.addEventListener('click', function() {
                var chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%&*';
                var arr = new Uint32Array(16);
                crypto.getRandomValues(arr);
                var password = '';
                for (var i = 0; i < 16; i++) {
                    password += chars[arr[i] % chars.length];
                }
                passwordInput.value = password;
                passwordInput.type = 'text';
                document.getElementById('icon-eye-open').style.display = 'none';
                document.getElementById('icon-eye-closed').style.display = 'block';
                var confirmField = document.getElementById('password_confirmation');
                if (confirmField) {
                    confirmField.value = password;
                    confirmField.type = 'text';
                }
                // Trigger strength bar update
                passwordInput.dispatchEvent(new Event('input'));
            });
        }

        // ── Timezone Auto-detect (Step 4) ──
        var tzSelect = document.getElementById('timezone');
        if (tzSelect && !tzSelect.dataset.userSet) {
            try {
                var browserTz = Intl.DateTimeFormat().resolvedOptions().timeZone;
                if (browserTz) {
                    for (var i = 0; i < tzSelect.options.length; i++) {
                        if (tzSelect.options[i].value === browserTz) {
                            tzSelect.value = browserTz;
                            break;
                        }
                    }
                }
            } catch(e) { /* Intl not available — keep server default */ }
        }

        // ── Pattern Card Selection ──
        document.querySelectorAll('.pattern-card').forEach(function(card) {
            card.addEventListener('click', function() {
                document.querySelectorAll('.pattern-card').forEach(function(c) { c.classList.remove('selected'); });
                this.classList.add('selected');
            });
        });

        // ── Color Picker Sync (real-time on input, not just change) ──
        var colorPicker = document.getElementById('brand_color_picker');
        var colorText = document.getElementById('brand_color');
        if (colorPicker && colorText) {
            colorPicker.addEventListener('input', function() { colorText.value = this.value; });
            colorPicker.addEventListener('change', function() { colorText.value = this.value; });
            colorText.addEventListener('input', function() {
                if (/^#[0-9A-Fa-f]{6}$/.test(this.value)) {
                    colorPicker.value = this.value;
                }
            });
        }

        // ── Copy URL with feedback ──
        var copyBtn = document.getElementById('vb-copy-url');
        if (copyBtn) {
            copyBtn.addEventListener('click', function() {
                var link = document.querySelector('.booking-url a');
                if (!link) return;
                navigator.clipboard.writeText(link.href).then(function() {
                    copyBtn.classList.add('copied');
                    var iconCopy = copyBtn.querySelector('.icon-copy');
                    var iconCheck = copyBtn.querySelector('.icon-check');
                    if (iconCopy) iconCopy.style.display = 'none';
                    if (iconCheck) iconCheck.style.display = 'block';
                    setTimeout(function() {
                        copyBtn.classList.remove('copied');
                        if (iconCopy) iconCopy.style.display = 'block';
                        if (iconCheck) iconCheck.style.display = 'none';
                    }, 2000);
                });
            });
        }

        // ── Form submit loading state ──
        document.querySelectorAll('button[data-loading]').forEach(function(btn) {
            btn.closest('form').addEventListener('submit', function() {
                btn.classList.add('is-loading');
                btn.disabled = true;
            });
        });
    })();
    </script>
</body>
</html>
