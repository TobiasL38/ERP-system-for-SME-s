<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_roles(['admin']);
$pageTitle = 'User accounts';
$error = '';
$roles = ['admin' => 'Administrator', 'manager' => 'Manager', 'sales' => 'Sales officer', 'inventory' => 'Inventory officer', 'procurement' => 'Procurement officer', 'accountant' => 'Accountant', 'hr' => 'HR officer'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_string('action', 30);
    try {
        if ($action === 'create') {
            $name = post_string('full_name', 150);
            $username = post_string('username', 60);
            $email = post_string('email', 190);
            $role = post_string('role', 30);
            $password = (string) ($_POST['password'] ?? '');
            if ($name === '' || $username === '' || $email === '' || $password === '') throw new RuntimeException('Complete all required fields.');
            if (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $username)) throw new RuntimeException('Username must be 3–60 characters and use letters, numbers, dots, underscores or hyphens.');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid email address.');
            if (strlen($password) < 8) throw new RuntimeException('Password must be at least 8 characters.');
            if (!array_key_exists($role, $roles)) throw new RuntimeException('Select a valid role.');
            $stmt = db()->prepare('INSERT INTO users (full_name, username, email, password_hash, role, status) VALUES (?, ?, ?, ?, ?, "active")');
            $stmt->execute([$name, $username, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
            flash('success', 'User account created. Share the credentials securely with the user.');
            redirect('users.php');
        }
        if ($action === 'toggle') {
            $id = (int) ($_POST['id'] ?? 0);
            $status = post_string('status', 20);
            if ($id < 1 || !in_array($status, ['active', 'inactive'], true)) throw new RuntimeException('Invalid account status request.');
            if ($id === (int) current_user()['id']) throw new RuntimeException('You cannot deactivate your own account.');
            if ($status === 'inactive') {
                $check = db()->prepare('SELECT COUNT(*) FROM users WHERE role="admin" AND status="active" AND id<>?');
                $check->execute([$id]);
                $target = db()->prepare('SELECT role FROM users WHERE id=?'); $target->execute([$id]); $targetRole = $target->fetchColumn();
                if ($targetRole === 'admin' && (int) $check->fetchColumn() < 1) throw new RuntimeException('At least one active administrator must remain.');
            }
            db()->prepare('UPDATE users SET status=? WHERE id=?')->execute([$status, $id]);
            flash('success', 'User account status updated.');
            redirect('users.php');
        }
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    } catch (PDOException $exception) {
        $error = $exception->getCode() === '23000' ? 'Username or email already exists. Use a different one.' : 'The account could not be saved.';
    }
}
$users = db()->query('SELECT id, full_name, username, email, role, status, created_at FROM users ORDER BY id')->fetchAll();
require __DIR__ . '/includes/header.php';
?>
<?php if ($error !== ''): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<section class="split-layout users-layout">
    <article class="panel form-panel"><div class="panel-heading"><div><h2>Create user</h2><p>Give each staff member only the access they need.</p></div></div>
        <form method="post" class="form-stack">
            <?= csrf_field() ?><input type="hidden" name="action" value="create">
            <div><label for="full_name">Full name *</label><input id="full_name" name="full_name" maxlength="150" required value="<?= e($_POST['full_name'] ?? '') ?>"></div>
            <div><label for="username">Username *</label><input id="username" name="username" maxlength="60" required value="<?= e($_POST['username'] ?? '') ?>" autocomplete="off"><small class="field-help">3–60 characters: letters, numbers, dot, underscore or hyphen.</small></div>
            <div><label for="email">Email *</label><input id="email" name="email" type="email" maxlength="190" required value="<?= e($_POST['email'] ?? '') ?>"></div>
            <div><label for="role">Role *</label><select id="role" name="role" required><?php foreach ($roles as $key => $label): ?><option value="<?= e($key) ?>" <?= ($_POST['role'] ?? 'sales') === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
            <div><label for="password">Temporary password *</label><input id="password" name="password" type="password" minlength="8" required autocomplete="new-password"><small class="field-help">At least 8 characters. Ask the user to change it after signing in.</small></div>
            <button class="button button-primary" type="submit">Create account</button>
        </form>
    </article>
    <article class="panel list-panel"><div class="panel-heading"><div><h2>Team accounts</h2><p><?= count($users) ?> account(s) registered</p></div></div>
        <div class="table-wrap"><table><thead><tr><th>User</th><th>Role</th><th>Status</th><th>Action</th></tr></thead><tbody>
        <?php foreach ($users as $account): ?><tr><td><strong><?= e($account['full_name']) ?></strong><small class="table-subtext"><?= e($account['username']) ?> · <?= e($account['email']) ?></small></td><td><?= e($roles[$account['role']] ?? ucfirst($account['role'])) ?></td><td><span class="pill pill-<?= $account['status'] === 'active' ? 'success' : 'neutral' ?>"><?= e(ucfirst($account['status'])) ?></span></td><td><?php if ((int) $account['id'] === (int) current_user()['id']): ?><span class="muted">You</span><?php else: ?><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $account['id'] ?>"><input type="hidden" name="status" value="<?= $account['status'] === 'active' ? 'inactive' : 'active' ?>"><button class="link-button <?= $account['status'] === 'active' ? 'danger-link' : '' ?>" type="submit"><?= $account['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button></form><?php endif; ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </article>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
