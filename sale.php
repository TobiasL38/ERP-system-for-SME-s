<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_roles(['admin', 'manager', 'sales']);

$id = (int) ($_GET['id'] ?? 0);
if ($id < 1) {
    flash('info', 'Select a sale order to view.');
    redirect('sales.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_string('action', 30);
    try {
        $stmt = db()->prepare('SELECT * FROM sales_orders WHERE id=?');
        $stmt->execute([$id]);
        $order = $stmt->fetch();
        if (!$order) throw new RuntimeException('Sale order not found.');

        if ($action === 'update_header') {
            if ($order['status'] !== 'draft') throw new RuntimeException('Only draft orders can be edited.');
            $vatRaw = (string) ($_POST['vat_rate'] ?? get_setting('vat_rate', '16.00'));            $notes  = post_string('notes', 500);
            if (!is_numeric($vatRaw) || (float) $vatRaw < 0 || (float) $vatRaw > 100) throw new RuntimeException('VAT rate must be between 0 and 100.');
            db()->beginTransaction();
            db()->prepare('UPDATE sales_orders SET vat_rate=?, notes=? WHERE id=?')->execute([(float) $vatRaw, $notes ?: null, $id]);
            recalc_order($id);
            db()->commit();
            flash('success', 'Order details updated.');
            redirect('sale.php?id=' . $id);
        }

        if ($action === 'add_item') {
            if ($order['status'] !== 'draft') throw new RuntimeException('Items can only be added while the order is a draft.');
            $productId = (int) ($_POST['product_id'] ?? 0);
            $qtyRaw    = (string) ($_POST['quantity'] ?? '');
            $priceRaw  = (string) ($_POST['unit_price'] ?? '');
            if ($productId < 1) throw new RuntimeException('Choose a product.');
            if (!is_numeric($qtyRaw) || (float) $qtyRaw <= 0) throw new RuntimeException('Quantity must be greater than zero.');
            if (!is_numeric($priceRaw) || (float) $priceRaw < 0) throw new RuntimeException('Unit price must be non-negative.');

            $pstmt = db()->prepare('SELECT id, name, is_active FROM products WHERE id=?');
            $pstmt->execute([$productId]);
            $product = $pstmt->fetch();
            if (!$product || !(int) $product['is_active']) throw new RuntimeException('Product unavailable.');

            $qty   = round((float) $qtyRaw, 2);
            $price = round((float) $priceRaw, 2);

            db()->beginTransaction();
            $check = db()->prepare('SELECT id, quantity FROM sales_order_items WHERE order_id=? AND product_id=?');
            $check->execute([$id, $productId]);
            $existing = $check->fetch();
            if ($existing) {
                $mergedQty = round((float) $existing['quantity'] + $qty, 2);
                $line      = round($mergedQty * $price, 2);
                db()->prepare('UPDATE sales_order_items SET quantity=?, unit_price=?, line_total=? WHERE id=?')
                    ->execute([$mergedQty, $price, $line, $existing['id']]);
            } else {
                $line = round($qty * $price, 2);
                db()->prepare('INSERT INTO sales_order_items (order_id, product_id, quantity, unit_price, line_total) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$id, $productId, $qty, $price, $line]);
            }
            recalc_order($id);
            db()->commit();
            flash('success', 'Item added.');
            redirect('sale.php?id=' . $id);
        }

        if ($action === 'remove_item') {
            if ($order['status'] !== 'draft') throw new RuntimeException('Items can only be removed while the order is a draft.');
            $itemId = (int) ($_POST['item_id'] ?? 0);
            db()->beginTransaction();
            db()->prepare('DELETE FROM sales_order_items WHERE id=? AND order_id=?')->execute([$itemId, $id]);
            recalc_order($id);
            db()->commit();
            flash('success', 'Item removed.');
            redirect('sale.php?id=' . $id);
        }

        if ($action === 'confirm') {
            if ($order['status'] !== 'draft') throw new RuntimeException('Only drafts can be confirmed.');
            $items = db()->prepare('SELECT soi.*, p.name FROM sales_order_items soi JOIN products p ON p.id = soi.product_id WHERE soi.order_id=?');
            $items->execute([$id]);
            $items = $items->fetchAll();
            if (!$items) throw new RuntimeException('Add at least one line item before confirming.');
            $inv = db()->prepare('SELECT quantity FROM inventory WHERE product_id=?');
            foreach ($items as $item) {
                $inv->execute([$item['product_id']]);
                $available = (float) ($inv->fetchColumn() ?: 0);
                if ($available < (float) $item['quantity']) {
                    throw new RuntimeException('Insufficient stock for ' . $item['name'] . '. Available: ' . number_format($available, 2) . ', required: ' . number_format((float) $item['quantity'], 2) . '.');
                }
            }
            db()->prepare('UPDATE sales_orders SET status="confirmed" WHERE id=?')->execute([$id]);
            flash('success', 'Order confirmed. Fulfil it to deduct stock.');
            redirect('sale.php?id=' . $id);
        }

        if ($action === 'fulfill') {
            if ($order['status'] !== 'confirmed') throw new RuntimeException('Order must be confirmed before fulfilment.');
            db()->beginTransaction();
            $items = db()->prepare('SELECT soi.*, p.name FROM sales_order_items soi JOIN products p ON p.id = soi.product_id WHERE soi.order_id=?');
            $items->execute([$id]);
            $items = $items->fetchAll();
            if (!$items) throw new RuntimeException('Order has no items.');

            $invStmt  = db()->prepare('SELECT quantity FROM inventory WHERE product_id=? FOR UPDATE');
            $update   = db()->prepare('UPDATE inventory SET quantity=?, updated_at=NOW() WHERE product_id=?');
            $movement = db()->prepare('INSERT INTO stock_movements (product_id, movement_type, quantity, previous_quantity, new_quantity, notes, created_by) VALUES (?, "OUT", ?, ?, ?, ?, ?)');

            foreach ($items as $item) {
                $invStmt->execute([$item['product_id']]);
                $available = (float) $invStmt->fetchColumn();
                if ($available < (float) $item['quantity']) {
                    throw new RuntimeException('Stock shortage for ' . $item['name'] . '. Available: ' . number_format($available, 2) . '.');
                }
                $new = $available - (float) $item['quantity'];
                $update->execute([$new, $item['product_id']]);
                $movement->execute([
                    $item['product_id'],
                    -(float) $item['quantity'],
                    $available,
                    $new,
                    'Sale ' . $order['order_number'],
                    current_user()['id'],
                ]);
            }
            db()->prepare('UPDATE sales_orders SET status="fulfilled" WHERE id=?')->execute([$id]);
            db()->commit();
            flash('success', 'Order fulfilled. Stock has been deducted.');
            redirect('sale.php?id=' . $id);
        }

        if ($action === 'cancel') {
            if (!in_array($order['status'], ['draft', 'confirmed'], true)) throw new RuntimeException('This order cannot be cancelled.');
            db()->prepare('UPDATE sales_orders SET status="cancelled" WHERE id=?')->execute([$id]);
            flash('success', 'Order cancelled.');
            redirect('sale.php?id=' . $id);
        }

        if ($action === 'generate_invoice') {
            if (!in_array($order['status'], ['confirmed', 'fulfilled'], true)) throw new RuntimeException('Only confirmed or fulfilled orders can be invoiced.');
            $check = db()->prepare('SELECT id FROM invoices WHERE order_id=?');
            $check->execute([$id]);
            if ($check->fetch()) throw new RuntimeException('An invoice already exists for this order.');
            $total = (float) $order['total_amount'];
            if ($total <= 0) throw new RuntimeException('Order total must be greater than zero.');
            $invoiceNumber = next_invoice_number();
            $dueDate = date('Y-m-d', strtotime($order['order_date'] . ' +30 days'));
            db()->prepare('INSERT INTO invoices (invoice_number, order_id, invoice_date, due_date, amount, status) VALUES (?, ?, ?, ?, ?, "unpaid")')
                ->execute([$invoiceNumber, $id, $order['order_date'], $dueDate, $total]);
            flash('success', 'Invoice ' . $invoiceNumber . ' generated.');
            redirect('invoice.php?id=' . (int) db()->lastInsertId());
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
    'SELECT so.*, c.name AS customer_name, c.email AS customer_email, c.phone AS customer_phone, c.address AS customer_address, u.full_name AS created_by_name
     FROM sales_orders so
     JOIN customers c ON c.id = so.customer_id
     LEFT JOIN users u ON u.id = so.created_by
     WHERE so.id=?'
);
$stmt->execute([$id]);
$order = $stmt->fetch();
if (!$order) {
    flash('error', 'Sale order not found.');
    redirect('sales.php');
}

$itemStmt = db()->prepare('SELECT soi.*, p.name, p.sku FROM sales_order_items soi JOIN products p ON p.id = soi.product_id WHERE soi.order_id=? ORDER BY soi.id');
$itemStmt->execute([$id]);
$items = $itemStmt->fetchAll();

$invStmt = db()->prepare('SELECT * FROM invoices WHERE order_id=? LIMIT 1');
$invStmt->execute([$id]);
$invoice = $invStmt->fetch();

$products = db()->query('SELECT p.id, p.sku, p.name, p.unit_price, COALESCE(i.quantity, 0) AS quantity FROM products p LEFT JOIN inventory i ON i.product_id = p.id WHERE p.is_active=1 ORDER BY p.name')->fetchAll();

$statusPill = match ($order['status']) {
    'fulfilled' => 'success',
    'confirmed' => 'info',
    'cancelled' => 'neutral',
    default     => 'warning',
};
require __DIR__ . '/includes/header.php';
?>
<?php if ($error !== ''): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<section class="order-banner">
    <div>
        <span class="eyebrow">SALE ORDER</span>
        <h2><?= e($order['order_number']) ?> · <?= e($order['customer_name']) ?></h2>
        <p>Ordered <?= e(format_day($order['order_date'])) ?> · Created by <?= e($order['created_by_name'] ?? 'System') ?></p>
    </div>
    <div class="order-meta">
        <span class="pill pill-<?= $statusPill ?>"><?= e(ucfirst($order['status'])) ?></span>
        <a class="text-link" href="sales.php">← All orders</a>
    </div>
</section>

<section class="split-layout">
    <article class="panel form-panel">
        <?php if ($order['status'] === 'draft'): ?>
            <div class="panel-heading"><div><h2>Workflow</h2><p>Confirm to validate stock availability.</p></div></div>
            <form method="post" class="form-stack" style="gap:8px">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="confirm">
                <button class="button button-primary button-full" type="submit">Confirm order</button>
            </form>
            <form method="post" data-confirm="Cancel this order?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="cancel">
                <button class="button button-quiet button-full" type="submit" style="margin-top:8px">Cancel order</button>
            </form>
        <?php elseif ($order['status'] === 'confirmed'): ?>
            <div class="panel-heading"><div><h2>Workflow</h2><p>Stock was validated on confirm.</p></div></div>
            <form method="post" class="form-stack" style="gap:8px">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="fulfill">
                <button class="button button-primary button-full" type="submit">Fulfil (deduct stock)</button>
            </form>
            <form method="post" data-confirm="Cancel this order?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="cancel">
                <button class="button button-quiet button-full" type="submit" style="margin-top:8px">Cancel order</button>
            </form>
        <?php else: ?>
            <div class="panel-heading"><div><h2>Workflow</h2><p>This order is <?= e($order['status']) ?>.</p></div></div>
        <?php endif; ?>

        <?php if (in_array($order['status'], ['confirmed', 'fulfilled'], true) && !$invoice): ?>
            <form method="post" style="margin-top:12px">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="generate_invoice">
                <button class="button button-secondary button-full" type="submit">Generate invoice</button>
            </form>
        <?php endif; ?>

        <?php if ($invoice): ?>
            <div class="info-note">
                <strong>Invoice <?= e($invoice['invoice_number']) ?></strong>
                <p>Status: <?= e(ucfirst(str_replace('_', ' ', $invoice['status']))) ?> · Amount: <?= e(money($invoice['amount'])) ?></p>
                <a class="button button-secondary button-small" href="invoice.php?id=<?= (int) $invoice['id'] ?>">Open invoice</a>
            </div>
        <?php endif; ?>

        <?php if ($order['status'] === 'draft'): ?>
            <hr style="border:0;border-top:1px solid var(--line);margin:20px 0">
            <div class="panel-heading"><div><h2>Order settings</h2></div></div>
            <form method="post" class="form-stack">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_header">
                <div><label for="vat_rate">VAT rate (%)</label><input id="vat_rate" name="vat_rate" type="number" min="0" max="100" step="0.01" value="<?= e($order['vat_rate']) ?>"></div>
                <div><label for="notes">Notes</label><textarea id="notes" name="notes" rows="2" maxlength="500"><?= e($order['notes'] ?? '') ?></textarea></div>
                <button class="button button-secondary" type="submit">Save details</button>
            </form>

            <hr style="border:0;border-top:1px solid var(--line);margin:20px 0">
            <div class="panel-heading"><div><h2>Add item</h2></div></div>
            <?php if (!$products): ?>
                <div class="empty-state compact"><strong>No products available</strong><p>Add products before raising a sale.</p></div>
            <?php else: ?>
                <form method="post" class="form-stack">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_item">
                    <div><label for="product_id">Product</label>
                        <select id="product_id" name="product_id" required>
                            <option value="">Choose a product</option>
                            <?php foreach ($products as $p): ?>
                                <option value="<?= (int) $p['id'] ?>" data-price="<?= e($p['unit_price']) ?>">
                                    <?= e($p['sku'] . ' — ' . $p['name'] . ' (stock: ' . number_format((float) $p['quantity'], 2) . ')') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field-grid">
                        <div><label for="quantity">Quantity</label><input id="quantity" name="quantity" type="number" min="0.01" step="0.01" required></div>
                        <div><label for="unit_price">Unit price (KSh)</label><input id="unit_price" name="unit_price" type="number" min="0" step="0.01" required></div>
                    </div>
                    <button class="button button-primary" type="submit">Add item</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </article>

    <article class="panel list-panel">
        <div class="panel-heading"><div><h2>Line items</h2><p>Order contents and totals</p></div></div>
        <div class="info-note" style="margin-top:0">
            <strong><?= e($order['customer_name']) ?></strong>
            <p><?= e($order['customer_email'] ?: 'No email recorded') ?> · <?= e($order['customer_phone'] ?: 'No phone recorded') ?></p>
            <?php if ($order['customer_address']): ?><p><?= e($order['customer_address']) ?></p><?php endif; ?>
        </div>
        <?php if (!$items): ?>
            <div class="empty-state compact"><strong>No items yet</strong><p>Add line items to build this order.</p></div>
        <?php else: ?>
            <div class="table-wrap"><table>
                <thead><tr><th>Product</th><th>Qty</th><th>Unit price</th><th>Line total</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><strong><?= e($item['name']) ?></strong><small class="table-subtext"><?= e($item['sku']) ?></small></td>
                        <td><?= e(number_format((float) $item['quantity'], 2)) ?></td>
                        <td><?= e(money($item['unit_price'])) ?></td>
                        <td><?= e(money($item['line_total'])) ?></td>
                        <td>
                            <?php if ($order['status'] === 'draft'): ?>
                                <form method="post" data-confirm="Remove this item?" class="inline-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="remove_item">
                                    <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                                    <button class="link-button danger-link" type="submit">Remove</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <div class="totals-list">
                <div><span>Subtotal</span><span><?= e(money($order['subtotal'])) ?></span></div>
                <div><span>VAT (<?= e(number_format((float) $order['vat_rate'], 2)) ?>%)</span><span><?= e(money($order['tax_amount'])) ?></span></div>
                <div class="total-row"><span>Total</span><span><?= e(money($order['total_amount'])) ?></span></div>
            </div>
        <?php endif; ?>
    </article>
</section>

<script>
(function () {
    var sel = document.getElementById('product_id');
    var price = document.getElementById('unit_price');
    if (!sel || !price) return;
    sel.addEventListener('change', function () {
        var opt = this.options[this.selectedIndex];
        var p = opt.getAttribute('data-price');
        if (p) price.value = p;
    });
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>