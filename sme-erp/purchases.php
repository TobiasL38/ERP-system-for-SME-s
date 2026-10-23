<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_roles(['admin', 'manager', 'procurement', 'inventory']);
$pageTitle = 'Purchase orders';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_string('action', 30);
    try {
        if ($action === 'create') {
            $supplierId = (int) ($_POST['supplier_id'] ?? 0);
            $orderDate  = post_string('order_date', 10);
            $expected   = post_string('expected_date', 10);
            $notes      = post_string('notes', 500);

            if ($supplierId < 1) throw new RuntimeException('Choose a supplier.');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $orderDate)) throw new RuntimeException('Enter a valid order date.');
            if ($expected !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expected)) throw new RuntimeException('Enter a valid expected date.');

            $check = db()->prepare('SELECT id FROM suppliers WHERE id=? AND is_active=1');
            $check->execute([$supplierId]);
            if (!$check->fetch()) throw new RuntimeException('The selected supplier is unavailable.');

            $poNumber = next_po_number();
            db()->prepare('INSERT INTO purchase_orders (po_number, supplier_id, order_date, expected_date, status, total_amount, notes, created_by) VALUES (?, ?, ?, ?, "draft", 0, ?, ?)')
                ->execute([$poNumber, $supplierId, $orderDate, $expected ?: null, $notes ?: null, current_user()['id']]);

            flash('success', 'Draft purchase order ' . $poNumber . ' created. Add line items next.');
            redirect('purchase.php?id=' . (int) db()->lastInsertId());
        }
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        $error = 'The purchase order could not be created.';
    }
}

$search = trim((string) ($_GET['q'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));
$sql = 'SELECT po.*, s.name AS supplier_name,
               (SELECT COUNT(*) FROM purchase_order_items WHERE purchase_order_id = po.id) AS item_count
        FROM purchase_orders po
        JOIN suppliers s ON s.id = po.supplier_id
        WHERE 1=1';
$params = [];
if ($search !== '') {
    $sql .= ' AND (po.po_number LIKE ? OR s.name LIKE ?)';
    $term = '%' . $search . '%';
    $params[] = $term;
    $params[] = $term;
}
if ($status !== '' && in_array($status, ['draft', 'submitted', 'part_received', 'received', 'cancelled'], true)) {
    $sql .= ' AND po.status = ?';
    $params[] = $status;
}
$sql .= ' ORDER BY po.id DESC LIMIT 100';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

$suppliers = db()->query('SELECT id, name FROM suppliers WHERE is_active=1 ORDER BY name')->fetchAll();
require __DIR__ . '/includes/header.php';
?>
<?php if ($error !== ''): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<section class="split-layout">
    <article class="panel form-panel">
        <div class="panel-heading"><div><h2>New purchase order</h2><p>Start a draft, then add items.</p></div></div>
        <?php if (!$suppliers): ?>
            <div class="empty-state compact"><strong>No active suppliers</strong><p>Add a supplier before raising a PO.</p><a class="button button-primary" href="suppliers.php">Add supplier</a></div>
        <?php else: ?>
        <form method="post" class="form-stack">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <div><label for="supplier_id">Supplier *</label>
                <select id="supplier_id" name="supplier_id" required>
                    <option value="">Choose a supplier</option>
                    <?php foreach ($suppliers as $supplier): ?>
                        <option value="<?= (int) $supplier['id'] ?>" <?= (int) ($_POST['supplier_id'] ?? 0) === (int) $supplier['id'] ? 'selected' : '' ?>><?= e($supplier['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field-grid">
                <div><label for="order_date">Order date *</label><input id="order_date" name="order_date" type="date" required value="<?= e($_POST['order_date'] ?? date('Y-m-d')) ?>"></div>
                <div><label for="expected_date">Expected delivery</label><input id="expected_date" name="expected_date" type="date" value="<?= e($_POST['expected_date'] ?? '') ?>"></div>
            </div>
            <div><label for="notes">Notes</label><textarea id="notes" name="notes" rows="2" maxlength="500" placeholder="Optional reference, delivery instructions"><?= e($_POST['notes'] ?? '') ?></textarea></div>
            <button class="button button-primary" type="submit">Create draft PO</button>
        </form>
        <?php endif; ?>
    </article>

    <article class="panel list-panel">
        <div class="panel-heading"><div><h2>Purchase orders</h2><p><?= count($orders) ?> order(s) shown</p></div></div>
        <form method="get" class="search-form">
            <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search PO # or supplier">
            <select name="status" style="padding:10px 11px;border:1px solid #dce2eb;border-radius:9px;font-size:12px;background:#fff">
                <option value="">All statuses</option>
                <?php foreach (['draft', 'submitted', 'part_received', 'received', 'cancelled'] as $s): ?>
                    <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $s))) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="button button-secondary" type="submit">Filter</button>
            <?php if ($search !== '' || $status !== ''): ?><a class="text-link" href="purchases.php">Clear</a><?php endif; ?>
        </form>
        <?php if (!$orders): ?>
            <div class="empty-state compact"><strong>No purchase orders yet</strong><p>Create your first order using the form on the left.</p></div>
        <?php else: ?>
            <div class="table-wrap"><table><thead><tr><th>PO #</th><th>Supplier</th><th>Items</th><th>Total</th><th>Status</th><th>Expected</th></tr></thead><tbody>
            <?php foreach ($orders as $order): 
                $pill = match($order['status']) {
                    'received' => 'success', 'part_received' => 'info', 'cancelled' => 'neutral', 'submitted' => 'warning', default => 'warning'
                };
            ?>
                <tr>
                    <td><strong><a href="purchase.php?id=<?= (int) $order['id'] ?>"><?= e($order['po_number']) ?></a></strong><small class="table-subtext"><?= e(format_day($order['order_date'])) ?></small></td>
                    <td><?= e($order['supplier_name']) ?></td>
                    <td><?= (int) $order['item_count'] ?></td>
                    <td><?= e(money($order['total_amount'])) ?></td>
                    <td><span class="pill pill-<?= $pill ?>"><?= e(ucwords(str_replace('_', ' ', $order['status']))) ?></span></td>
                    <td><?= e(format_day($order['expected_date'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </article>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>