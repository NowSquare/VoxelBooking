<?php
$activePage = 'connections';
ob_start();
?>
<div class="vb-page-header"><div class="vb-page-heading">
    <h1 class="vb-page-title"><?= e(__('oauth.title')) ?></h1>
    <p class="vb-page-subtitle"><?= e(__('oauth.subtitle', ['client' => $clientName])) ?></p>
</div></div>
<div class="vb-card">
    <div class="vb-card-header">
        <div class="vb-card-title"><?= e(__('oauth.review_title')) ?></div>
        <p class="vb-card-desc"><?= e(__($isCustomer ? 'oauth.customer_review' : 'oauth.review_description')) ?></p>
    </div>
    <form method="post" action="/oauth/authorize" class="vb-card-body">
        <input type="hidden" name="_csrf_token" value="<?= e($csrfToken) ?>">
        <input type="hidden" name="request" value="<?= e($handle) ?>">
        <?php if ($formError): ?><p class="vb-alert vb-alert-danger" role="alert"><?= e(__('oauth.errors.' . $formError)) ?></p><?php endif; ?>
        <div class="oauth-client-identity">
            <div class="vb-info-row">
                <span class="vb-info-label"><?= e(__('oauth.returns_to')) ?></span>
                <span class="vb-info-value oauth-redirect-host">
                    <?php if ($redirectHost['local']): ?>
                        <?= e(__('oauth.returns_to_local', ['host' => $redirectHost['host']])) ?>
                    <?php else: ?>
                        <strong data-redirect-host><?= e($redirectHost['host']) ?></strong>
                    <?php endif; ?>
                </span>
            </div>
            <p class="vb-hint<?= $clientNameVerified ? '' : ' vb-hint-warning' ?>">
                <?= e(__($clientNameVerified ? 'oauth.verified_name' : 'oauth.unverified_name')) ?>
            </p>
            <div class="vb-info-row">
                <span class="vb-info-label"><?= e(__('oauth.client_id')) ?></span>
                <code class="vb-info-value oauth-client-id"><?= e($clientId) ?></code>
            </div>
        </div>
        <div class="vb-info-row">
            <span class="vb-info-label"><?= e(__('oauth.account')) ?></span>
            <span class="vb-info-value"><?= e($user['name'] ?? '') ?> · <?= e($user['email'] ?? '') ?></span>
        </div>
        <?php if ($isCustomer): ?>
            <p class="vb-card-desc"><?= e(__($customerPurpose === 'booking.manage' ? 'oauth.booking_access' : 'oauth.privacy_access')) ?></p>
        <?php else: ?>
        <fieldset class="vb-form-group">
            <legend class="vb-label"><?= e(__('oauth.businesses')) ?></legend>
            <?php foreach ($businesses as $business): ?>
                <label class="vb-checkbox-label">
                    <input type="checkbox" name="business_ids[]" value="<?= e($business['id']) ?>"<?= count($businesses) === 1 ? ' checked' : '' ?>>
                    <span><?= e($business['name']) ?></span>
                </label>
            <?php endforeach; ?>
        </fieldset>
        <?php endif; ?>
        <div class="vb-form-group">
            <h2 class="vb-card-title"><?= e(__('oauth.permissions')) ?></h2>
            <p class="vb-card-desc"><?= e(__($isCustomer ? 'oauth.customer_permissions' : 'oauth.permissions_description')) ?></p>
            <ul class="oauth-action-list">
                <?php foreach (array_slice($operationTitles, 0, 4) as $title): ?>
                    <li><?= e($title) ?></li>
                <?php endforeach; ?>
            </ul>
            <details>
                <summary class="vb-label"><?= e(__('oauth.permission_details')) ?></summary>
                <?php if (count($operationTitles) > 4): ?>
                <ul>
                    <?php foreach (array_slice($operationTitles, 4) as $title): ?>
                        <li><?= e($title) ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
                <p class="vb-card-desc"><?= e(__('oauth.scope_details')) ?></p>
                <ul>
                    <?php foreach ($scopes as $scope): ?>
                        <li><code><?= e($scope) ?></code></li>
                    <?php endforeach; ?>
                </ul>
            </details>
        </div>
        <p class="vb-card-desc"><?= e(__($isCustomer ? 'oauth.customer_expiry' : 'oauth.expiry', ['expiry' => $expiresAt])) ?></p>
        <div class="vb-form-actions">
            <button type="submit" name="decision" value="approve" class="vb-btn vb-btn-primary"><?= e(__('oauth.connect')) ?></button>
            <button type="submit" name="decision" value="deny" class="vb-btn vb-btn-secondary"><?= e(__('oauth.cancel')) ?></button>
        </div>
    </form>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/oauth-layout.php';
