<?php
/**
 * Customer list — tenant-scoped.
 *
 * Follows the canonical table-page structure from tenants/index.php:
 *   1. vb-page-header  (title + subtitle + optional action)
 *   2. vb-table-container  (single card wrapping toolbar + table)
 *        ├ vb-table-toolbar  (search + filters)
 *        └ vb-table-wrap > table.vb-table
 *   3. vb-pagination  (outside the card)
 *
 * Variables: $tenant, $customers, $total, $page, $perPage, $search, $tenantId, $csrfToken
 */
$tenant = $tenant ?? [];
$customers = $customers ?? [];
$tenantId = $tenantId ?? '';
$search = $search ?? '';
$total = $total ?? 0;
$page = $page ?? 1;
$perPage = $perPage ?? 25;
$totalPages = max(1, (int) ceil($total / $perPage));
$baseUrl = "/admin/tenants/" . htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8');

ob_start();
?>

<?php ob_start(); ?>
        <?php
        $exportParams = array_filter(['search' => $search], fn($v) => $v !== '');
        $exportUrl = $baseUrl . '/customers/export' . ($exportParams ? '?' . http_build_query($exportParams) : '');
        ?>
        <a href="<?= htmlspecialchars($exportUrl, ENT_QUOTES, 'UTF-8') ?>"
           class="vb-btn vb-btn-ghost vb-btn-sm" id="btn-export-csv"
           <?php if (\App\Engine\DemoMode::isActive()): ?>onclick="event.preventDefault(); showDemoToast()"<?php endif; ?>>
            <i data-lucide="download" class="vb-icon-sm"></i>
            <?= __('admin.common.export_csv') ?>
        </a>
    <?php
$pageHeader = [
    'title' => __('admin.customers.page_title'),
    'subtitle' => __p('admin.customers.showing_count', (int) $total),
    'actions' => ob_get_clean(),
];
include __DIR__ . '/../../partials/page-header.php';
?>

<?php if (empty($customers) && $search === ''): ?>
    <div class="vb-empty-state vb-animate-in">
        <i data-lucide="users" class="vb-empty-icon"></i>
        <h3><?= __('admin.customers.empty_title') ?></h3>
        <p><?= __('admin.customers.empty_desc') ?></p>
    </div>
<?php else: ?>
    <div class="vb-table-container vb-animate-in">
        <!-- Toolbar: search -->
        <div class="vb-table-toolbar">
            <?php
            $searchAction = $baseUrl . '/customers';
            $searchValue = $search;
            $searchPlaceholder = __('admin.customers.search_placeholder');
            $searchHiddenFields = [];
            include dirname(__DIR__, 3) . '/partials/table-search.php';
            ?>
        </div>

        <?php
        $resultCountKey = 'admin.customers.showing_count';
        $resultCountValue = count($customers);
        $resultCountActive = ($search !== '');
        include dirname(__DIR__, 3) . '/partials/table-result-count.php';
        ?>
        <?php if (empty($customers)): ?>
            <!-- Search returned zero results -->
            <div class="vb-empty-state vb-empty-state--compact">
                <i data-lucide="search-x" class="vb-empty-icon"></i>
                <h3><?= __('admin.customers.empty_search_title') ?></h3>
                <p><?= __('admin.customers.empty_search_desc') ?></p>
            </div>
        <?php else: ?>
            <div class="vb-table-wrap">
                <table class="vb-table" id="customers-table">
                    <thead>
                        <tr>
                            <th><?= __('admin.customers.col_name') ?></th>
                            <th><?= __('admin.customers.col_email') ?></th>
                            <th><?= __('admin.customers.col_phone') ?></th>
                            <th class="vb-text-center"><?= __('admin.customers.col_bookings') ?></th>
                            <th><?= __('admin.customers.col_last_booking') ?></th>
                            <th><?= __('admin.customers.col_joined') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($customers as $i => $c): ?>
                        <tr class="vb-fade-in-up stagger-<?= min($i + 1, 6) ?> vb-clickable-row"
                            data-href="<?= $baseUrl ?>/customers/<?= htmlspecialchars($c['id'], ENT_QUOTES, 'UTF-8') ?>">
                            <td>
                                <div class="vb-user-cell">
                                    <span class="vb-avatar vb-avatar-xs"><?= mb_strtoupper(mb_substr($c['name'], 0, 1)) ?></span>
                                    <span class="vb-cell-name"><?= htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                            </td>
                            <td class="vb-text-secondary"><?= htmlspecialchars($c['email'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="vb-text-secondary">
                                <?php if ($c['phone']): ?>
                                    <?= htmlspecialchars($c['phone'], ENT_QUOTES, 'UTF-8') ?>
                                <?php else: ?>
                                    <span class="vb-text-tertiary">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="vb-text-center">
                                <?php $bc = (int) $c['booking_count']; ?>
                                <?php if ($bc > 0): ?>
                                    <span class="vb-cell-numeric"><?= $bc ?></span>
                                <?php else: ?>
                                    <span class="vb-cell-numeric vb-text-tertiary">0</span>
                                <?php endif; ?>
                            </td>
                            <td class="vb-text-secondary">
                                <?php if ($c['last_booking_at']): ?>
                                    <?= htmlspecialchars(\App\Engine\Locale::dateLong(new DateTimeImmutable($c['last_booking_at'])), ENT_QUOTES, 'UTF-8') ?>
                                <?php else: ?>
                                    <span class="vb-text-tertiary">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="vb-text-secondary">
                                <?= htmlspecialchars(\App\Engine\Locale::dateLong(new DateTimeImmutable($c['created_at'])), ENT_QUOTES, 'UTF-8') ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php
    $paginationPage = $page;
    $paginationTotalPages = $totalPages;
    $paginationBaseUrl = $baseUrl . '/customers';
    $paginationParams = $search !== '' ? '&search=' . urlencode($search) : '';
    $paginationI18nPrefix = 'admin.common';
    include dirname(__DIR__, 3) . '/partials/table-pagination.php';
    ?>
<?php endif; ?>

<?php
$content = ob_get_clean();
include dirname(__DIR__, 3) . '/admin/layout.php';
