<?php
/**
 * Audit Log Viewer — Compliance §5/§6 audit trail.
 *
 * Read-only view of structured audit log entries.
 * Filters: action type. Paginated, 50 entries per page.
 *
 * Uses design system exclusively — zero inline style= attributes.
 * Icons: Lucide via data-lucide.
 *
 * Variables: $user, $version, $csrfToken, $pageTitle, $entries, $total, $page, $perPage, $actionFilter
 */
$activePage = 'settings.audit';
$activeTab = 'audit';

$actionLabels = [
    'auth.login'                => ['label' => __('admin.audit.action_auth_login'),             'variant' => 'success'],
    'auth.login_failed'         => ['label' => __('admin.audit.action_auth_login_failed'),      'variant' => 'error'],
    'auth.logout'               => ['label' => __('admin.audit.action_auth_logout'),             'variant' => 'default'],
    'auth.password_changed'     => ['label' => __('admin.audit.action_auth_password_changed'),   'variant' => 'accent'],
    'auth.brute_force_detected' => ['label' => __('admin.audit.action_auth_brute_force'),        'variant' => 'error'],
    'auth.access_denied'        => ['label' => __('admin.audit.action_auth_access_denied'),      'variant' => 'error'],
    'settings.updated'          => ['label' => __('admin.audit.action_settings_updated'),         'variant' => 'accent'],
    'booking.created'           => ['label' => __('admin.audit.action_booking_created'),          'variant' => 'success'],
    'booking.cancelled'         => ['label' => __('admin.audit.action_booking_cancelled'),        'variant' => 'error'],
    'booking.rescheduled'       => ['label' => __('admin.audit.action_booking_rescheduled'),      'variant' => 'accent'],
    'booking.status_changed'    => ['label' => __('admin.audit.action_booking_status_changed'),   'variant' => 'accent'],
    'customer.anonymized'       => ['label' => __('admin.audit.action_customer_anonymized'),      'variant' => 'warning'],
    'privacy.request_received'  => ['label' => __('admin.audit.action_privacy_request_received'), 'variant' => 'warning'],
    'privacy.request_completed' => ['label' => __('admin.audit.action_privacy_request_completed'),'variant' => 'success'],
    'privacy.deletion_confirmed'=> ['label' => __('admin.audit.action_privacy_deletion_confirmed') ?? 'Deletion confirmed', 'variant' => 'warning'],
    'privacy.deletion_dismissed'=> ['label' => __('admin.audit.action_privacy_deletion_dismissed') ?? 'Deletion dismissed', 'variant' => 'default'],
    'data.export_generated'     => ['label' => __('admin.audit.action_data_export'),             'variant' => 'accent'],
    'api_key.created'           => ['label' => __('admin.audit.action_api_key_created'),         'variant' => 'warning'],
    'api_key.revoked'           => ['label' => __('admin.audit.action_api_key_revoked'),         'variant' => 'default'],
    'system.audit_cleanup'      => ['label' => __('admin.audit.action_system_audit_cleanup'),     'variant' => 'default'],
    'system.migration'          => ['label' => __('admin.audit.action_system_migration'),         'variant' => 'default'],
    'retention.executed'        => ['label' => __('admin.audit.action_retention_executed'),       'variant' => 'default'],
    'role.changed'              => ['label' => __('admin.audit.action_role_changed'),             'variant' => 'warning'],
    'rate_limit.exceeded'       => ['label' => __('admin.audit.action_rate_limit_exceeded'),     'variant' => 'error'],
    'tenant.created'            => ['label' => __('admin.audit.action_tenant_created'),           'variant' => 'success'],
    'tenant.archived'           => ['label' => __('admin.audit.action_tenant_archived'),         'variant' => 'default'],
];

$actorIcons = [
    'operator'      => 'shield',
    'business_user' => 'user',
    'system'        => 'server',
    'api'           => 'zap',
    'customer'      => 'user',
];

$totalPages = max(1, (int) ceil($total / $perPage));

ob_start();

include dirname(__DIR__, 2) . '/partials/settings-tabs.php';
?>

<div class="vb-card vb-animate-in stagger-1">
    <div class="vb-card-header vb-card-header-toolbar">
        <div class="vb-card-title-row">
            <i data-lucide="shield" class="vb-card-icon"></i>
            <div>
                <div class="vb-card-title"><?= __('admin.audit.title') ?></div>
                <div class="vb-card-desc"><?= __p('admin.audit.showing_count', (int) $total) ?></div>
            </div>
        </div>
        <div class="vb-page-actions">
            <?php
            $auditExportParams = array_filter(['action' => $actionFilter ?? ''], fn($v) => $v !== '');
            $auditExportUrl = '/admin/settings/audit/export' . ($auditExportParams ? '?' . http_build_query($auditExportParams) : '');
            ?>
            <a href="<?= htmlspecialchars($auditExportUrl, ENT_QUOTES, 'UTF-8') ?>"
               class="vb-btn vb-btn-ghost vb-btn-sm" id="btn-export-csv"
               <?php if (\App\Engine\DemoMode::isActive()): ?>onclick="event.preventDefault(); showDemoToast()"<?php endif; ?>>
                <i data-lucide="download" class="vb-icon-sm"></i>
                <?= __('admin.common.export_csv') ?>
            </a>
            <form method="GET" action="/admin/settings/audit" class="vb-filter-form">
                <select name="action" class="vb-input vb-input-compact vb-filter-select" onchange="this.form.submit()">
                    <option value=""><?= __('admin.audit.all_events') ?></option>
                    <?php foreach ($actionLabels as $actionKey => $meta):
                        $selected = ($actionFilter ?? '') === $actionKey ? 'selected' : '';
                    ?>
                        <option value="<?= htmlspecialchars($actionKey, ENT_QUOTES, 'UTF-8') ?>" <?= $selected ?>><?= htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    </div>

    <?php
    $resultCountKey = 'admin.audit.showing_count';
    $resultCountValue = count($entries);
    $resultCountActive = ($actionFilter ?? '') !== '';
    include dirname(__DIR__, 2) . '/partials/table-result-count.php';
    ?>
    <?php if (empty($entries)): ?>
        <div class="vb-table-empty">
            <i data-lucide="shield-check" class="vb-table-empty-icon"></i>
            <div class="vb-table-empty-title">
                <?= __('admin.audit.empty_title') ?><?= ($actionFilter ?? '') !== '' ? ' ' . __('admin.audit.empty_filter') : '' ?>
            </div>
            <div class="vb-table-empty-desc"><?= __('admin.audit.empty_desc') ?></div>
        </div>
    <?php else: ?>
        <div class="vb-scroll-x">
            <table class="vb-table">
                <thead>
                    <tr>
                        <th class="vb-col-time"><?= __('admin.audit.th_time') ?></th>
                        <th class="vb-col-event"><?= __('admin.audit.th_event') ?></th>
                        <th class="vb-col-actor"><?= __('admin.audit.th_actor') ?></th>
                        <th class="vb-col-entity"><?= __('admin.audit.th_entity') ?></th>
                        <th><?= __('admin.audit.th_details') ?></th>
                        <th class="vb-col-request"><?= __('admin.audit.th_request') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($entries as $entry): ?>
                        <?php
                        $meta = $actionLabels[$entry['action']] ?? ['label' => $entry['action'], 'variant' => 'default'];
                        $details = array_filter(['connection'=>$entry['connection_id'],'receipt'=>$entry['receipt_id'],'result'=>$entry['result']]);
                        $createdAt = new \DateTime($entry['created_at']);
                        $actorIcon = $actorIcons[$entry['actor_type']] ?? 'help-circle';
                        ?>
                        <tr class="vb-fade-in-up">
                            <td data-label="<?= __('admin.audit.th_time') ?>">
                                <span class="vb-mono vb-cell-dim">
                                    <?= \App\Engine\Locale::datetimeFull($createdAt) ?>
                                </span>
                            </td>
                            <td data-label="<?= __('admin.audit.th_event') ?>">
                                <span class="vb-badge vb-badge-<?= $meta['variant'] ?>">
                                    <?= htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </td>
                            <td class="vb-cell-dim" data-label="<?= __('admin.audit.th_actor') ?>">
                                <span class="vb-audit-actor">
                                    <i data-lucide="<?= $actorIcon ?>" class="vb-audit-actor-icon"></i>
                                    <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $entry['actor_type'])), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </td>
                            <td class="vb-cell-dim" data-label="<?= __('admin.audit.th_entity') ?>">
                                <?= htmlspecialchars($entry['entity_type'], ENT_QUOTES, 'UTF-8') ?>
                                <?php if ($entry['entity_id']): ?>
                                    <br><span class="vb-mono vb-cell-micro"><?= htmlspecialchars(substr($entry['entity_id'], 0, 8), ENT_QUOTES, 'UTF-8') ?>…</span>
                                <?php endif; ?>
                            </td>
                            <td class="vb-audit-details" data-label="<?= __('admin.audit.th_details') ?>">
                                <?php if ($details): ?>
                                    <?php foreach ($details as $key => $value): ?>
                                        <?php if (is_array($value) && isset($value['old'], $value['new'])): ?>
                                            <span class="vb-audit-kv">
                                                <strong class="vb-audit-key"><?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>:</strong>
                                                <span class="vb-line-through"><?= htmlspecialchars((string)$value['old'], ENT_QUOTES, 'UTF-8') ?></span>
                                                → <?= htmlspecialchars((string)$value['new'], ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        <?php elseif (is_array($value)): ?>
                                            <strong class="vb-audit-key"><?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>:</strong>
                                            <span class="vb-mono"><?= htmlspecialchars(json_encode($value, JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php else: ?>
                                            <span class="vb-audit-kv">
                                                <strong class="vb-audit-key"><?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>:</strong>
                                                <?= htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span class="vb-text-tertiary">—</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="<?= __('admin.audit.th_request') ?>">
                                <span class="vb-mono vb-cell-micro">
                                    <?= htmlspecialchars(substr(($entry['correlation_id'] ?? ''), 0, 8), ENT_QUOTES, 'UTF-8') ?>…
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php
        $paginationPage = $page;
        $paginationTotalPages = $totalPages;
        $paginationBaseUrl = '/admin/settings/audit';
        $paginationParams = ($actionFilter ?? '') !== '' ? '&action=' . urlencode($actionFilter) : '';
        $paginationI18nPrefix = 'admin.audit';
        include dirname(__DIR__, 2) . '/partials/table-pagination.php';
        ?>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
include dirname(__DIR__, 2) . '/admin/layout.php';
