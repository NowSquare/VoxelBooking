<?php
/**
 * Edit Tenant form — premium design with working color picker.
 *
 * Uses wizard-grade components:
 * - vb-color-field: swatch + text input with bidirectional sync
 * - vb-select:      premium styled selects for timezone and currency
 *
 * Alpine component: colorSync (registered in admin/app.js)
 *
 * Variables: $user, $version, $csrfToken, $tenant, $flash, $pageTitle, $activePage
 *
 * Old input and field errors are read from session via helpers:
 *   old(), has_error(), field_error(), error_class()
 */
$activePage = 'tenants';

$timezones = get_supported_timezones();
$currencies = get_supported_currencies();

// Old input takes priority over tenant data (preserves unsaved changes)
$tenantTimezone = old('timezone', $tenant['timezone'] ?? 'UTC');
$tenantCurrency = old('currency', $tenant['currency'] ?? 'EUR');
$tenantColor = old('brand_color', $tenant['brand_color'] ?? '#2563EB');


ob_start();
?>

<?php
$pageHeader = [
    'title' => __('admin.tenants.edit'),
    'backUrl' => '/admin/tenants',
    'backLabel' => __('admin.tenants.title'),
];
include __DIR__ . '/../partials/page-header.php';
?>

<?php if ($flash): ?>
    <?php include __DIR__ . '/../../partials/alert.php'; ?>
<?php endif; ?>

<div class="vb-grid vb-grid-2 vb-fade-in-up">
    <!-- Edit Form -->
    <div class="vb-card" x-data="slugGenerator">
        <form method="POST" action="/admin/tenants/<?= htmlspecialchars($tenant['id'], ENT_QUOTES, 'UTF-8') ?>/edit" novalidate>
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="_record_version" value="<?= e($recordVersion ?? '') ?>">

            <div class="vb-form-group">
                <label for="tenant_name" class="vb-label"><?= __('admin.tenants.name') ?> <span class="vb-required">*</span></label>
                <input type="text" id="tenant_name" name="name" class="vb-input<?= error_class('name') ?>" required
                       value="<?= e(old('name', $tenant['name'] ?? '')) ?>">
                <?php if (has_error('name')): ?>
                    <div class="vb-form-error" role="alert"><?= e(field_error('name')) ?></div>
                <?php endif; ?>
            </div>

            <div class="vb-form-group">
                <label for="tenant_slug" class="vb-label"><?= __('admin.tenants.slug') ?></label>
                <input type="text" id="tenant_slug" name="slug" class="vb-input<?= error_class('slug') ?>"
                       x-ref="slugInput" x-model="slug"
                       @input="onSlugInput" @blur="onSlugBlur"
                       value="<?= e(old('slug', $tenant['slug'] ?? '')) ?>">
                <?php if (has_error('slug')): ?>
                    <div class="vb-form-error" role="alert"><?= e(field_error('slug')) ?></div>
                <?php else: ?>
                    <span class="vb-hint"><?= __('admin.tenants.slug_help') ?></span>
                <?php endif; ?>
            </div>

            <div class="vb-form-group">
                <label for="tenant_email" class="vb-label"><?= __('admin.tenants.email') ?> <span class="vb-required">*</span></label>
                <input type="email" id="tenant_email" name="email" class="vb-input<?= error_class('email') ?>" required
                       value="<?= e(old('email', $tenant['email'] ?? '')) ?>">
                <?php if (has_error('email')): ?>
                    <div class="vb-form-error" role="alert"><?= e(field_error('email')) ?></div>
                <?php endif; ?>
            </div>

            <div class="vb-form-row">
                <div class="vb-form-group">
                    <label for="tenant_timezone" class="vb-label vb-icon-label">
                        <i data-lucide="globe"></i>
                        <?= __('admin.tenants.timezone') ?>
                    </label>
                    <select id="tenant_timezone" name="timezone" class="vb-select">
                        <?php foreach ($timezones as $tz): ?>
                            <option value="<?= htmlspecialchars($tz, ENT_QUOTES, 'UTF-8') ?>" <?= $tz === $tenantTimezone ? 'selected' : '' ?>>
                                <?= htmlspecialchars(format_timezone($tz), ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="vb-form-group">
                    <label for="tenant_currency" class="vb-label">
                        <?= __('admin.tenants.currency') ?>
                    </label>
                    <select id="tenant_currency" name="currency" class="vb-select">
                        <?php foreach ($currencies as $code => $label): ?>
                            <option value="<?= $code ?>" <?= $code === $tenantCurrency ? 'selected' : '' ?>>
                                <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="vb-form-group" x-data="colorSync">
                <label class="vb-label vb-icon-label">
                    <i data-lucide="palette"></i>
                    <?= __('admin.tenants.brand_color') ?>
                </label>
                <div class="vb-color-field">
                    <input type="color" x-ref="colorPicker"
                           value="<?= htmlspecialchars($tenantColor, ENT_QUOTES, 'UTF-8') ?>"
                           class="vb-color-input" @input="onPickerChange">
                    <input type="text" x-ref="colorText" name="brand_color"
                           value="<?= htmlspecialchars($tenantColor, ENT_QUOTES, 'UTF-8') ?>"
                           class="vb-input" maxlength="7" @input="onTextChange">
                </div>
            </div>

            <div class="vb-form-actions">
                <button type="submit" class="vb-btn vb-btn-primary">
                    <i data-lucide="check"></i>
                    <?= __('admin.settings.save_button') ?>
                </button>
                <a href="/admin/tenants" class="vb-btn vb-btn-ghost">
                    <?= __('admin.common.cancel') ?>
                </a>
            </div>
        </form>
    </div>

    <!-- Tenant Info -->
    <div class="vb-card">
        <div class="vb-card-header">
            <div class="vb-card-title-row">
                <i data-lucide="activity" class="vb-card-icon"></i>
                <div>
                    <div class="vb-card-title"><?= __('admin.tenants.status') ?></div>
                    <div class="vb-card-desc"><?= __('admin.tenants.status_desc') ?></div>
                </div>
            </div>
        </div>
        <div class="vb-info-row">
            <span class="vb-info-label"><?= __('admin.tenants.status') ?></span>
            <span class="vb-info-value">
                <?php
                $statusClass = match ($tenant['status'] ?? 'active') {
                    'active'   => 'vb-badge-success',
                    'paused'   => 'vb-badge-warning',
                    'archived' => 'vb-badge-default',
                    default    => 'vb-badge-default',
                };
                ?>
                <span class="vb-badge <?= $statusClass ?>"><?= __('admin.tenants.status_' . ($tenant['status'] ?? 'active')) ?></span>
            </span>
        </div>
        <div class="vb-info-row">
            <span class="vb-info-label"><?= __('admin.tenants.pattern') ?></span>
            <span class="vb-info-value">
                <span class="vb-badge vb-badge-default"><?= htmlspecialchars(ucfirst($tenant['booking_pattern'] ?? 'timeslot'), ENT_QUOTES, 'UTF-8') ?></span>
            </span>
        </div>
        <div class="vb-info-row">
            <span class="vb-info-label"><?= __('admin.tenants.created') ?></span>
            <span class="vb-info-value"><?= htmlspecialchars($tenant['created_at'] ?? '—', ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    </div>

    <!-- Public Booking URL -->
    <?php $bookingUrl = (isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/book/' . ($tenant['slug'] ?? ''); ?>
    <div class="vb-public-url-card vb-fade-in-up">
        <div class="vb-public-url-label"><?= __('admin.tenants.view_booking_page') ?></div>
        <div class="vb-public-url-control">
            <code class="vb-public-url-text"><?= htmlspecialchars($bookingUrl, ENT_QUOTES, 'UTF-8') ?></code>
            <div class="vb-public-url-actions">
                <a href="/book/<?= htmlspecialchars($tenant['slug'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                   target="_blank" rel="noopener"
                   class="vb-public-url-btn" title="<?= __('admin.tenants.view_booking_page') ?>">
                    <i data-lucide="external-link"></i>
                </a>
                <button type="button"
                        class="vb-public-url-btn"
                        data-copy-url="<?= htmlspecialchars($bookingUrl, ENT_QUOTES, 'UTF-8') ?>"
                        @click="copyBookingUrl"
                        title="<?= __('admin.common.copy_booking_url') ?>">
                    <span class="vb-copy-icon"><i data-lucide="copy"></i></span>
                    <span class="vb-copy-check"><i data-lucide="check"></i></span>
                </button>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include dirname(__DIR__, 2) . '/admin/layout.php';
