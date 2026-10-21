<?php
/**
 * Manual capacity booking creation form — tenant-scoped (capacity pattern).
 *
 * Variables: $tenant, $tenantId, $slots, $csrfToken, $flash, $old
 */
$tenant   = $tenant ?? [];
$tenantId = $tenantId ?? '';
$slots    = $slots ?? [];
$baseUrl  = "/admin/tenants/" . htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8');

$dayNames = [];
for ($i = 0; $i < 7; $i++) {
    $dayNames[$i] = __('admin.capacity_slots.day_' . $i);
}

$activePage  = 'bookings';
ob_start();
?>

<?php if ($flash ?? null): ?>
    <?php include __DIR__ . '/../../../partials/alert.php'; ?>
<?php endif; ?>

<?php
$pageHeader = [
    'title' => __('admin.bookings.create_title'),
    'subtitle' => __('admin.bookings.create_subtitle'),
    'backUrl' => '/admin/tenants/' . $tenantId . '/bookings',
    'backLabel' => __('admin.bookings.title'),
];
include __DIR__ . '/../../partials/page-header.php';
?>

<form method="POST"
      action="<?= $baseUrl ?>/bookings/create"
      class="vb-animate-in" novalidate>
    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

    <!-- Section 1: Reservation Details -->
    <div class="vb-settings-section">
        <div class="vb-card">
            <div class="vb-card-header">
                <div class="vb-card-title-row">
                    <i data-lucide="calendar" class="vb-card-icon"></i>
                    <div class="vb-card-title"><?= __('admin.bookings.date_time') ?></div>
                </div>
            </div>

            <div class="vb-form-grid">
                <div class="vb-form-row">
                    <div class="vb-form-group">
                        <label for="create_slot_id" class="vb-label"><?= __('admin.bookings.label_slot') ?> <span class="vb-required">*</span></label>
                        <select id="create_slot_id" name="slot_id" class="vb-input" required>
                            <option value=""><?= __('admin.bookings.placeholder_select_slot') ?></option>
                            <?php foreach ($slots as $s): ?>
                                <option value="<?= htmlspecialchars($s['id'], ENT_QUOTES, 'UTF-8') ?>"
                                        <?= old('slot_id') === $s['id'] ? 'selected' : '' ?>>
                                    <?= $dayNames[(int) $s['day_of_week']] ?> <?= \App\Engine\Locale::time(new \DateTimeImmutable($s['start_time'])) ?>–<?= \App\Engine\Locale::time(new \DateTimeImmutable($s['end_time'])) ?>
                                    <?php if ($s['label']): ?>(<?= htmlspecialchars($s['label'], ENT_QUOTES, 'UTF-8') ?>)<?php endif; ?>
                                    · <?= str_replace(':count', (string) (int) $s['max_capacity'], __('admin.capacity_slots.capacity_max_seats')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="vb-form-group">
                        <label for="create_party_size" class="vb-label"><?= __('admin.bookings.label_party_size') ?> <span class="vb-required">*</span></label>
                        <input type="number" id="create_party_size" name="party_size" class="vb-input" required
                               min="1" value="<?= e(old('party_size', '2')) ?>">
                    </div>
                </div>

                <div class="vb-form-row">
                    <div class="vb-form-group">
                        <label for="create_date" class="vb-label"><?= __('admin.bookings.label_date') ?> <span class="vb-required">*</span></label>
                        <input type="date" id="create_date" name="date" class="vb-input" required
                               value="<?= e(old('date', '')) ?>">
                    </div>
                    <div class="vb-form-group">
                        <!-- Intentional: reserved for future fields -->
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Section 2: Customer -->
    <div class="vb-settings-section">
        <div class="vb-card">
            <div class="vb-card-header">
                <div class="vb-card-title-row">
                    <i data-lucide="contact" class="vb-card-icon"></i>
                    <div class="vb-card-title"><?= __('admin.bookings.customer') ?></div>
                </div>
            </div>

            <div class="vb-form-grid">
                <div class="vb-form-row">
                    <div class="vb-form-group">
                        <label for="create_customer_name" class="vb-label"><?= __('admin.bookings.label_customer_name') ?> <span class="vb-required">*</span></label>
                        <input type="text" id="create_customer_name" name="customer_name" class="vb-input" required
                               value="<?= e(old('customer_name', '')) ?>"
                               autocomplete="off">
                    </div>
                    <div class="vb-form-group">
                        <label for="create_customer_email" class="vb-label"><?= __('admin.bookings.label_customer_email') ?> <span class="vb-required">*</span></label>
                        <input type="email" id="create_customer_email" name="customer_email" class="vb-input" required
                               value="<?= e(old('customer_email', '')) ?>"
                               autocomplete="off">
                    </div>
                </div>

                <div class="vb-form-row">
                    <div class="vb-form-group">
                        <label for="create_customer_phone" class="vb-label">
                            <?= __('admin.bookings.label_customer_phone') ?>
                            <?php if ((int) ($tenant['require_phone'] ?? 0) === 1): ?>
                                <span class="vb-required">*</span>
                            <?php endif; ?>
                        </label>
                        <input type="tel" id="create_customer_phone" name="customer_phone" class="vb-input"
                               value="<?= e(old('customer_phone', '')) ?>"
                               <?php if ((int) ($tenant['require_phone'] ?? 0) === 1): ?>required<?php endif; ?>
                               autocomplete="off">
                    </div>
                    <div class="vb-form-group">
                        <!-- Intentional: reserved for future fields -->
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/custom-fields.php'; ?>

    <!-- Section 3: Internal Notes -->
    <div class="vb-settings-section">
        <div class="vb-card">
            <div class="vb-card-header">
                <div class="vb-card-title-row">
                    <i data-lucide="notebook-pen" class="vb-card-icon"></i>
                    <div class="vb-card-title" id="create-notes-label"><?= __('admin.bookings.label_notes') ?></div>
                </div>
            </div>
            <div class="vb-form-grid">
                <div class="vb-form-group">
                    <textarea id="create_notes" aria-labelledby="create-notes-label" name="notes" class="vb-input" rows="3"
                              placeholder="<?= __('admin.bookings.label_notes') ?>…"><?= e(old('notes', '')) ?></textarea>
                </div>
            </div>
        </div>
    </div>

    <!-- Actions -->
    <div class="vb-form-actions">
        <a href="<?= $baseUrl ?>/bookings" class="vb-btn vb-btn-ghost">
            <?= __('admin.common.cancel') ?>
        </a>
        <button type="submit" class="vb-btn vb-btn-primary">
            <i data-lucide="check" class="vb-icon-sm"></i>
            <?= __('admin.bookings.btn_create_booking') ?>
        </button>
    </div>
</form>

<?php
$content = ob_get_clean();
include dirname(__DIR__, 3) . '/admin/layout.php';
