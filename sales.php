<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_roles(['admin', 'manager', 'sales']);
$pageTitle = 'Sales orders';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_string('action', 30);
    try {
        if ($action === 'create') {
            $customerId = (int) ($_POST['customer_id'] ?? 0);
            $orderDate  = post_string('order_date', 10);
            $notes      = post_string('notes', 500);
            $vatRaw     = (string) ($_POST['vat_rate'] ?? '0');

            if ($customerId < 1) throw new RuntimeException('Choose a customer.');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $orderDate)) throw new RuntimeException('Enter a valid order date.');
            if (!is_numeric($vatRaw) || (float) $vatRaw < 0 || (float) $vatRaw > 100) throw new RuntimeException('VAT rate must be between 0 and 100.');

            $check = db()->prepare('SELECT id FROM customers WHERE id=? AND is_active=1');
            $check->execute([$customerId]);
            if (!$check->fetch()) throw new RuntimeException('The selected customer is unavailable.');

            $orderNumber = next_sale_number();
            db()->prepare('INSERT INTO sales_orders (order_number, customer_id, order_date, status, subtotal, tax_amount, total_amount, vat_rate, notes, created_by) VALUES (?, ?, ?, "draft", 0, 0, 0, ?, ?, ?)')
                ->execute([$orderNumber, $customerId, $orderDate, (float) $vatRaw, $notes ?: null, current_user()['id']]);

            flash('success', 'Draft order ' . $orderNumber . ' created. Add line items next.');
            redirect('sale.php?id=' . (int) db()->lastInsertId());
        }
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        $error = 'The sale order could not be created.';
    }
}

$search = trim((string) ($_GET['q'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));
$sql = 'SELECT so.*, c.name AS customer_name,
               (SELECT COUNT(*) FROM sales_order_items WHERE order_id = so.id) AS item_count,
               inv.invoice_number, inv.status AS invoice_status
        FROM sales_orders so
        JOIN customers c ON c.id = so.customer_id
        LEFT JOIN invoices inv ON inv.order_id = so.id
        WHERE 1=1';
$params = [];
if ($search !== '') {
    $sql .= ' AND (so.order_number LIKE ? OR c.name LIKE ?)';
    $term = '%' . $search . '%';
    $params[] = $term;
    $params[] = $term;
}
if ($status !== '' && in_array($status, ['draft', 'confirmed', 'fulfilled', 'cancelled'], true)) {
    $sql .= ' AND so.status = ?';
    $params[] = $status;
}
$sql .= ' ORDER BY so.id DESC LIMIT 100';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

$customers = db()->query('SELECT id, name FROM customers WHERE is_active=1 ORDER BY name')->fetchAll();
require __DIR__ . '/includes/header.php';
?>
<?php if ($error !== ''): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<section class="split-layout">
    <article class="panel form-panel">
        <div class="panel-heading"><div><h2>New sale order</h2><p>Start a draft, then add line items.</p></div></div>
        <?php if (!$customers): ?>
            <div class="empty-state compact"><strong>No active customers</strong><p>Add a customer before raising a sale.</p><a class="button button-primary" href="customers.php">Add customer</a></div>
        <?php else: ?>
        <form method="post" class="form-stack">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <div><label for="customer_id">Customer *</label>
                <select id="customer_id" name="customer_id" required>
                    <option value="">Choose a customer</option>
                    <?php foreach ($customers as $customer): ?>
                        <option value="<?= (int) $customer['id'] ?>" <?= (int) ($_POST['customer_id'] ?? 0) === (int) $customer['id'] ? 'selected' : '' ?>><?= e($customer['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field-grid">
                <div><label for="order_date">Order date *</label><input id="order_date" name="order_date" type="date" required value="<?= e($_POST['order_date'] ?? date('Y-m-d')) ?>"></div>
                <div><label for="vat_rate">VAT rate (%)</label><input id="vat_rate" name="vat_rate" type="number" min="0" max="100" step="0.01" value="<?= e($_POST['vat_rate'] ?? '16') ?>"><small class="field-help">Kenyan standard rate is 16%. Use 0 to skip VAT.</small></div>
            </div>
            <div><label for="notes">Notes</label><textarea id="notes" name="notes" rows="2" maxlength="500" placeholder="Optional reference, delivery instructions"><?= e($_POST['notes'] ?? '') ?></textarea></div>
            <button class="button button-primary" type="submit">Create draft order</button>
        </form>
        <?php endif; ?>
    </article>

    <article class="panel list-panel">
        <div class="panel-heading"><div><h2>Sales orders</h2><p><?= count($orders) ?> order(s) shown</p></div></div>
        <form method="get" class="search-form">
            <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search order # or customer">
            <select name="status" style="padding:10px 11px;border:1px solid #dce2eb;border-radius:9px;font-size:12px;background:#fff">
                <option value="">All statuses</option>
                <?php foreach (['draft', 'confirmed', 'fulfilled', 'cancelled'] as $s): ?>
                    <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="button button-secondary" type="submit">Filter</button>
            <?php if ($search !== '' || $status !== ''): ?><a class="text-link" href="sales.php">Clear</a><?php endif; ?>
        </form>
        <?php if (!$orders): ?>
            <div class="empty-state compact"><strong>No sale orders yet</strong><p>Create your first order using the form on the left.</p></div>
        <?php else: ?>
            <div class="table-wrap"><table><thead><tr><th>Order</th><th>Customer</th><th>Items</th><th>Total</th><th>Status</th><th>Invoice</th></tr></thead><tbody>
            <?php foreach ($orders as $order): ?>
                <tr>
                    <td><strong><a href="sale.php?id=<?= (int) $order['id'] ?>"><?= e($order['order_number']) ?></a></strong><small class="table-subtext"><?= e(format_day($order['order_date'])) ?></small></td>
                    <td><?= e($order['customer_name']) ?></td>
                    <td><?= (int) $order['item_count'] ?></td>
                    <td><?= e(money($order['total_amount'])) ?></td>
                    <td><span class="pill pill-<?= $order['status'] === 'fulfilled' ? 'success' : ($order['status'] === 'cancelled' ? 'neutral' : ($order['status'] === 'confirmed' ? 'info' : 'warning')) ?>"><?= e(ucfirst($order['status'])) ?></span></td>
                    <td><?php if ($order['invoice_number']): ?><a class="text-link" href="invoices.php?q=<?= urlencode($order['invoice_number']) ?>"><?= e($order['invoice_number']) ?></a><?php else: ?><span class="muted">—</span><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </article>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>