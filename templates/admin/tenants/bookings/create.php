<?php
/**
 * Manual booking creation form — tenant-scoped (timeslot pattern).
 *
 * Variables: $tenant, $tenantId, $services, $staff, $serviceStaffMap, $csrfToken, $flash, $old
 */
$tenant          = $tenant ?? [];
$tenantId        = $tenantId ?? '';
$services        = $services ?? [];
$staff           = $staff ?? [];
$serviceStaffMap = $serviceStaffMap ?? [];
$slug            = htmlspecialchars($tenant['slug'] ?? '', ENT_QUOTES, 'UTF-8');
$baseUrl         = "/admin/tenants/" . htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8');

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
      class="vb-animate-in"
      x-data="bookingCreate"
      data-slug="<?= $slug ?>"
      data-locale="<?= htmlspecialchars(\App\Engine\Locale::getLocale(), ENT_QUOTES, 'UTF-8') ?>"
      data-staff-map="<?= htmlspecialchars(json_encode($serviceStaffMap, JSON_FORCE_OBJECT | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>"
      data-all-staff="<?= htmlspecialchars(json_encode(array_map(fn($m) => [
          'id'    => $m['id'],
          'label' => $m['name'] . ($m['title'] ? ' — ' . $m['title'] : ''),
      ], $staff), JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>"
      data-old="<?= htmlspecialchars(json_encode([
          'service_id' => old('service_id'),
          'staff_id'   => old('staff_id'),
          'date'       => old('date'),
          'time'       => old('time'),
      ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>"
      novalidate>
    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

    <!-- Section 1: Appointment Details -->
    <div class="vb-settings-section">
        <div class="vb-card">
            <div class="vb-card-header">
                <div class="vb-card-title-row">
                    <i data-lucide="calendar" class="vb-card-icon"></i>
                    <div>
                        <div class="vb-card-title"><?= __('admin.bookings.date_time') ?></div>
                        <div class="vb-card-desc"><?= __('admin.bookings.create_subtitle') ?></div>
                    </div>
                </div>
            </div>

            <div class="vb-form-grid">
                <div class="vb-form-row">
                    <div class="vb-form-group">
                        <label for="create_service_id" class="vb-label"><?= __('admin.bookings.label_service') ?> <span class="vb-required">*</span></label>
                        <select id="create_service_id" name="service_id" class="vb-input" required
                                x-model="serviceId" @change="onServiceChange()">
                            <option value=""><?= __('admin.bookings.placeholder_select_service') ?></option>
                            <?php foreach ($services as $s): ?>
                                <option value="<?= htmlspecialchars($s['id'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-duration="<?= (int) $s['duration_minutes'] ?>"
                                        <?= old('service_id') === $s['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($s['name'], ENT_QUOTES, 'UTF-8') ?>
                                    (<?= str_replace(':count', (string) (int) $s['duration_minutes'], __('admin.bookings.duration_unit')) ?><?php if ($s['price']): ?> · <?= htmlspecialchars($s['price_label'] ?? __c((float) $s['price'], $tenant['currency'] ?? 'EUR'), ENT_QUOTES, 'UTF-8') ?><?php endif; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="vb-form-group">
                        <label for="create_staff_id" class="vb-label"><?= __('admin.bookings.label_staff') ?></label>
                        <select id="create_staff_id" name="staff_id" class="vb-input"
                                x-model="staffId" @change="onStaffChange()">
                            <option value=""><?= __('admin.bookings.placeholder_any_staff') ?></option>
                            <template x-for="m in filteredStaff" :key="m.id">
                                <option :value="m.id" x-text="m.label" :selected="m.id === staffId"></option>
                            </template>
                        </select>
                    </div>
                </div>

                <div class="vb-form-row">
                    <div class="vb-form-group">
                        <label for="create_date" class="vb-label"><?= __('admin.bookings.label_date') ?> <span class="vb-required">*</span></label>
                        <input type="date" id="create_date" name="date" class="vb-input" required
                               x-model="date" @change="onDateChange()"
                               value="<?= e(old('date', '')) ?>">
                    </div>

                    <div class="vb-form-group">
                        <label for="create_time" class="vb-label"><?= __('admin.bookings.label_time') ?> <span class="vb-required">*</span></label>
                        <select id="create_time" name="time" class="vb-input" required
                                x-model="time" :disabled="loadingSlots || slots.length === 0">
                            <template x-if="!serviceId || !date">
                                <option value=""><?= __('admin.bookings.placeholder_select_time') ?></option>
                            </template>
                            <template x-if="serviceId && date && loadingSlots">
                                <option value=""><?= __('admin.bookings.placeholder_loading_slots') ?></option>
                            </template>
                            <template x-if="serviceId && date && !loadingSlots && slots.length === 0">
                                <option value=""><?= __('admin.bookings.placeholder_no_slots') ?></option>
                            </template>
                            <template x-if="serviceId && date && !loadingSlots && slots.length > 0">
                                <option value=""><?= __('admin.bookings.label_time') ?>…</option>
                            </template>
                            <template x-for="slot in slots" :key="slot.time">
                                <option :value="slot.time" x-text="slot.label"></option>
                            </template>
                        </select>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Section 2: Customer -->
    <?php include __DIR__ . '/customer-fields.php'; ?>

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
