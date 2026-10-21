<?php
/**
 * Create Tenant form — structured with card sections.
 *
 * Layout:
 *   Section 1: Business Details (name, slug, email, brand color)
 *   Section 2: Booking Pattern (4-card radio grid)
 *   Section 3: Regional (timezone, currency)
 *   Section 4: Owner Access (optional, collapsible)
 *   Actions: Create + Cancel
 *
 * Alpine components: patternCards, colorSync, ownerSetup, formSubmit
 * (all registered in admin/app.js)
 *
 * Variables: $user, $version, $csrfToken, $flash, $pageTitle,
 *            $activePage, $documentTitle, $defaultTimezone, $defaultCurrency
 *
 * Old input and field errors are read from session via helpers:
 *   old(), has_error(), field_error(), error_class()
 */
$activePage = 'tenants';

$timezones = get_supported_timezones();
$currencies = get_supported_currencies();
$defaultTimezone = $defaultTimezone ?? 'UTC';

$patterns = [
    'timeslot' => [
        'icon'  => 'clock',
        'name'  => __('admin.tenants.pattern_timeslot'),
        'desc'  => __('admin.tenants.pattern_timeslot_desc'),
    ],
    'resource' => [
        'icon'  => 'users',
        'name'  => __('admin.tenants.pattern_resource'),
        'desc'  => __('admin.tenants.pattern_resource_desc'),
    ],
    'capacity' => [
        'icon'  => 'layers',
        'name'  => __('admin.tenants.pattern_capacity'),
        'desc'  => __('admin.tenants.pattern_capacity_desc'),
    ],
    'event' => [
        'icon'  => 'calendar-days',
        'name'  => __('admin.tenants.pattern_event'),
        'desc'  => __('admin.tenants.pattern_event_desc'),
    ],
];

ob_start();
?>

<?php
$pageHeader = [
    'title' => __('admin.tenants.create'),
    'backUrl' => '/admin/tenants',
    'backLabel' => __('admin.tenants.title'),
];
include __DIR__ . '/../partials/page-header.php';
?>

<?php if ($flash): ?>
    <?php include __DIR__ . '/../../partials/alert.php'; ?>
<?php endif; ?>

<form method="POST" action="/admin/tenants/create" class="vb-animate-in" novalidate x-data="formSubmit" @submit="onSubmit">
    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

    <!-- ── Section 1: Business Details ── -->
    <div class="vb-settings-section">
        <div class="vb-card" x-data="slugGenerator">
            <div class="vb-card-header">
                <div class="vb-card-title-row">
                    <i data-lucide="building-2" class="vb-card-icon"></i>
                    <div>
                        <div class="vb-card-title"><?= __('admin.tenants.section_identity') ?></div>
                        <div class="vb-card-desc"><?= __('admin.tenants.section_identity_desc') ?></div>
                    </div>
                </div>
            </div>

            <div class="vb-form-group">
                <label for="tenant_name" class="vb-label"><?= __('admin.tenants.name') ?> <span class="vb-required">*</span></label>
                <input type="text" id="tenant_name" name="name" class="vb-input<?= error_class('name') ?>" required
                       value="<?= e(old('name')) ?>"
                       placeholder="Acme Hair Studio" autofocus
                       @input="onNameInput">
                <?php if (has_error('name')): ?>
                    <div class="vb-form-error" role="alert"><?= e(field_error('name')) ?></div>
                <?php endif; ?>
            </div>

            <div class="vb-form-group">
                <label for="tenant_slug" class="vb-label"><?= __('admin.tenants.slug') ?></label>
                <input type="text" id="tenant_slug" name="slug" class="vb-input<?= error_class('slug') ?>"
                       x-ref="slugInput" x-model="slug"
                       @input="onSlugInput" @blur="onSlugBlur"
                       placeholder="acme-hair-studio">
                <?php if (has_error('slug')): ?>
                    <div class="vb-form-error" role="alert"><?= e(field_error('slug')) ?></div>
                <?php else: ?>
                    <span class="vb-hint"><?= __('admin.tenants.slug_help') ?></span>
                <?php endif; ?>
            </div>

            <div class="vb-form-group">
                <label for="tenant_email" class="vb-label"><?= __('admin.tenants.email') ?> <span class="vb-required">*</span></label>
                <input type="email" id="tenant_email" name="email" class="vb-input<?= error_class('email') ?>" required
                       value="<?= e(old('email')) ?>"
                       placeholder="hello@example.com">
                <?php if (has_error('email')): ?>
                    <div class="vb-form-error" role="alert"><?= e(field_error('email')) ?></div>
                <?php endif; ?>
            </div>

            <!-- Brand Color -->
            <div class="vb-form-group" x-data="colorSync">
                <label class="vb-label vb-icon-label">
                    <i data-lucide="palette"></i>
                    <?= __('admin.tenants.brand_color') ?>
                </label>
                <div class="vb-color-field">
                    <input type="color" x-ref="colorPicker" value="<?= e(old('brand_color', '#2563EB')) ?>" class="vb-color-input"
                           @input="onPickerChange">
                    <input type="text" x-ref="colorText" name="brand_color" value="<?= e(old('brand_color', '#2563EB')) ?>" class="vb-input"
                           maxlength="7" placeholder="#2563EB" @input="onTextChange">
                </div>
            </div>
        </div>
    </div>

    <!-- ── Section 2: Booking Pattern ── -->
    <?php $selectedPattern = old('booking_pattern', 'timeslot'); ?>
    <div class="vb-settings-section">
        <div class="vb-card">
            <div class="vb-card-header">
                <div class="vb-card-title-row">
                    <i data-lucide="layout-grid" class="vb-card-icon"></i>
                    <div>
                        <div class="vb-card-title"><?= __('admin.tenants.section_pattern') ?></div>
                        <div class="vb-card-desc"><?= __('admin.tenants.section_pattern_desc') ?></div>
                    </div>
                </div>
            </div>

            <div class="vb-form-group" x-data="patternCards">
                <div class="vb-pattern-cards">
                    <?php foreach ($patterns as $value => $info): ?>
                    <label class="vb-pattern-card" @click="select('<?= $value ?>')">
                        <input type="radio" name="booking_pattern" value="<?= $value ?>"
                               <?= $value === $selectedPattern ? 'checked' : '' ?>>
                        <span class="vb-pattern-card-icon">
                            <i data-lucide="<?= $info['icon'] ?>"></i>
                        </span>
                        <span class="vb-pattern-card-name"><?= $info['name'] ?></span>
                        <span class="vb-pattern-card-desc"><?= $info['desc'] ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Section 3: Regional ── -->
    <?php $selectedTz = old('timezone', $defaultTimezone); ?>
    <?php $selectedCurrency = old('currency', $defaultCurrency ?? 'EUR'); ?>
    <div class="vb-settings-section">
        <div class="vb-card">
            <div class="vb-card-header">
                <div class="vb-card-title-row">
                    <i data-lucide="globe" class="vb-card-icon"></i>
                    <div>
                        <div class="vb-card-title"><?= __('admin.tenants.section_regional') ?></div>
                        <div class="vb-card-desc"><?= __('admin.tenants.section_regional_desc') ?></div>
                    </div>
                </div>
            </div>

            <div class="vb-form-grid vb-form-grid-2">
                <div class="vb-form-group">
                    <label for="tenant_timezone" class="vb-label"><?= __('admin.tenants.timezone') ?></label>
                    <select id="tenant_timezone" name="timezone" class="vb-select">
                        <?php foreach ($timezones as $tz): ?>
                            <option value="<?= htmlspecialchars($tz, ENT_QUOTES, 'UTF-8') ?>" <?= $tz === $selectedTz ? 'selected' : '' ?>>
                                <?= htmlspecialchars(format_timezone($tz), ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="vb-form-group">
                    <label for="tenant_currency" class="vb-label"><?= __('admin.tenants.currency') ?></label>
                    <select id="tenant_currency" name="currency" class="vb-select">
                        <?php foreach ($currencies as $code => $label): ?>
                            <option value="<?= $code ?>" <?= $code === $selectedCurrency ? 'selected' : '' ?>>
                                <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Section 4: Owner Access (Optional) ── -->
    <?php $ownerEnabled = (old('create_owner', '0')) === '1'; ?>
    <div class="vb-settings-section">
        <div class="vb-card">
            <div class="vb-owner-section" x-data="ownerSetup" data-smtp-configured="<?= \App\Engine\Mailer::isConfigured() ? '1' : '0' ?>"
                 <?php if ($ownerEnabled): ?>data-initially-enabled="1"<?php endif; ?>>

                <div class="vb-form-group">
                    <div class="vb-toggle-row">
                        <input type="checkbox" id="create_owner_toggle"
                               :checked="enabled" @change="onToggleEnabled" class="vb-checkbox">
                        <input type="hidden" name="create_owner" :value="ownerFormValue">
                        <label for="create_owner_toggle" class="vb-toggle-label">
                            <span class="vb-label vb-mb-0">
                                <i data-lucide="user-plus" class="vb-icon-sm"></i>
                                <?= __('admin.tenants.owner_section_title') ?>
                            </span>
                            <span class="vb-hint"><?= __('admin.tenants.owner_section_desc') ?></span>
                        </label>
                    </div>
                </div>

                <div x-show="enabled" x-transition.duration.200ms style="display: none;">
                    <div class="vb-form-group">
                        <label for="owner_name" class="vb-label"><?= __('admin.tenants.owner_name') ?> <span class="vb-required">*</span></label>
                        <input type="text" id="owner_name" name="owner_name" class="vb-input<?= error_class('owner_name') ?>"
                               value="<?= e(old('owner_name')) ?>"
                               placeholder="Jane Doe" :required="enabled">
                        <?php if (has_error('owner_name')): ?>
                            <div class="vb-form-error" role="alert"><?= e(field_error('owner_name')) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="vb-form-group">
                        <label for="owner_email" class="vb-label"><?= __('admin.tenants.owner_email') ?> <span class="vb-required">*</span></label>
                        <input type="email" id="owner_email" name="owner_email" class="vb-input<?= error_class('owner_email') ?>"
                               x-ref="ownerEmail"
                               value="<?= e(old('owner_email')) ?>"
                               placeholder="owner@example.com" :required="enabled">
                        <?php if (has_error('owner_email')): ?>
                            <div class="vb-form-error" role="alert"><?= e(field_error('owner_email')) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="vb-form-group">
                        <label for="owner_password" class="vb-label"><?= __('admin.tenants.owner_password') ?></label>
                        <div class="vb-password-field">
                            <input type="password" id="owner_password" name="owner_password" class="vb-input"
                                   x-ref="ownerPassword" autocomplete="new-password">
                            <button type="button" class="vb-btn vb-btn-ghost vb-btn-sm" @click="togglePasswordVisibility"
                                    title="<?= __('admin.tenants.owner_toggle_visibility') ?>">
                                <i data-lucide="eye" x-show="!showPassword"></i>
                                <i data-lucide="eye-off" x-show="showPassword"></i>
                            </button>
                            <button type="button" class="vb-btn vb-btn-ghost vb-btn-sm" @click="generatePassword()"
                                    title="<?= __('admin.tenants.owner_generate_password') ?>">
                                <i data-lucide="refresh-cw"></i>
                            </button>
                        </div>
                    </div>

                    <div class="vb-form-group">
                        <div class="vb-toggle-row">
                            <input type="checkbox" id="send_owner_email_toggle" name="send_owner_email" value="1"
                                   :checked="sendEmail"
                                   @change="onToggleSendEmail"
                                   :disabled="smtpNotConfigured"
                                   class="vb-checkbox">
                            <label for="send_owner_email_toggle" class="vb-toggle-label">
                                <span class="vb-label vb-mb-0">
                                    <i data-lucide="mail" class="vb-icon-sm"></i>
                                    <?= __('agent-business.send_invitation') ?>
                                </span>
                                <?php if (!\App\Engine\Mailer::isConfigured()): ?>
                                    <span class="vb-hint vb-hint-warning">
                                        <i data-lucide="alert-triangle" class="vb-icon-xs"></i>
                                        <?= __('admin.tenants.owner_smtp_hint') ?>
                                    </span>
                                <?php endif; ?>
                            </label>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="vb-form-actions">
        <button type="submit" class="vb-btn vb-btn-primary" :disabled="submitDisabled" :class="submitClass">
            <i data-lucide="plus"></i>
            <?= __('admin.tenants.create') ?>
        </button>
        <a href="/admin/tenants" class="vb-btn vb-btn-ghost">
            <?= __('admin.common.cancel') ?>
        </a>
    </div>
</form>

<?php
$content = ob_get_clean();
include dirname(__DIR__, 2) . '/admin/layout.php';
