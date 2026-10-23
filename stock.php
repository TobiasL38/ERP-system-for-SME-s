<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_roles(['admin', 'manager', 'inventory', 'procurement']);
$pageTitle = 'Stock movements';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $productId = (int) ($_POST['product_id'] ?? 0);
    $type = strtoupper(post_string('movement_type', 20));
    $amountRaw = (string) ($_POST['quantity'] ?? '');
    $notes = post_string('notes', 500);
    try {
        if ($productId < 1 || !in_array($type, ['IN', 'OUT', 'COUNT'], true)) throw new RuntimeException('Select a valid product and stock movement type.');
        if (!is_numeric($amountRaw) || (float) $amountRaw < 0) throw new RuntimeException('Quantity must be a valid non-negative number.');
        $amount = round((float) $amountRaw, 2);
        if (($type === 'IN' || $type === 'OUT') && $amount <= 0) throw new RuntimeException('Quantity must be greater than zero for stock-in or stock-out.');

        db()->beginTransaction();
        $stmt = db()->prepare('SELECT i.quantity, p.name FROM inventory i JOIN products p ON p.id=i.product_id WHERE p.id=? AND p.is_active=1 FOR UPDATE');
        $stmt->execute([$productId]);
        $row = $stmt->fetch();
        if (!$row) throw new RuntimeException('The selected product is unavailable.');
        $previous = (float) $row['quantity'];
        if ($type === 'IN') {
            $new = $previous + $amount;
            $delta = $amount;
        } elseif ($type === 'OUT') {
            if ($amount > $previous) throw new RuntimeException('Stock-out quantity exceeds current stock. Current stock: ' . number_format($previous, 2));
            $new = $previous - $amount;
            $delta = -$amount;
        } else {
            $new = $amount;
            $delta = $new - $previous;
        }
        db()->prepare('UPDATE inventory SET quantity=?, updated_at=NOW() WHERE product_id=?')->execute([$new, $productId]);
        db()->prepare('INSERT INTO stock_movements (product_id, movement_type, quantity, previous_quantity, new_quantity, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$productId, $type, $delta, $previous, $new, $notes ?: null, current_user()['id']]);
        db()->commit();
        flash('success', 'Stock movement recorded for ' . $row['name'] . '. New balance: ' . number_format($new, 2) . '.');
        redirect('stock.php');
    } catch (RuntimeException $exception) {
        if (db()->inTransaction()) db()->rollBack();
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        if (db()->inTransaction()) db()->rollBack();
        $error = 'The stock movement could not be saved. Check the database and try again.';
    }
}
$products = db()->query('SELECT p.id, p.sku, p.name, p.reorder_level, i.quantity FROM products p JOIN inventory i ON i.product_id=p.id WHERE p.is_active=1 ORDER BY p.name')->fetchAll();
$movements = db()->query('SELECT sm.*, p.name AS product_name, p.sku, u.full_name FROM stock_movements sm JOIN products p ON p.id=sm.product_id LEFT JOIN users u ON u.id=sm.created_by ORDER BY sm.id DESC LIMIT 100')->fetchAll();
require __DIR__ . '/includes/header.php';
?>
<?php if ($error !== ''): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<section class="split-layout stock-layout">
    <article class="panel form-panel">
        <div class="panel-heading"><div><h2>Record a movement</h2><p>Every change is saved to the stock audit trail.</p></div></div>
        <?php if (!$products): ?><div class="empty-state compact"><strong>No active products</strong><p>Add a product before recording stock.</p><a class="button button-primary" href="products.php">Add product</a></div><?php else: ?>
        <form method="post" class="form-stack">
            <?= csrf_field() ?>
            <div><label for="product_id">Product *</label><select id="product_id" name="product_id" required><option value="">Choose a product</option><?php foreach ($products as $product): ?><option value="<?= (int) $product['id'] ?>" <?= (int) ($_POST['product_id'] ?? 0) === (int) $product['id'] ? 'selected' : '' ?>><?= e($product['sku'] . ' — ' . $product['name'] . ' (stock: ' . number_format((float) $product['quantity'], 2) . ')') ?></option><?php endforeach; ?></select></div>
            <div><label for="movement_type">Movement type *</label><select id="movement_type" name="movement_type" required><option value="IN">Stock in — receiving or opening stock</option><option value="OUT" <?= ($_POST['movement_type'] ?? '') === 'OUT' ? 'selected' : '' ?>>Stock out — issue or write-off</option><option value="COUNT" <?= ($_POST['movement_type'] ?? '') === 'COUNT' ? 'selected' : '' ?>>Stock count — set actual counted balance</option></select><small class="field-help">For Stock count, enter the actual quantity physically counted.</small></div>
            <div><label for="quantity">Quantity *</label><input id="quantity" name="quantity" type="number" min="0" step="0.01" required value="<?= e($_POST['quantity'] ?? '') ?>" placeholder="0.00"></div>
            <div><label for="notes">Notes / reference</label><textarea id="notes" name="notes" rows="3" maxlength="500" placeholder="e.g. Opening balance, delivery note number"><?= e($_POST['notes'] ?? '') ?></textarea></div>
            <button class="button button-primary" type="submit">Save stock movement</button>
        </form><?php endif; ?>
        <div class="info-note"><strong>Audit note</strong><p>Stock updates are processed in a database transaction. Stock cannot go below zero through a stock-out movement.</p></div>
    </article>
    <article class="panel list-panel">
        <div class="panel-heading"><div><h2>Current stock levels</h2><p><?= count($products) ?> active product(s)</p></div></div>
        <div class="table-wrap"><table><thead><tr><th>Product</th><th>Available</th><th>Reorder level</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($products as $product): $low = (float) $product['quantity'] <= (float) $product['reorder_level']; ?><tr><td><strong><?= e($product['name']) ?></strong><small class="table-subtext"><?= e($product['sku']) ?></small></td><td><?= e(number_format((float) $product['quantity'], 2)) ?></td><td><?= e(number_format((float) $product['reorder_level'], 2)) ?></td><td><span class="pill pill-<?= $low ? 'warning' : 'success' ?>"><?= $low ? 'Low stock' : 'In stock' ?></span></td></tr><?php endforeach; ?>
        <?php if (!$products): ?><tr><td colspan="4" class="empty-cell">No products yet.</td></tr><?php endif; ?>
        </tbody></table></div>
    </article>
</section>
<section class="panel table-panel"><div class="panel-heading"><div><h2>Movement history</h2><p>Most recent 100 recorded movements</p></div></div>
    <div class="table-wrap"><table><thead><tr><th>Product</th><th>Type</th><th>Change</th><th>Previous</th><th>New balance</th><th>Recorded by</th><th>Date</th></tr></thead><tbody>
    <?php foreach ($movements as $movement): ?><tr><td><strong><?= e($movement['product_name']) ?></strong><small class="table-subtext"><?= e($movement['sku']) ?></small><?php if ($movement['notes']): ?><small class="table-subtext"><?= e($movement['notes']) ?></small><?php endif; ?></td><td><span class="pill pill-<?= $movement['movement_type'] === 'OUT' ? 'neutral' : ($movement['movement_type'] === 'IN' ? 'success' : 'info') ?>"><?= e($movement['movement_type']) ?></span></td><td><?= (float) $movement['quantity'] > 0 ? '+' : '' ?><?= e(number_format((float) $movement['quantity'], 2)) ?></td><td><?= e(number_format((float) $movement['previous_quantity'], 2)) ?></td><td><?= e(number_format((float) $movement['new_quantity'], 2)) ?></td><td><?= e($movement['full_name'] ?? 'System') ?></td><td><?= e(format_date($movement['created_at'])) ?></td></tr><?php endforeach; ?>
    <?php if (!$movements): ?><tr><td colspan="7" class="empty-cell">No stock movements have been recorded.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
