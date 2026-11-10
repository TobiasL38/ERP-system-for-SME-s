<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    redirect('index.php');
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $identity = post_string('identity', 190);
    $password = (string) ($_POST['password'] ?? '');
    if ($identity === '' || $password === '') {
        $error = 'Enter your username/email and password.';
    } else {
        try {
            $stmt = db()->prepare('SELECT id, full_name, username, email, password_hash, role, status FROM users WHERE username = :username OR email = :email LIMIT 1');
            $stmt->execute(['username' => $identity, 'email' => $identity]);
            $account = $stmt->fetch();
            if ($account && $account['status'] === 'active' && password_verify($password, $account['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['user'] = [
                    'id' => (int) $account['id'],
                    'full_name' => $account['full_name'],
                    'username' => $account['username'],
                    'email' => $account['email'],
                    'role' => $account['role'],
                ];
                unset($_SESSION['csrf_token']);
                flash('success', 'Welcome back, ' . $account['full_name'] . '.');
                log_action('User Login', 'Successfully signed in');
                redirect('index.php');
            }
            $error = 'The sign-in details were not recognized, or the account is inactive.';
        } catch (PDOException $exception) {
            $error = 'Unable to connect to the database. Import database/schema.sql and check config/database.php.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · SME ERP</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body class="login-page">
<div class="login-decoration decor-one"></div><div class="login-decoration decor-two"></div>
<main class="login-wrap">
    <a class="brand login-brand" href="login.php"><span class="brand-mark">SME</span><span><strong>Enterprise Resource Planning</strong><small>Business workspace</small></span></a>
    <section class="login-card">
        <span class="eyebrow">WELCOME BACK</span>
        <h1>Sign in to your workspace</h1>
        <p class="muted">Manage your products, stock, customers and suppliers in one place.</p>
        <?php if ($error !== ''): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
        <?php $loginFlash = get_flash(); if ($loginFlash): ?><div class="alert alert-<?= e($loginFlash['type']) ?>"><?= e($loginFlash['message']) ?></div><?php endif; ?>
        <form method="post" action="login.php" class="form-stack">
            <?= csrf_field() ?>
            <label for="identity">Username or email</label>
            <input id="identity" name="identity" type="text" autocomplete="username" required value="<?= e($_POST['identity'] ?? '') ?>" placeholder="Enter your username">
            <div class="label-row"><label for="password">Password</label></div>
            <input id="password" name="password" type="password" autocomplete="current-password" required placeholder="Enter your password">
            <button class="button button-primary button-full" type="submit">Sign in <span aria-hidden="true">→</span></button>
        </form>
    </section>
    <p class="login-foot">Enterprise Resource Planning · Small and Medium Enterprises</p>
</main>
</body>
</html>
