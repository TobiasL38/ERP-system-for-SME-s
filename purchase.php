<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_roles(['admin', 'manager', 'procurement', 'inventory']);

$id = (int) ($_GET['id'] ?? 0);
if ($id < 1) {
    flash('info', 'Select a purchase order to view.');
    redirect('purchases.php');
}
$error = '';
$canReceive = in_array(current_user()['role'], ['admin', 'manager', 'procurement', 'inventory'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_string('action', 30);
    try {
        $stmt = db()->prepare('SELECT * FROM purchase_orders WHERE id=?');
        $stmt->execute([$id]);
        $order = $stmt->fetch();
        if (!$order) throw new RuntimeException('Purchase order not found.');

        if ($action === 'add_item') {
            if ($order['status'] !== 'draft') throw new RuntimeException('Items can only be added to drafts.');
            $productId = (int) ($_POST['product_id'] ?? 0);
            $qtyRaw    = (string) ($_POST['quantity_ordered'] ?? '');
            $costRaw   = (string) ($_POST['unit_cost'] ?? '');

            if ($productId < 1) throw new RuntimeException('Choose a product.');
            if (!is_numeric($qtyRaw) || (float) $qtyRaw <= 0) throw new RuntimeException('Quantity must be greater than zero.');
            if (!is_numeric($costRaw) || (float) $costRaw < 0) throw new RuntimeException('Unit cost must be non-negative.');

            $pstmt = db()->prepare('SELECT id, name FROM products WHERE id=? AND is_active=1');
            $pstmt->execute([$productId]);
            if (!$pstmt->fetch()) throw new RuntimeException('Product unavailable.');

            $qty   = round((float) $qtyRaw, 2);
            $cost  = round((float) $costRaw, 2);
            $line  = round($qty * $cost, 2);

            db()->beginTransaction();
            $check = db()->prepare('SELECT id, quantity_ordered FROM purchase_order_items WHERE purchase_order_id=? AND product_id=?');
            $check->execute([$id, $productId]);
            $existing = $check->fetch();
            if ($existing) {
                $mergedQty = round((float) $existing['quantity_ordered'] + $qty, 2);
                $line = round($mergedQty * $cost, 2);
                db()->prepare('UPDATE purchase_order_items SET quantity_ordered=?, unit_cost=?, line_total=? WHERE id=?')
                    ->execute([$mergedQty, $cost, $line, $existing['id']]);
            } else {
                db()->prepare('INSERT INTO purchase_order_items (purchase_order_id, product_id, quantity_ordered, quantity_received, unit_cost, line_total) VALUES (?, ?, ?, 0, ?, ?)')
                    ->execute([$id, $productId, $qty, $cost, $line]);
            }
            
            // Recalculate PO total
            $sum = db()->prepare('SELECT COALESCE(SUM(line_total),0) FROM purchase_order_items WHERE purchase_order_id=?');
            $sum->execute([$id]);
            $total = (float) $sum->fetchColumn();
            db()->prepare('UPDATE purchase_orders SET total_amount=? WHERE id=?')->execute([$total, $id]);
            db()->commit();
            
            flash('success', 'Item added to PO.');
            redirect('purchase.php?id=' . $id);
        }

        if ($action === 'remove_item') {
            if ($order['status'] !== 'draft') throw new RuntimeException('Items can only be removed from drafts.');
            $itemId = (int) ($_POST['item_id'] ?? 0);
            db()->beginTransaction();
            db()->prepare('DELETE FROM purchase_order_items WHERE id=? AND purchase_order_id=?')->execute([$itemId, $id]);
            $sum = db()->prepare('SELECT COALESCE(SUM(line_total),0) FROM purchase_order_items WHERE purchase_order_id=?');
            $sum->execute([$id]);
            $total = (float) $sum->fetchColumn();
            db()->prepare('UPDATE purchase_orders SET total_amount=? WHERE id=?')->execute([$total, $id]);
            db()->commit();
            flash('success', 'Item removed.');
            redirect('purchase.php?id=' . $id);
        }

        if ($action === 'submit') {
            if ($order['status'] !== 'draft') throw new RuntimeException('Only drafts can be submitted.');
            $check = db()->prepare('SELECT COUNT(*) FROM purchase_order_items WHERE purchase_order_id=?');
            $check->execute([$id]);
            if ((int)$check->fetchColumn() === 0) throw new RuntimeException('Add at least one item before submitting.');
            
            db()->prepare('UPDATE purchase_orders SET status="submitted" WHERE id=?')->execute([$id]);
            flash('success', 'Purchase order submitted to supplier.');
            redirect('purchase.php?id=' . $id);
        }

        if ($action === 'cancel') {
            if (!in_array($order['status'], ['draft', 'submitted'], true)) throw new RuntimeException('This PO cannot be cancelled.');
            db()->prepare('UPDATE purchase_orders SET status="cancelled" WHERE id=?')->execute([$id]);
            flash('success', 'Purchase order cancelled.');
            redirect('purchase.php?id=' . $id);
        }

        if ($action === 'receive') {
            if (!in_array($order['status'], ['submitted', 'part_received'], true)) throw new RuntimeException('This PO is not open for receiving.');
            if (!$canReceive) throw new RuntimeException('You do not have permission to receive stock.');

            $itemId = (int) ($_POST['item_id'] ?? 0);
            $qtyRaw = (string) ($_POST['receive_qty'] ?? '');

            if (!is_numeric($qtyRaw) || (float) $qtyRaw <= 0) throw new RuntimeException('Enter a valid quantity to receive.');

            db()->beginTransaction();
            
            $itemStmt = db()->prepare('SELECT * FROM purchase_order_items WHERE id=? AND purchase_order_id=? FOR UPDATE');
            $itemStmt->execute([$itemId, $id]);
            $item = $itemStmt->fetch();
            if (!$item) throw new RuntimeException('Item not found.');

            $remaining = (float) $item['quantity_ordered'] - (float) $item['quantity_received'];
            $receiveQty = round((float) $qtyRaw, 2);
            
            if ($receiveQty > $remaining) {
                throw new RuntimeException('Cannot receive more than ordered. Remaining: ' . number_format($remaining, 2));
            }

            // 1. Update PO Item
            $newReceived = (float) $item['quantity_received'] + $receiveQty;
            db()->prepare('UPDATE purchase_order_items SET quantity_received=? WHERE id=?')->execute([$newReceived, $itemId]);

            // 2. Update Inventory
            $invStmt = db()->prepare('SELECT quantity FROM inventory WHERE product_id=? FOR UPDATE');
            $invStmt->execute([$item['product_id']]);
            $currentStock = (float) ($invStmt->fetchColumn() ?: 0);
            $newStock = $currentStock + $receiveQty;

            db()->prepare('UPDATE inventory SET quantity=?, updated_at=NOW() WHERE product_id=?')->execute([$newStock, $item['product_id']]);

            // 3. Log Stock Movement
            db()->prepare('INSERT INTO stock_movements (product_id, movement_type, quantity, previous_quantity, new_quantity, notes, created_by) VALUES (?, "IN", ?, ?, ?, ?, ?)')
                ->execute([$item['product_id'], $receiveQty, $currentStock, $newStock, 'Received from PO ' . $order['po_number'], current_user()['id']]);

            // 4. Update PO Status based on all items
            $allItems = db()->prepare('SELECT quantity_ordered, quantity_received FROM purchase_order_items WHERE purchase_order_id=?');
            $allItems->execute([$id]);
            $isFullyReceived = true;
            $isPartiallyReceived = false;
            
            foreach ($allItems->fetchAll() as $row) {
                if ((float)$row['quantity_received'] < (float)$row['quantity_ordered']) {
                    $isFullyReceived = false;
                }
                if ((float)$row['quantity_received'] > 0) {
                    $isPartiallyReceived = true;
                }
            }
            
            $newStatus = $isFullyReceived ? 'received' : ($isPartiallyReceived ? 'part_received' : 'submitted');
            db()->prepare('UPDATE purchase_orders SET status=? WHERE id=?')->execute([$newStatus, $id]);

            db()->commit();
            flash('success', 'Received ' . number_format($receiveQty, 2) . ' units into stock.');
            redirect('purchase.php?id=' . $id);
        }
    } catch (RuntimeException $exception) {
        if (db()->inTransaction()) db()->rollBack();
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        if (db()->inTransaction()) db()->rollBack();
        $error = 'The operation failed. Please try again.';
    }
}

$stmt = db()->prepare(
    'SELECT po.*, s.name AS supplier_name, s.contact_person, s.email AS supplier_email, s.phone AS supplier_phone, u.full_name AS created_by_name
     FROM purchase_orders po
     JOIN suppliers s ON s.id = po.supplier_id
     LEFT JOIN users u ON u.id = po.created_by
     WHERE po.id=?'
);
$stmt->execute([$id]);
$order = $stmt->fetch();
if (!$order) {
    flash('error', 'Purchase order not found.');
    redirect('purchases.php');
}

$itemStmt = db()->prepare('SELECT poi.*, p.name, p.sku FROM purchase_order_items poi JOIN products p ON p.id = poi.product_id WHERE poi.purchase_order_id=? ORDER BY poi.id');
$itemStmt->execute([$id]);
$items = $itemStmt->fetchAll();

$products = db()->query('SELECT id, sku, name, cost_price FROM products WHERE is_active=1 ORDER BY name')->fetchAll();

$statusPill = match ($order['status']) {
    'received'      => 'success',
    'part_received' => 'info',
    'cancelled'     => 'neutral',
    'submitted'     => 'warning',
    default         => 'warning',
};
require __DIR__ . '/includes/header.php';
?>
<?php if ($error !== ''): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<section class="order-banner">
    <div>
        <span class="eyebrow">PURCHASE ORDER</span>
        <h2><?= e($order['po_number']) ?> · <?= e($order['supplier_name']) ?></h2>
        <p>Ordered <?= e(format_day($order['order_date'])) ?> · Expected <?= e(format_day($order['expected_date'])) ?> · Created by <?= e($order['created_by_name'] ?? 'System') ?></p>
    </div>
    <div class="order-meta">
        <span class="pill pill-<?= $statusPill ?>"><?= e(ucwords(str_replace('_', ' ', $order['status']))) ?></span>
        <a class="text-link" href="purchases.php">← All POs</a>
        <button class="button button-secondary button-small" onclick="window.print()">🖨 Print / PDF</button>
    </div>
    <div class="print-header">
    <h1><?= e(get_setting('company_name', 'SME ERP')) ?></h1>
    <p><?= e(get_setting('company_email')) ?> | <?= e(get_setting('company_phone')) ?></p>
</div>
</section>

<section class="split-layout">
    <article class="panel form-panel">
        <?php if ($order['status'] === 'draft'): ?>
            <div class="panel-heading"><div><h2>Workflow</h2><p>Submit this PO to the supplier.</p></div></div>
            <form method="post" class="form-stack" style="gap:8px">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="submit">
                <button class="button button-primary button-full" type="submit">Submit PO</button>
            </form>
            <form method="post" data-confirm="Cancel this purchase order?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="cancel">
                <button class="button button-quiet button-full" type="submit" style="margin-top:8px">Cancel PO</button>
            </form>
            
            <hr style="border:0;border-top:1px solid var(--line);margin:20px 0">
            <div class="panel-heading"><div><h2>Add item</h2></div></div>
            <?php if (!$products): ?>
                <div class="empty-state compact"><strong>No products available</strong><p>Add products to the catalogue first.</p></div>
            <?php else: ?>
                <form method="post" class="form-stack">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_item">
                    <div><label for="product_id">Product</label>
                        <select id="product_id" name="product_id" required>
                            <option value="">Choose a product</option>
                            <?php foreach ($products as $p): ?>
                                <option value="<?= (int) $p['id'] ?>" data-cost="<?= e($p['cost_price']) ?>">
                                    <?= e($p['sku'] . ' — ' . $p['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field-grid">
                        <div><label for="quantity_ordered">Quantity</label><input id="quantity_ordered" name="quantity_ordered" type="number" min="0.01" step="0.01" required></div>
                        <div><label for="unit_cost">Unit cost (KSh)</label><input id="unit_cost" name="unit_cost" type="number" min="0" step="0.01" required></div>
                    </div>
                    <button class="button button-primary" type="submit">Add item</button>
                </form>
            <?php endif; ?>
        <?php elseif (in_array($order['status'], ['submitted', 'part_received'])): ?>
            <div class="panel-heading"><div><h2>Receiving</h2><p>Record received quantities below.</p></div></div>
            <div class="info-note" style="margin-top:0">
                <strong>Instructions</strong>
                <p>Enter the quantity physically received. This will immediately increase inventory stock and log a movement.</p>
            </div>
        <?php else: ?>
            <div class="panel-heading"><div><h2>Workflow</h2><p>This PO is <?= e(str_replace('_', ' ', $order['status'])) ?>.</p></div></div>
        <?php endif; ?>

        <?php if ($order['status'] === 'submitted'): ?>
            <form method="post" data-confirm="Cancel this PO? It cannot be cancelled once stock is received." style="margin-top:20px">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="cancel">
                <button class="button button-quiet button-small" type="submit">Cancel PO</button>
            </form>
        <?php endif; ?>
    </article>

    <article class="panel list-panel">
        <div class="panel-heading"><div><h2>Line items</h2><p>Order contents and receiving status</p></div></div>
        <div class="info-note" style="margin-top:0">
            <strong><?= e($order['supplier_name']) ?></strong>
            <p><?= e($order['contact_person'] ?: 'No contact person') ?> · <?= e($order['supplier_phone'] ?: 'No phone') ?></p>
        </div>
        
        <?php if (!$items): ?>
            <div class="empty-state compact"><strong>No items yet</strong><p>Add line items to build this order.</p></div>
        <?php else: ?>
            <div class="table-wrap"><table>
                <thead><tr><th>Product</th><th>Ordered</th><th>Received</th><th>Unit cost</th><th>Total</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($items as $item): 
                    $remaining = (float)$item['quantity_ordered'] - (float)$item['quantity_received'];
                ?>
                    <tr>
                        <td><strong><?= e($item['name']) ?></strong><small class="table-subtext"><?= e($item['sku']) ?></small></td>
                        <td><?= e(number_format((float) $item['quantity_ordered'], 2)) ?></td>
                        <td>
                            <span class="<?= $remaining <= 0 ? 'stock-ok' : 'stock-low' ?>">
                                <?= e(number_format((float) $item['quantity_received'], 2)) ?>
                            </span>
                        </td>
                        <td><?= e(money($item['unit_cost'])) ?></td>
                        <td><?= e(money($item['line_total'])) ?></td>
                        <td>
                            <?php if ($order['status'] === 'draft'): ?>
                                <form method="post" data-confirm="Remove this item?" class="inline-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="remove_item">
                                    <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                                    <button class="link-button danger-link" type="submit">Remove</button>
                                </form>
                            <?php elseif (in_array($order['status'], ['submitted', 'part_received']) && $remaining > 0 && $canReceive): ?>
                                <form method="post" class="inline-form" style="display:flex;gap:4px;align-items:center">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="receive">
                                    <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                                    <input type="number" name="receive_qty" min="0.01" max="<?= $remaining ?>" step="0.01" value="<?= $remaining ?>" style="width:70px;padding:4px;font-size:11px;border:1px solid #dce2eb;border-radius:4px" required>
                                    <button class="button button-primary button-small" type="submit">Receive</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <div class="totals-list">
                <div class="total-row"><span>Total Amount</span><span><?= e(money($order['total_amount'])) ?></span></div>
            </div>
        <?php endif; ?>
    </article>
</section>

<script>
(function () {
    var sel = document.getElementById('product_id');
    var cost = document.getElementById('unit_cost');
    if (!sel || !cost) return;
    sel.addEventListener('change', function () {
        var opt = this.options[this.selectedIndex];
        var p = opt.getAttribute('data-cost');
        if (p) cost.value = p;
    });
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>