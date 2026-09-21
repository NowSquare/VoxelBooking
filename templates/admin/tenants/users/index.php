<?php
/**
 * Business user list — Team page.
 *
 * Variables: $tenant, $users, $flash, $tenantId, $csrfToken
 */
$tenant = $tenant ?? [];
$users = $users ?? [];
$tenantId = $tenantId ?? '';

ob_start();
?>
<div class="vb-page-header">
    <div>
        <h2 class="vb-page-title"><?= __('admin.users.page_title') ?></h2>
        <p class="vb-page-subtitle"><?= __('admin.users.page_subtitle') ?></p>
    </div>
    <a href="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/users/invite" class="vb-btn vb-btn-primary">
        <i data-lucide="user-plus" class="vb-icon-md"></i>
        <?= __('admin.users.invite_btn') ?>
    </a>
</div>

<?php if (!empty($flash)): ?>
    <?php include __DIR__ . '/../../../partials/alert.php'; ?>

    <?php if (!empty($flash['credentials'])): ?>
        <div class="vb-card vb-credentials-card">
            <div class="vb-card-header">
                <i data-lucide="key" class="vb-icon-md"></i>
                <strong><?= __('admin.users.credentials_title') ?></strong>
            </div>
            <p class="vb-hint vb-mb-md"><?= __('admin.users.credentials_hint') ?></p>
            <div class="vb-credentials-grid">
                <div class="vb-credentials-row">
                    <span class="vb-credentials-label"><?= __('admin.users.credentials_email') ?></span>
                    <code class="vb-credentials-value"><?= htmlspecialchars($flash['credentials']['email'] ?? '', ENT_QUOTES, 'UTF-8') ?></code>
                </div>
                <div class="vb-credentials-row">
                    <span class="vb-credentials-label"><?= __('admin.users.credentials_password') ?></span>
                    <code class="vb-credentials-value"><?= htmlspecialchars($flash['credentials']['password'] ?? '', ENT_QUOTES, 'UTF-8') ?></code>
                </div>
                <div class="vb-credentials-row">
                    <span class="vb-credentials-label"><?= __('admin.users.credentials_login') ?></span>
                    <code class="vb-credentials-value"><?= htmlspecialchars(app_url('/admin/login'), ENT_QUOTES, 'UTF-8') ?></code>
                </div>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php if (empty($users)): ?>
    <div class="vb-empty-state">
        <i data-lucide="users" class="vb-empty-icon"></i>
        <h3><?= __('admin.users.empty_title') ?></h3>
        <p><?= __('admin.users.empty_desc') ?></p>
        <a href="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/users/invite" class="vb-btn vb-btn-primary vb-mb-md">
            <i data-lucide="user-plus" class="vb-icon-md"></i>
            <?= __('admin.users.invite_btn') ?>
        </a>
    </div>
<?php else: ?>
    <div class="vb-table-container">
        <div class="vb-table-wrap">
            <table class="vb-table" id="users-table">
                <thead>
                    <tr>
                        <th><?= __('admin.users.col_name') ?></th>
                        <th><?= __('admin.users.col_email') ?></th>
                        <th><?= __('admin.users.col_role') ?></th>
                        <th><?= __('admin.users.col_status') ?></th>
                        <th><?= __('admin.users.col_last_login') ?></th>
                        <th class="vb-text-right"><?= __('admin.users.col_actions') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $bu): ?>
                    <tr>
                        <td>
                            <div class="vb-user-cell">
                                <span class="vb-avatar vb-avatar-xs"><?= mb_strtoupper(mb_substr($bu['name'], 0, 1)) ?></span>
                                <span class="vb-user-name"><?= htmlspecialchars($bu['name'], ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                        </td>
                        <td class="vb-text-secondary"><?= htmlspecialchars($bu['email'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <span class="vb-badge vb-badge-<?= $bu['role'] === 'owner' ? 'primary' : 'neutral' ?>">
                                <?= $bu['role'] === 'owner' ? __('admin.users.role_owner') : __('admin.users.role_manager') ?>
                            </span>
                        </td>
                        <td>
                            <span class="vb-badge vb-badge-<?= $bu['is_active'] ? 'success' : 'danger' ?>">
                                <?= $bu['is_active'] ? __('admin.users.status_active') : __('admin.users.status_inactive') ?>
                            </span>
                        </td>
                        <td class="vb-text-secondary">
                            <?php if ($bu['last_login_at']): ?>
                                <?= htmlspecialchars(\App\Engine\Locale::date(new \DateTimeImmutable($bu['last_login_at'])) . ' ' . date('H:i', strtotime($bu['last_login_at'])), ENT_QUOTES, 'UTF-8') ?>
                            <?php else: ?>
                                <span class="vb-text-tertiary">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="vb-text-right">
                            <?php if ($bu['is_active']): ?>
                                <form method="POST"
                                      action="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/users/<?= htmlspecialchars($bu['id'], ENT_QUOTES, 'UTF-8') ?>/deactivate"
                                      class="vb-form-flush"
                                      data-confirm="<?= __('admin.users.deactivate_confirm') ?>" data-confirm-text="<?= __('admin.users.deactivate') ?>">
                                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                    <button type="submit" class="vb-btn vb-btn-ghost vb-btn-sm vb-btn-danger-text">
                                        <?= __('admin.users.deactivate') ?>
                                    </button>
                                </form>
                            <?php else: ?>
                                <form method="POST"
                                      action="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/users/<?= htmlspecialchars($bu['id'], ENT_QUOTES, 'UTF-8') ?>/activate"
                                      class="vb-form-flush"
                                      data-confirm="<?= __('admin.users.activate_confirm') ?>" data-confirm-text="<?= __('admin.users.activate') ?>">
                                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                    <button type="submit" class="vb-btn vb-btn-ghost vb-btn-sm">
                                        <?= __('admin.users.activate') ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php
$content = ob_get_clean();
include dirname(__DIR__, 3) . '/admin/layout.php';
