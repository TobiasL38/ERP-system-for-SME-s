<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
$pageTitle = 'My profile';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
    $stmt = db()->prepare('SELECT password_hash FROM users WHERE id=? AND status="active"');
    $stmt->execute([current_user()['id']]);
    $hash = $stmt->fetchColumn();
    if (!$hash || !password_verify($currentPassword, (string) $hash)) {
        $error = 'Current password is incorrect.';
    } elseif (strlen($newPassword) < 8) {
        $error = 'New password must be at least 8 characters.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'The new passwords do not match.';
    } else {
        db()->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($newPassword, PASSWORD_DEFAULT), current_user()['id']]);
        session_regenerate_id(true);
        flash('success', 'Password changed successfully.');
        redirect('profile.php');
    }
}
require __DIR__ . '/includes/header.php';
?>
<section class="profile-grid">
    <article class="panel profile-summary"><div class="large-avatar"><?= e(strtoupper(substr(current_user()['full_name'], 0, 1))) ?></div><h2><?= e(current_user()['full_name']) ?></h2><p class="muted">@<?= e(current_user()['username']) ?></p><span class="pill pill-info"><?= e(ucfirst(current_user()['role'])) ?></span><dl><dt>Email</dt><dd><?= e(current_user()['email']) ?></dd><dt>Account access</dt><dd>Active</dd></dl></article>
    <article class="panel form-panel"><div class="panel-heading"><div><h2>Change password</h2><p>Use a unique password that you do not use on other sites.</p></div></div>
        <?php if ($error !== ''): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
        <form method="post" class="form-stack">
            <?= csrf_field() ?>
            <div><label for="current_password">Current password *</label><input id="current_password" name="current_password" type="password" required autocomplete="current-password"></div>
            <div><label for="new_password">New password *</label><input id="new_password" name="new_password" type="password" minlength="8" required autocomplete="new-password"><small class="field-help">At least 8 characters.</small></div>
            <div><label for="confirm_password">Confirm new password *</label><input id="confirm_password" name="confirm_password" type="password" minlength="8" required autocomplete="new-password"></div>
            <button class="button button-primary" type="submit">Update password</button>
        </form>
    </article>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
