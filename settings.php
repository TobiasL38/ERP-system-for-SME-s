<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_roles(['admin']);
$pageTitle = 'System Settings';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $settingsToUpdate = [
            'company_name' => post_string('company_name', 150),
            'company_email' => post_string('company_email', 190),
            'company_phone' => post_string('company_phone', 40),
            'currency' => post_string('currency', 10),
            'vat_rate' => post_string('vat_rate', 10),
        ];
        
        if ($settingsToUpdate['company_name'] === '') throw new RuntimeException('Company name is required.');
        if (!is_numeric($settingsToUpdate['vat_rate']) || (float)$settingsToUpdate['vat_rate'] < 0 || (float)$settingsToUpdate['vat_rate'] > 100) throw new RuntimeException('VAT rate must be between 0 and 100.');

        db()->beginTransaction();
        $stmt = db()->prepare('INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        foreach ($settingsToUpdate as $key => $value) {
            $stmt->execute([$key, $value]);
        }
        db()->commit();
        
        log_action('Update Settings', 'Updated system configuration');
        flash('success', 'Settings updated successfully.');
        redirect('settings.php');
    } catch (RuntimeException $e) {
        if (db()->inTransaction()) db()->rollBack();
        $error = $e->getMessage();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        $error = 'Failed to update settings.';
    }
}

require __DIR__ . '/includes/header.php';
?>
<?php if ($error !== ''): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<section class="panel form-panel" style="max-width: 600px; margin: 0 auto;">
    <div class="panel-heading"><div><h2>System Configuration</h2><p>These details appear on invoices and reports.</p></div></div>
    <form method="post" class="form-stack">
        <?= csrf_field() ?>
        <div><label for="company_name">Company Name *</label><input id="company_name" name="company_name" maxlength="150" required value="<?= e(get_setting('company_name')) ?>"></div>
        <div class="field-grid">
            <div><label for="company_email">Company Email</label><input id="company_email" name="company_email" type="email" maxlength="190" value="<?= e(get_setting('company_email')) ?>"></div>
            <div><label for="company_phone">Company Phone</label><input id="company_phone" name="company_phone" type="text" maxlength="40" value="<?= e(get_setting('company_phone')) ?>"></div>
        </div>
        <div class="field-grid">
            <div><label for="currency">Currency Symbol</label><input id="currency" name="currency" maxlength="10" required value="<?= e(get_setting('currency', 'KSh')) ?>"></div>
            <div><label for="vat_rate">Default VAT Rate (%)</label><input id="vat_rate" name="vat_rate" type="number" min="0" max="100" step="0.01" required value="<?= e(get_setting('vat_rate', '16.00')) ?>"></div>
        </div>
        <button class="button button-primary" type="submit">Save Settings</button>
    </form>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>