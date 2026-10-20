<?php
/**
 * Edit Staff Member — tenant-scoped.
 *
 * Fields: name*, email*, phone, title, bio, avatar, service assignments.
 *
 * Variables: $tenant, $member, $services, $linkedServiceIds, $old, $tenantId, $csrfToken, $flash
 */
$tenant = $tenant ?? [];
$member = $member ?? [];
$services = $services ?? [];
$linkedServiceIds = $linkedServiceIds ?? [];
$tenantId = $tenantId ?? '';
$staffId = $member['id'] ?? '';
$currentServices = old('service_ids', $linkedServiceIds);

ob_start();
?>

<?php
ob_start();
?>
<?php if ($member['is_active']): ?>
            <span class="vb-badge vb-badge-success"><?= __('admin.staff.status_active') ?></span>
        <?php else: ?>
            <span class="vb-badge vb-badge-default"><?= __('admin.staff.status_inactive') ?></span>
        <?php endif; ?>
<?php
$pageHeader = [
    'title' => __('admin.staff.edit'),
    'subtitle' => $member['name'] ?? '',
    'backUrl' => '/admin/tenants/' . $tenantId . '/staff',
    'backLabel' => __('admin.staff.title'),
    'actions' => ob_get_clean(),
];
include __DIR__ . '/../../partials/page-header.php';
?>

<?php if ($flash): ?>
    <?php include __DIR__ . '/../../../partials/alert.php'; ?>
<?php endif; ?>

<div class="vb-card p-6 vb-fade-in-up">
    <form method="POST"
          action="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/staff/<?= htmlspecialchars($staffId, ENT_QUOTES, 'UTF-8') ?>/edit"
          id="edit-staff-form" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

        <div class="vb-form-row">
            <div class="vb-form-group">
                <label for="staff_name" class="vb-label"><?= __('admin.staff.label_name') ?> <span class="vb-required">*</span></label>
                <input type="text" id="staff_name" name="name" class="vb-input" required
                       value="<?= e(old('name', $member['name'] ?? '')) ?>"
                       placeholder="<?= __('admin.staff.placeholder_name') ?>">
            </div>
            <div class="vb-form-group">
                <label for="staff_email" class="vb-label"><?= __('admin.staff.label_email') ?> <span class="vb-required">*</span></label>
                <input type="email" id="staff_email" name="email" class="vb-input" required
                       value="<?= e(old('email', $member['email'] ?? '')) ?>"
                       placeholder="<?= __('admin.staff.placeholder_email') ?>">
            </div>
        </div>

        <div class="vb-form-row">
            <div class="vb-form-group">
                <label for="staff_phone" class="vb-label"><?= __('admin.staff.label_phone') ?></label>
                <input type="tel" id="staff_phone" name="phone" class="vb-input"
                       value="<?= e(old('phone', $member['phone'] ?? '')) ?>"
                       placeholder="<?= __('admin.staff.placeholder_phone') ?>">
            </div>
            <div class="vb-form-group">
                <label for="staff_title" class="vb-label"><?= __('admin.staff.label_title') ?></label>
                <input type="text" id="staff_title" name="title" class="vb-input"
                       value="<?= e(old('title', $member['title'] ?? '')) ?>"
                       placeholder="<?= __('admin.staff.placeholder_title') ?>">
            </div>
        </div>

        <div class="vb-form-group col-span-full">
            <label for="staff_bio" class="vb-label"><?= __('admin.staff.label_bio') ?></label>
            <textarea id="staff_bio" name="bio" class="vb-input vb-textarea" rows="2"
                      placeholder="<?= __('admin.staff.placeholder_bio') ?>"><?= e(old('bio', $member['bio'] ?? '')) ?></textarea>
        </div>

        <?php
        $uploadFieldName   = 'avatar';
        $uploadFieldId     = 'staff_avatar';
        $uploadLabel       = __('admin.staff.label_avatar');
        $uploadHint        = __('admin.staff.avatar_hint');
        $uploadCurrentPath = $member['avatar_path'] ?? null;
        $uploadShape       = 'circle';
        include __DIR__ . '/../../../partials/upload-field.php';
        ?>

        <div class="vb-section-divider"></div>

        <div class="vb-form-group">
            <label class="vb-label vb-icon-label mb-1">
                <i data-lucide="layers"></i>
                <?= __('admin.staff.label_services') ?>
            </label>
            <?php if (empty($services)): ?>
                <p class="vb-text-tertiary text-sm"><?= __('admin.staff.no_services_available') ?></p>
            <?php else: ?>
                <div class="vb-checkbox-grid mt-4">
                    <?php foreach ($services as $svc): ?>
                    <label class="vb-checkbox-label">
                        <input type="checkbox"
                               name="service_ids[]"
                               value="<?= htmlspecialchars($svc['id'], ENT_QUOTES, 'UTF-8') ?>"
                               <?= in_array($svc['id'], $currentServices) ? 'checked' : '' ?>>
                        <span class="vb-checkbox-text"><?= htmlspecialchars($svc['name'], ENT_QUOTES, 'UTF-8') ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="vb-form-actions mt-8">
            <button type="submit" class="vb-btn vb-btn-primary">
                <i data-lucide="check" class="w-4 h-4"></i>
                <?= __('admin.settings.save_button') ?>
            </button>
            <a href="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/staff"
               class="vb-btn vb-btn-ghost">
                <?= __('admin.common.cancel') ?>
            </a>
        </div>
    </form>
</div>

<?php
$content = ob_get_clean();
include dirname(__DIR__, 3) . '/admin/layout.php';
