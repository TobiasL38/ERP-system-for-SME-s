<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

date_default_timezone_set('Africa/Nairobi');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
    ]);
    session_start();
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $sent = (string) ($_POST['csrf_token'] ?? '');
    $known = (string) ($_SESSION['csrf_token'] ?? '');
    if ($sent === '' || $known === '' || !hash_equals($known, $sent)) {
        http_response_code(419);
        exit('Your form session expired. Go back, refresh the page, and try again.');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function get_flash(): ?array
{
    $message = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($message) ? $message : null;
}

function current_user(): ?array
{
    return isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : null;
}

function require_login(): void
{
    if (!current_user()) {
        flash('info', 'Please sign in to continue.');
        redirect('login.php');
    }
}

function require_roles(array $roles): void
{
    require_login();
    if (!in_array(current_user()['role'] ?? '', $roles, true)) {
        http_response_code(403);
        $pageTitle = 'Access denied';
        require __DIR__ . '/header.php';
        echo '<section class="panel"><h1>Access denied</h1><p>Your account does not have permission to perform this action.</p><a class="button" href="index.php">Return to dashboard</a></section>';
        require __DIR__ . '/footer.php';
        exit;
    }
}

function money(mixed $amount): string
{
    return 'KSh ' . number_format((float) $amount, 2);
}

function format_date(?string $date): string
{
    if (!$date) {
        return '—';
    }
    $timestamp = strtotime($date);
    return $timestamp ? date('d M Y, H:i', $timestamp) : e($date);
}

function post_string(string $key, int $maxLength = 255): string
{
    $value = trim((string) ($_POST[$key] ?? ''));
    return mb_substr($value, 0, $maxLength);
}

function format_day(?string $date): string
{
    if (!$date) {
        return '—';
    }
    $timestamp = strtotime($date);
    return $timestamp ? date('d M Y', $timestamp) : e($date);
}

function money_short(mixed $amount): string
{
    $n = (float) $amount;
    if ($n >= 1000000) {
        return 'KSh ' . number_format($n / 1000000, 2) . 'M';
    }
    if ($n >= 1000) {
        return 'KSh ' . number_format($n / 1000, 1) . 'K';
    }
    return 'KSh ' . number_format($n, 0);
}

function next_sale_number(): string
{
    $prefix = 'SO-' . date('Ymd') . '-';
    $stmt = db()->prepare('SELECT order_number FROM sales_orders WHERE order_number LIKE ? ORDER BY order_number DESC LIMIT 1');
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();
    $seq = 1;
    if ($last) {
        $parts = explode('-', $last);
        $seq = (int) end($parts) + 1;
    }
    return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
}

function next_invoice_number(): string
{
    $prefix = 'INV-' . date('Ymd') . '-';
    $stmt = db()->prepare('SELECT invoice_number FROM invoices WHERE invoice_number LIKE ? ORDER BY invoice_number DESC LIMIT 1');
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();
    $seq = 1;
    if ($last) {
        $parts = explode('-', $last);
        $seq = (int) end($parts) + 1;
    }
    return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
}

function recalc_order(int $orderId): void
{
    $stmt = db()->prepare('SELECT vat_rate FROM sales_orders WHERE id=?');
    $stmt->execute([$orderId]);
    $rate = (float) ($stmt->fetchColumn() ?: 0);

    $subStmt = db()->prepare('SELECT COALESCE(SUM(line_total),0) FROM sales_order_items WHERE order_id=?');
    $subStmt->execute([$orderId]);
    $subtotal = (float) $subStmt->fetchColumn();

    $tax = round($subtotal * $rate / 100, 2);
    $total = round($subtotal + $tax, 2);

    db()->prepare('UPDATE sales_orders SET subtotal=?, tax_amount=?, total_amount=? WHERE id=?')
        ->execute([$subtotal, $tax, $total, $orderId]);
}

function next_po_number(): string
{
    $prefix = 'PO-' . date('Ymd') . '-';
    $stmt = db()->prepare('SELECT po_number FROM purchase_orders WHERE po_number LIKE ? ORDER BY po_number DESC LIMIT 1');
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();
    $seq = 1;
    if ($last) {
        $parts = explode('-', $last);
        $seq = (int) end($parts) + 1;
    }
    return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
}

function output_csv(string $filename, array $headers, array $rows): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    
    // Add UTF-8 BOM for Excel compatibility
    fputs($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    
    if (!empty($headers)) {
        fputcsv($output, $headers);
    }
    
    foreach ($rows as $row) {
        fputcsv($output, $row);
    }
    
    fclose($output);
    exit;
}

function get_setting(string $key, string $default = ''): string
{
    static $settings = null;
    if ($settings === null) {
        try {
            $settings = db()->query('SELECT setting_key, setting_value FROM system_settings')->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Throwable $e) {
            $settings = [];
        }
    }
    return $settings[$key] ?? $default;
}

function log_action(string $action, string $details = ''): void
{
    try {
        $stmt = db()->prepare('INSERT INTO audit_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)');
        $stmt->execute([
            current_user()['id'] ?? null,
            $action,
            mb_substr($details, 0, 500),
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);
    } catch (Throwable $e) {
        // Silently fail so audit logging doesn't break the app
    }
}