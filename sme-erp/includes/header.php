<?php
$pageTitle = $pageTitle ?? 'Dashboard';
$user = current_user();
$activePage = basename($_SERVER['PHP_SELF'] ?? 'index.php');
$role = $user['role'] ?? '';
$navItems = [
    ['file' => 'index.php', 'label' => 'Overview', 'icon' => '⌂', 'roles' => ['admin','manager','sales','inventory','procurement','accountant','hr']],
    ['file' => 'products.php', 'label' => 'Products', 'icon' => '▦', 'roles' => ['admin','manager','inventory','procurement']],
    ['file' => 'stock.php', 'label' => 'Stock movements', 'icon' => '↕', 'roles' => ['admin','manager','inventory','procurement']],
    ['file' => 'sales.php', 'label' => 'Sales orders', 'icon' => '≡', 'roles' => ['admin','manager','sales']],
    ['file' => 'invoices.php', 'label' => 'Invoices', 'icon' => '$', 'roles' => ['admin','manager','sales','accountant']],
    ['file' => 'purchases.php', 'label' => 'Purchase orders', 'icon' => '🛒', 'roles' => ['admin','manager','procurement','inventory']],
    ['file' => 'customers.php', 'label' => 'Customers', 'icon' => '♙', 'roles' => ['admin','manager','sales']],
    ['file' => 'suppliers.php', 'label' => 'Suppliers', 'icon' => '⇄', 'roles' => ['admin','manager','procurement']],
    ['file' => 'accounts.php', 'label' => 'Chart of Accounts', 'icon' => '📒', 'roles' => ['admin','manager','accountant']],
    ['file' => 'journals.php', 'label' => 'Journal Entries', 'icon' => '✍', 'roles' => ['admin','manager','accountant']],
    ['file' => 'reports.php', 'label' => 'Financial Reports', 'icon' => '📊', 'roles' => ['admin','manager','accountant']],
    ['file' => 'employees.php', 'label' => 'Employees', 'icon' => '👥', 'roles' => ['admin','manager','hr']],
    ['file' => 'attendance.php', 'label' => 'Attendance', 'icon' => '📅', 'roles' => ['admin','manager','hr']],
    ['file' => 'leave.php', 'label' => 'Leave requests', 'icon' => '🌴', 'roles' => ['admin','manager','hr']],
]; // <--- The array is properly closed here

// Filter items by the current user's role
$navItems = array_values(array_filter($navItems, static fn(array $item): bool => in_array($role, $item['roles'] ?? [], true)));

// Add Admin-only links OUTSIDE the array
if ($role === 'admin') {
    $navItems[] = ['file' => 'users.php', 'label' => 'User accounts', 'icon' => '⚙'];
    $navItems[] = ['file' => 'settings.php', 'label' => 'System Settings', 'icon' => '🔧'];
    $navItems[] = ['file' => 'audit.php', 'label' => 'Audit Trail', 'icon' => '🛡'];
}
$flashMessage = get_flash();
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> · SME ERP</title>
    <link rel="stylesheet" href="assets/app.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
<div class="app-shell">
    <aside class="sidebar">
        <a class="brand" href="index.php">
            <span class="brand-mark">E</span>
            <span><strong><?= e(get_setting('company_name', 'SME ERP')) ?></strong><small>Business workspace</small></span>
        </a>
        <p class="nav-caption">WORKSPACE</p>
        <nav class="main-nav" aria-label="Main navigation">
            <?php foreach ($navItems as $item): ?>
                <a class="nav-link <?= $activePage === $item['file'] ? 'active' : '' ?>" href="<?= e($item['file']) ?>">
                    <span class="nav-icon" aria-hidden="true"><?= e($item['icon']) ?></span>
                    <span><?= e($item['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-note">
            <span class="status-dot"></span>
            <div><strong>Local development</strong><small>XAMPP · MySQL</small></div>
        </div>
        <div class="sidebar-bottom">ERP Research Project<br><span>SME Operations · v0.1</span></div>
    </aside>
    <main class="main-area">
        <header class="topbar">
            <div><span class="eyebrow">BUSINESS MANAGEMENT</span><h1><?= e($pageTitle) ?></h1></div>
            <div class="user-menu">
                <div class="avatar"><?= e(strtoupper(substr($user['full_name'] ?? 'U', 0, 1))) ?></div>
                <div class="user-copy"><strong><?= e($user['full_name'] ?? 'User') ?></strong><small><?= e(ucfirst($user['role'] ?? '')) ?></small></div>
                <a class="profile-link" href="profile.php">Profile</a>
                <form method="post" action="logout.php" class="logout-form">
                    <?= csrf_field() ?>
                    <button class="button button-quiet button-small" type="submit">Sign out</button>
                </form>
            </div>
        </header>
        <div class="page-content">
            <?php if ($flashMessage): ?>
                <div class="alert alert-<?= e($flashMessage['type']) ?>" role="status"><?= e($flashMessage['message']) ?></div>
            <?php endif; ?>
